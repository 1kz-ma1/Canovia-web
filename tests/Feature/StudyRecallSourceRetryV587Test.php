<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyRecallSourceRetryV587Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' =>
                'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
        ]);

        Storage::fake('local');
    }

    public function test_failed_text_source_retries_with_same_source_row(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $source = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'abandon は「放棄する」という意味。',
            'status' => 'failed',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody(),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasNoErrors();

        $source->refresh();

        $this->assertDatabaseCount('study_recall_sources', 1);
        $this->assertSame('ready', $source->status);
        $this->assertSame(1, $source->candidate_count);
        $this->assertNotNull($source->native_ai_run_id);
        $this->assertDatabaseHas('study_recall_candidates', [
            'study_recall_source_id' => $source->id,
            'task_id' => $task->id,
            'status' => 'pending',
            'prompt' => 'abandon',
            'answer' => '放棄する',
        ]);
    }

    public function test_failed_pdf_source_reuses_saved_private_file_bytes(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $path = 'study-recall-sources/'
            .$plan->id.'/'.$task->id.'/saved.pdf';

        Storage::disk('local')->put(
            $path,
            "%PDF-1.4\nretry-source\n%%EOF",
        );

        $source = $this->source($plan, $task, [
            'source_type' => 'pdf',
            'original_name' => 'saved.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => $path,
            'source_text' => null,
            'status' => 'failed',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody(),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            $content = data_get(
                $request->data(),
                'input.0.content',
                [],
            );
            $file = collect($content)
                ->firstWhere('type', 'input_file');

            return is_array($file)
                && ($file['filename'] ?? null) === 'saved.pdf'
                && str_starts_with(
                    (string) ($file['file_data'] ?? ''),
                    'data:application/pdf;base64,',
                );
        });

        $this->assertDatabaseCount('study_recall_sources', 1);
        $this->assertSame('ready', $source->fresh()->status);
    }

    public function test_retry_does_not_duplicate_existing_candidate_content(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $source = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'abandon は「放棄する」という意味。',
            'status' => 'failed',
        ]);

        StudyRecallCandidate::query()->create([
            'study_recall_source_id' => $source->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'prompt' => 'abandon',
            'answer' => '放棄する',
            'tags' => ['TOEIC'],
            'source_excerpt' => 'abandon: 放棄する',
            'confidence' => 95,
            'status' => 'pending',
            'fingerprint' => hash(
                'sha256',
                'abandon|放棄する',
            ),
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody(),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_recall_candidates', 1);
        $this->assertSame(0, $source->fresh()->candidate_count);
    }

    public function test_non_failed_source_retry_is_blocked_before_provider_call(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $source = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'saved text',
            'status' => 'ready',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->from(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasErrors('recall_source');

        Http::assertNothingSent();
    }

    public function test_missing_saved_file_retry_is_blocked_without_provider_call(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $source = $this->source($plan, $task, [
            'source_type' => 'pdf',
            'original_name' => 'missing.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'study-recall-sources/missing.pdf',
            'source_text' => null,
            'status' => 'failed',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->from(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasErrors('recall_source');

        Http::assertNothingSent();
        $this->assertSame('failed', $source->fresh()->status);
    }

    public function test_source_from_another_task_cannot_be_retried(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $otherTask = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '別の暗記Task',
            'description' => '別範囲',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        $source = $this->source($plan, $otherTask, [
            'source_type' => 'text',
            'source_text' => 'foreign source',
            'status' => 'failed',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_free_user_cannot_retry_failed_source(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $source = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'saved text',
            'status' => 'failed',
        ]);

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $source],
            ))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame('failed', $source->fresh()->status);
    }

    public function test_retry_ui_is_shown_only_for_retryable_failed_source(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $failed = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'failed but saved',
            'status' => 'failed',
        ]);
        $ready = $this->source($plan, $task, [
            'source_type' => 'text',
            'source_text' => 'already ready',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('抽出失敗')
            ->assertSee('再抽出');

        $response->assertSee(
            route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $failed],
            ),
            false,
        );
        $response->assertDontSee(
            route(
                'plans.tasks.study_recall.sources.retry',
                [$plan, $task, $ready],
            ),
            false,
        );
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function source(
        Plan $plan,
        Task $task,
        array $overrides = [],
    ): StudyRecallSource {
        return StudyRecallSource::query()->create(array_merge([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $plan->user_id,
            'source_type' => 'text',
            'source_text' => 'saved source',
            'status' => 'failed',
            'candidate_count' => 0,
        ], $overrides));
    }

    private function responseBody(): array
    {
        return [
            'id' => 'resp_recall_retry',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'candidates' => [[
                            'prompt' => 'abandon',
                            'answer' => '放棄する',
                            'note' => '動詞',
                            'tags' => ['TOEIC'],
                            'source_excerpt' => 'abandon: 放棄する',
                            'confidence' => 95,
                        ]],
                    ], JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ];
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'TOEIC語彙',
            'description' => '語彙をRecallで定着させる',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '英単語を暗記する',
            'description' => '頻出語彙を覚える',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
