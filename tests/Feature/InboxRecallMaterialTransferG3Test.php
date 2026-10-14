<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\InboxRecallMaterialTransferGuard;
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class InboxRecallMaterialTransferG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake(config('filesystems.default'));
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'test-model',
        ]);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-inbox-recall-copy-g3', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text',
                'text' => json_encode(['candidates' => [[
                    'prompt' => 'abandon', 'answer' => '放棄する',
                    'note' => null, 'tags' => ['AP'],
                    'source_excerpt' => 'abandon', 'confidence' => 90,
                ]]], JSON_UNESCAPED_UNICODE),
            ]]]],
            'usage' => ['input_tokens' => 75, 'output_tokens' => 45, 'total_tokens' => 120],
        ], 200)]);
    }

    public function test_image_copy_seals_exact_bytes_and_processes_only_after_recall_succeeds(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $originalBytes = Storage::get($inbox->storage_path);

        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $source = StudyRecallSource::firstOrFail();
        $this->assertSame('ready', $source->status);
        $this->assertNotSame($inbox->storage_path, $source->storage_path);
        $this->assertSame($originalBytes, Storage::get($source->storage_path));
        $this->assertSame($originalBytes, Storage::get($inbox->storage_path));
        $this->assertSame('processed', $inbox->fresh()->status);
        $this->assertSame('recall_material', data_get($inbox->fresh()->metadata,
            'routing_confirmed.destination'));
        $this->assertSame(1, StudyRecallCandidate::count());
        $this->assertSame(1, data_get(StudyRecallCandidate::firstOrFail()->review_provenance,
            'version'));
        Http::assertSentCount(1);
    }

    public function test_text_inbox_still_routes_into_recall_with_no_copy(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario('text');
        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasNoErrors();
        $source = StudyRecallSource::firstOrFail();
        $this->assertSame('ready', $source->status);
        $this->assertSame('abandon は放棄する', $source->source_text);
        $this->assertNull($source->storage_path);
        $this->assertSame('processed', $inbox->fresh()->status);
    }

    public function test_missing_source_file_fails_before_any_recall_row_or_provider_call(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        Storage::delete($inbox->storage_path);

        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasErrors('destination');
        $this->assertDatabaseCount('study_recall_sources', 0);
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertSame('new', $inbox->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_copied_file_digest_cannot_authorize_middle_version_of_source_a_b_a(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $guard = app(InboxRecallMaterialTransferGuard::class);
        $originalBytes = Storage::get($inbox->storage_path);
        $signed = $guard->signature($inbox);

        Storage::put($inbox->storage_path, 'the other file version B');
        $actualCopiedHash = hash('sha256', Storage::get($inbox->storage_path));
        Storage::put($inbox->storage_path, $originalBytes);

        $this->assertNotSame($signed, $guard->signature($inbox, $actualCopiedHash));
        $this->assertSame($signed, $guard->signature($inbox));
    }

    public function test_inbox_edit_during_provider_keeps_new_state_and_never_marks_stale_routing_processed(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $this->afterGateway(fn () => $inbox->update(['content' => 'revised original note']));

        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasErrors('destination');
        $this->assertSame('new', $inbox->fresh()->status);
        $this->assertSame('revised original note', $inbox->fresh()->content);
        $this->assertNull(data_get($inbox->fresh()->metadata, 'routing_confirmed'));
        $this->assertSame('ready', StudyRecallSource::firstOrFail()->status);
        $this->assertSame('pending', StudyRecallCandidate::firstOrFail()->status);
    }

    public function test_owner_transfer_and_return_during_provider_rejects_old_inbox_copy_epoch(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(function () use ($plan, $actor, $other) {
            $plan->update(['user_id' => $other->id]);
            $plan->update(['user_id' => $actor->id]);
        });

        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasErrors('destination');
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertSame('new', $inbox->fresh()->status);
    }

    public function test_editor_downgrade_and_return_during_provider_rejects_copied_material(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $owner = User::factory()->create();
        $plan->update(['user_id' => $owner->id, 'is_collaborative' => true]);
        $member = PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $actor->id,
            'role' => PlanMember::ROLE_EDITOR, 'joined_at' => now(),
        ]);
        $this->afterGateway(function () use ($member) {
            $member->update(['role' => PlanMember::ROLE_VIEWER]);
            $member->update(['role' => PlanMember::ROLE_EDITOR]);
        });

        $this->routeRecall($actor, $plan, $task, $inbox)
            ->assertSessionHasErrors('destination');
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertSame('new', $inbox->fresh()->status);
    }

    public function test_retargeted_inbox_actor_cannot_copy_someone_elses_file(): void
    {
        [$actor, $plan, $task, $inbox] = $this->scenario();
        $other = User::factory()->create();
        $inbox->update(['user_id' => $other->id]);
        $this->actingAs($actor)
            ->post(route('inbox.route', $inbox), [
                'destination' => 'recall_material',
                'plan_id' => $plan->id, 'task_id' => $task->id,
            ])->assertForbidden();
        $this->assertDatabaseCount('study_recall_sources', 0);
        Http::assertNothingSent();
    }

    public function test_successful_shared_editor_can_route_own_inbox_into_owner_plan(): void
    {
        [$editor, $plan, $task, $inbox] = $this->scenario();
        $owner = User::factory()->create();
        $plan->update(['user_id' => $owner->id, 'is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR, 'joined_at' => now(),
        ]);

        $this->routeRecall($editor, $plan, $task, $inbox)
            ->assertSessionHasNoErrors();

        $this->assertSame('processed', $inbox->fresh()->status);
        $this->assertSame('ready', StudyRecallSource::firstOrFail()->status);
    }

    private function scenario(string $type = 'image'): array
    {
        $actor = User::factory()->create();
        UserProductGrant::query()->create([
            'user_id' => $actor->id, 'product_key' => ProductKey::PremiumCore,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $actor->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Inbox Recall G3', 'category' => '資格学習',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => '教材を確認',
            'status' => 'todo', 'estimated_minutes' => 30, 'remaining_minutes' => 30,
        ]);
        $path = $type === 'image'
            ? 'inbox/'.$actor->id.'/source-image.png' : null;
        if ($path !== null) {
            Storage::put($path, 'original uploaded image A');
        }
        $inbox = InboxItem::query()->create([
            'user_id' => $actor->id, 'plan_id' => $plan->id,
            'source_type' => $type, 'status' => 'new',
            'storage_path' => $path,
            'mime_type' => $type === 'image' ? 'image/png' : null,
            'original_name' => $type === 'image' ? 'source-image.png' : null,
            'content' => $type === 'text' ? 'abandon は放棄する' : '試験範囲画像',
            'metadata' => ['capture_surface' => 'inbox'],
        ]);

        return [$actor, $plan, $task, $inbox];
    }

    private function routeRecall(User $actor, Plan $plan, Task $task, InboxItem $inbox)
    {
        return $this->actingAs($actor)->post(
            route('inbox.route', $inbox), [
                'destination' => 'recall_material',
                'plan_id' => $plan->id, 'task_id' => $task->id,
            ],
        )->assertRedirect();
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
}
