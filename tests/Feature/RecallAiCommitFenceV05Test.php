<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\NativeAiGateway;
use App\Services\StudyRecallBatchCandidateExtractionService;
use App\Services\StudyRecallCandidateExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class RecallAiCommitFenceV05Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'test-model',
        ]);
    }

    public function test_single_source_grant_expiry_after_provider_blocks_candidates_and_retains_retry(): void
    {
        [$user, $plan, $task, $grant] = $this->scenario();
        $source = $this->source($user, $plan, $task);
        $this->fakeResponse(false);
        $this->afterGateway(fn () => $grant->update(['expires_at' => now()->subMinute()]));

        $this->denied('native_ai_purpose_not_entitled', fn () =>
            app(StudyRecallCandidateExtractionService::class)->extract($source, $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
        $this->assertSame('failed', $source->fresh()->status);
        $this->assertSame(NativeAiRun::firstOrFail()->id, $source->fresh()->native_ai_run_id);
    }

    public function test_single_source_text_change_after_provider_rejects_stale_model_answer(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $source = $this->source($user, $plan, $task);
        $this->fakeResponse(false);
        $this->afterGateway(fn () => $source->update(['source_text' => '教材は訂正されました']));

        $this->denied('native_ai_source_changed', fn () =>
            app(StudyRecallCandidateExtractionService::class)->extract($source, $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
        $this->assertSame('教材は訂正されました', $source->fresh()->source_text);
        $this->assertSame('failed', $source->fresh()->status);
    }

    public function test_single_source_owner_transfer_after_provider_does_not_commit_foreign_candidates(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $source = $this->source($user, $plan, $task);
        $newOwner = User::factory()->create();
        $this->fakeResponse(false);
        $this->afterGateway(fn () => $plan->update(['user_id' => $newOwner->id]));

        $this->denied('native_ai_target_changed', fn () =>
            app(StudyRecallCandidateExtractionService::class)->extract($source, $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
    }

    public function test_single_source_deleted_after_provider_does_not_recreate_source_or_candidates(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $source = $this->source($user, $plan, $task);
        $this->fakeResponse(false);
        $this->afterGateway(fn () => $source->delete());

        $this->denied('native_ai_source_changed', fn () =>
            app(StudyRecallCandidateExtractionService::class)->extract($source, $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
        $this->assertDatabaseCount('study_recall_sources', 0);
    }

    public function test_batch_one_changed_file_after_provider_rejects_all_candidates_atomically(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $a = $this->source($user, $plan, $task, 'a.png');
        $b = $this->source($user, $plan, $task, 'b.png');
        $this->fakeResponse(true);
        $this->afterGateway(fn () => Storage::put((string) $b->storage_path, 'different image data'));

        $this->denied('native_ai_source_changed', fn () =>
            app(StudyRecallBatchCandidateExtractionService::class)->extract(collect([$a, $b]), $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
        $this->assertSame('failed', $a->fresh()->status);
        $this->assertSame('failed', $b->fresh()->status);
    }

    public function test_batch_task_move_after_provider_does_not_persist_any_candidate(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $a = $this->source($user, $plan, $task, 'a.png');
        $b = $this->source($user, $plan, $task, 'b.png');
        $otherPlan = $this->plan($user);
        $this->fakeResponse(true);
        $this->afterGateway(fn () => $task->update(['plan_id' => $otherPlan->id]));

        $this->denied('native_ai_target_changed', fn () =>
            app(StudyRecallBatchCandidateExtractionService::class)->extract(collect([$a, $b]), $plan, $task, $user->id)
        );

        $this->assertNoCandidates();
    }

    public function test_unchanged_single_and_batch_still_create_reviewable_candidates(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $single = $this->source($user, $plan, $task);
        $this->fakeResponse(false);

        $result = app(StudyRecallCandidateExtractionService::class)
            ->extract($single, $plan, $task, $user->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame('ready', $single->fresh()->status);
        $this->assertSame('pending', StudyRecallCandidate::firstOrFail()->status);

        $first = $this->source($user, $plan, $task, 'page1.png');
        $second = $this->source($user, $plan, $task, 'page2.png');
        $this->fakeResponse(true);

        $batch = app(StudyRecallBatchCandidateExtractionService::class)
            ->extract(collect([$first, $second]), $plan, $task, $user->id);

        $this->assertSame(2, $batch['created']);
        $this->assertSame('ready', $first->fresh()->status);
        $this->assertSame('ready', $second->fresh()->status);
        $this->assertDatabaseCount('study_recall_candidates', 3);
        $this->assertDatabaseCount('study_recall_items', 0);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Recall Task',
            'status' => 'todo',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
        ]);
        $grant = UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);

        return [$user, $plan, $task, $grant];
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 Recall',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function source(User $user, Plan $plan, Task $task, ?string $name = null): StudyRecallSource
    {
        $path = $name === null ? null : 'study-recall-sources/'.$plan->id.'/'.$task->id.'/'.$name;
        if ($path !== null) {
            Storage::put($path, 'original image data '.$name);
        }

        return StudyRecallSource::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source_type' => $name === null ? 'text' : 'image',
            'source_text' => $name === null ? 'abandon: 放棄する' : null,
            'storage_path' => $path,
            'mime_type' => $name === null ? null : 'image/png',
            'original_name' => $name,
            'status' => 'pending',
        ]);
    }

    private function fakeResponse(bool $batch): void
    {
        $candidates = $batch
            ? [$this->candidate('page-a', 0), $this->candidate('page-b', 1)]
            : [$this->candidate('single-card')];

        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-recall-fence',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ], 200)]);
    }

    private function candidate(string $term, ?int $sourceIndex = null): array
    {
        $candidate = [
            'prompt' => $term,
            'answer' => $term.' answer',
            'note' => null,
            'tags' => [],
            'source_excerpt' => $term,
            'confidence' => 90,
        ];
        if ($sourceIndex !== null) {
            $candidate['source_index'] = $sourceIndex;
        }

        return $candidate;
    }

    private function afterGateway(callable $callback): void
    {
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $callback) {
                $result = $real->generateStructured(...$args);
                $callback();

                return $result;
            });
        app()->instance(NativeAiGateway::class, $mock);
    }

    private function denied(string $code, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Revoked or changed Recall context must not persist AI candidates.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertNotNull($exception->runId);
        }
    }

    private function assertNoCandidates(): void
    {
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertDatabaseCount('study_recall_items', 0);
        $this->assertSame('succeeded', NativeAiRun::firstOrFail()->status);
        Http::assertSentCount(1);
    }
}
