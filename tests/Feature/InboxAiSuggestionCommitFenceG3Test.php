<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\InboxItem;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class InboxAiSuggestionCommitFenceG3Test extends TestCase
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
            'id' => 'resp-inbox-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'destination' => 'task_evidence',
                        'reason' => '実際の進捗を記録するため',
                        'confidence' => 90,
                        'suggested_plan_title' => null,
                        'suggested_task_title' => null,
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120, 'output_tokens' => 50, 'total_tokens' => 170,
            ],
        ], 200)]);
    }

    public function test_unchanged_owned_inbox_can_persist_candidate_without_confirming_task(): void
    {
        [$user, $item] = $this->scenario();
        $this->suggest($user, $item)->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertSame('task_evidence', data_get($item->metadata, 'routing_suggestion.destination'));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(1);
    }

    public function test_chat_capture_uses_the_same_guard_and_keeps_new_inbox_on_failure(): void
    {
        $user = User::factory()->create();
        $this->grant($user);
        $this->afterGateway(fn () => InboxItem::query()->firstOrFail()->update([
            'content' => 'ユーザーが訂正した内容',
        ]));

        $this->actingAs($user)->post(route('inbox.store'), [
            'content' => '古い内容',
            'intake_mode' => 'chat',
        ])->assertRedirect();

        $item = InboxItem::query()->firstOrFail();
        $this->assertSame('new', $item->status);
        $this->assertSame('ユーザーが訂正した内容', $item->content);
        $this->assertNull(data_get($item->metadata, 'routing_suggestion'));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_post_provider_note_correction_withholds_stale_candidate(): void
    {
        [$user, $item] = $this->scenario();
        $this->afterGateway(fn () => $item->update(['content' => '内容を訂正しました']));

        $this->suggest($user, $item);
        $this->assertWithheld($item);
        $this->assertSame('内容を訂正しました', $item->fresh()->content);
    }

    public function test_unchanged_image_input_is_bound_and_suggestion_is_persisted(): void
    {
        [$user, $item] = $this->scenario('image');
        $this->suggest($user, $item)->assertSessionHasNoErrors();

        $this->assertSame('review', $item->fresh()->status);
        $this->assertSame(
            'task_evidence',
            data_get($item->fresh()->metadata, 'routing_suggestion.destination'),
        );
        Http::assertSentCount(1);
    }

    public function test_second_storage_read_changed_before_ai_provider_is_denied_without_submission(): void
    {
        [$user, $item] = $this->scenario('image');

        // First file read signs original A; second produces B as the EXACT
        // proposed provider payload. Old code submitted B and could later
        // accept restored A. The new pre-provider seal must stop here.
        $original = Storage::getFacadeRoot();
        $changed = Mockery::mock();
        $changed->shouldReceive('exists')->atLeast()->once()->andReturn(true);
        $changed->shouldReceive('get')->twice()
            ->with($item->storage_path)
            ->andReturn('original-image-bytes', 'replaced-image-bytes');
        Storage::swap($changed);
        try {
            $this->suggest($user, $item)->assertSessionHas('status');
        } finally {
            Storage::swap($original);
        }

        $this->assertSame('new', $item->fresh()->status);
        $this->assertNull(data_get($item->fresh()->metadata, 'routing_suggestion'));
        $this->assertDatabaseCount('native_ai_runs', 0);
        Http::assertSentCount(0);
    }

    public function test_file_disappearing_between_signature_and_payload_is_not_sent_as_text(): void
    {
        [$user, $item] = $this->scenario('image');
        $original = Storage::getFacadeRoot();
        $disappeared = Mockery::mock();
        $disappeared->shouldReceive('exists')->twice()
            ->with($item->storage_path)->andReturn(true, false);
        $disappeared->shouldReceive('get')->once()
            ->with($item->storage_path)->andReturn('original-image-bytes');
        Storage::swap($disappeared);
        try {
            $this->suggest($user, $item)->assertSessionHas('status');
        } finally {
            Storage::swap($original);
        }

        $this->assertSame('new', $item->fresh()->status);
        $this->assertDatabaseCount('native_ai_runs', 0);
        Http::assertSentCount(0);
    }

    public function test_inbox_content_correction_before_provider_blocks_submission(): void
    {
        [$user, $item] = $this->scenario('image');
        $this->mutateOnSecondFileRead($user, $item, function () use ($item): void {
            $item->update(['content' => 'corrected before provider']);
        });
        $this->assertNoPreProviderSubmission($item);
        $this->assertSame('corrected before provider', $item->fresh()->content);
    }

    public function test_inbox_owner_transfer_before_provider_blocks_submission(): void
    {
        [$user, $item] = $this->scenario('image');
        $other = User::factory()->create();
        $this->mutateOnSecondFileRead($user, $item, function () use ($item, $other): void {
            $item->update(['user_id' => $other->id]);
        });
        $this->assertNoPreProviderSubmission($item);
        $this->assertSame($other->id, $item->fresh()->user_id);
    }

    public function test_shared_editor_downgrade_before_provider_blocks_private_file_submission(): void
    {
        [$editor, $item, $plan, $membership] = $this->sharedEditorScenario('image');
        $this->mutateOnSecondFileRead($editor, $item, function () use ($membership): void {
            $membership->update(['role' => PlanMember::ROLE_VIEWER]);
        });
        $this->assertNoPreProviderSubmission($item);
        $this->assertSame(PlanMember::ROLE_VIEWER, $membership->fresh()->role);
    }

    public function test_entitlement_expiration_before_provider_blocks_private_file_submission(): void
    {
        [$user, $item] = $this->scenario('image');
        $this->mutateOnSecondFileRead($user, $item, function () use ($user): void {
            UserProductGrant::query()->where('user_id', $user->id)
                ->update(['expires_at' => now()->subMinute()]);
        });
        $this->assertNoPreProviderSubmission($item);
    }

    public function test_post_provider_file_change_withholds_stale_candidate(): void
    {
        [$user, $item] = $this->scenario('image');
        $this->afterGateway(fn () => Storage::put($item->storage_path, 'replacement-image-bytes'));

        $this->suggest($user, $item);
        $this->assertWithheld($item);
        $this->assertSame('replacement-image-bytes', Storage::get($item->storage_path));
    }

    public function test_source_deletion_after_provider_cannot_resurrect_suggestion(): void
    {
        [$user, $item] = $this->scenario();
        $this->afterGateway(fn () => $item->delete());

        $this->suggest($user, $item);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
    }

    public function test_human_routing_during_provider_is_not_reopened_by_ai(): void
    {
        [$user, $item] = $this->scenario();
        $this->afterGateway(fn () => $item->update([
            'status' => 'processed',
            'metadata' => ['routing_confirmed' => ['destination' => 'keep_inbox']],
            'processed_at' => now(),
        ]));

        $this->suggest($user, $item);
        $this->assertSame('processed', $item->fresh()->status);
        $this->assertNull(data_get($item->fresh()->metadata, 'routing_suggestion'));
        $this->assertSame('keep_inbox', data_get($item->fresh()->metadata, 'routing_confirmed.destination'));
    }

    public function test_item_transfer_after_provider_rejects_old_actor_output(): void
    {
        [$user, $item] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(fn () => $item->update(['user_id' => $other->id]));

        $this->suggest($user, $item);
        $this->assertWithheld($item);
        $this->assertSame($other->id, $item->fresh()->user_id);
    }

    public function test_plan_transfer_after_provider_rejects_old_actor_output(): void
    {
        [$user, $item] = $this->scenario();
        $plan = $this->plan($user);
        $item->update(['plan_id' => $plan->id]);
        $other = User::factory()->create();
        $this->afterGateway(fn () => $plan->update(['user_id' => $other->id]));

        $this->suggest($user, $item);
        $this->assertWithheld($item);
    }

    public function test_entitlement_expiry_after_provider_blocks_inbox_ai_persistence(): void
    {
        [$user, $item] = $this->scenario();
        $this->afterGateway(fn () => UserProductGrant::query()
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]));

        $this->suggest($user, $item);
        $this->assertWithheld($item);
    }

    public function test_unchanged_shared_editor_inbox_persists_suggestion(): void
    {
        [$editor, $item] = $this->sharedEditorScenario();

        $this->suggest($editor, $item)->assertSessionHasNoErrors();

        $this->assertSame('review', $item->fresh()->status);
        $this->assertSame('task_evidence', data_get($item->fresh()->metadata, 'routing_suggestion.destination'));
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_owner_change_and_return_during_provider_withholds_stale_inbox_suggestion(): void
    {
        [$owner, $item] = $this->scenario();
        $plan = $this->plan($owner);
        $item->update(['plan_id' => $plan->id]);
        $other = User::factory()->create();

        $this->afterGateway(function () use ($plan, $owner, $other) {
            $plan->update(['user_id' => $other->id]);
            $plan->update(['user_id' => $owner->id]);
        });

        $this->suggest($owner, $item);
        $this->assertWithheld($item);
        $this->assertSame($owner->id, $plan->fresh()->user_id);
    }

    public function test_editor_downgrade_and_return_during_provider_withholds_stale_inbox_suggestion(): void
    {
        [$editor, $item, $plan, $membership] = $this->sharedEditorScenario();

        $this->afterGateway(function () use ($membership) {
            $membership->update(['role' => PlanMember::ROLE_VIEWER]);
            $membership->update(['role' => PlanMember::ROLE_EDITOR]);
        });

        $this->suggest($editor, $item);
        $this->assertWithheld($item);
        $this->assertSame(PlanMember::ROLE_EDITOR, $membership->fresh()->role);
        $this->assertTrue((bool) $plan->fresh()->is_collaborative);
    }

    public function test_editor_remove_and_same_role_rejoin_during_provider_withholds_stale_inbox_suggestion(): void
    {
        [$editor, $item, $plan, $membership] = $this->sharedEditorScenario();
        $originalMemberId = $membership->id;

        $this->afterGateway(function () use ($editor, $plan, $membership) {
            $membership->delete();
            PlanMember::query()->create([
                'plan_id' => $plan->id,
                'user_id' => $editor->id,
                'role' => PlanMember::ROLE_EDITOR,
                'joined_at' => now(),
            ]);
        });

        $this->suggest($editor, $item);
        $this->assertWithheld($item);
        $this->assertNotEquals($originalMemberId, PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $editor->id)->firstOrFail()->id);
    }

    public function test_unrelated_plan_title_update_does_not_invalidate_inbox_editor(): void
    {
        [$editor, $item, $plan] = $this->sharedEditorScenario();
        $this->afterGateway(fn () => $plan->update(['title' => '新しいPlanタイトル']));

        $this->suggest($editor, $item)->assertSessionHasNoErrors();
        $this->assertSame('review', $item->fresh()->status);
        $this->assertSame('新しいPlanタイトル', $plan->fresh()->title);
    }

    private function assertNoPreProviderSubmission(InboxItem $item): void
    {
        $this->assertSame('new', $item->fresh()->status);
        $this->assertNull(data_get($item->fresh()->metadata, 'routing_suggestion'));
        $this->assertDatabaseCount('native_ai_runs', 0);
        Http::assertSentCount(0);
    }

    private function mutateOnSecondFileRead(User $user, InboxItem $item, callable $mutate): void
    {
        // Storage bytes remain identical. Inject a committed database change
        // immediately after preparing the provider input, but before the
        // pre-provider admission transaction. Prior R38 code sent AI anyway.
        $real = Storage::getFacadeRoot();
        $fake = Mockery::mock();
        $fake->shouldReceive('exists')->atLeast()->once()->andReturn(true);
        $reads = 0;
        $fake->shouldReceive('get')->twice()->with($item->storage_path)
            ->andReturnUsing(function () use (&$reads, $mutate): string {
                $reads++;
                if ($reads === 2) {
                    $mutate();
                }

                return 'original-image-bytes';
            });
        Storage::swap($fake);
        try {
            $this->suggest($user, $item)->assertSessionHas('status');
        } finally {
            Storage::swap($real);
        }
        $this->assertSame(2, $reads);
    }

    private function sharedEditorScenario(string $type = 'text'): array
    {
        [$editor, $item] = $this->scenario($type);
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $plan->update(['is_collaborative' => true]);
        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $item->update(['plan_id' => $plan->id]);

        return [$editor, $item, $plan, $membership];
    }

    private function scenario(string $type = 'text'): array
    {
        $user = User::factory()->create();
        $this->grant($user);
        $path = null;

        if ($type === 'image') {
            $path = 'inbox/'.$user->id.'/material.png';
            Storage::put($path, 'original-image-bytes');
        }

        $item = InboxItem::query()->create([
            'user_id' => $user->id,
            'source_type' => $type,
            'status' => 'new',
            'title' => '今日の活動',
            'content' => '実装が完了した',
            'storage_path' => $path,
            'mime_type' => $type === 'image' ? 'image/png' : null,
            'original_name' => $type === 'image' ? 'material.png' : null,
            'metadata' => [],
        ]);

        return [$user, $item];
    }

    private function grant(User $user): void
    {
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '開発Plan',
            'category' => '個人開発',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
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

    private function suggest(User $user, InboxItem $item)
    {
        return $this->actingAs($user)
            ->post(route('inbox.suggest', $item))
            ->assertRedirect();
    }

    private function assertWithheld(InboxItem $item): void
    {
        $this->assertSame('new', $item->fresh()->status);
        $this->assertNull(data_get($item->fresh()->metadata, 'routing_suggestion'));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertDatabaseCount('task_evidences', 0);
        Http::assertSentCount(1);
    }
}
