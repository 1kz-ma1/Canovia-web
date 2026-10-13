<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyScopeCapture;
use App\Models\User;
use App\Services\NativeAiGateway;
use App\Services\StudyScopeCaptureAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class StudyScopeAiCommitFenceG3Test extends TestCase
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

    }

    public function test_plan_transfer_after_valid_ai_return_does_not_save_draft(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(fn () => $plan->update(['user_id' => $other->id]));

        $this->assertDenied('native_ai_target_changed', $capture, $plan, $actor);
    }

    public function test_plan_domain_changed_after_valid_ai_return_does_not_save_draft(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => $plan->update(['workspace_domain_override' => 'development']));

        $this->assertDenied('native_ai_target_changed', $capture, $plan, $actor);
    }

    public function test_original_file_change_after_valid_ai_return_rejects_derived_scope(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => Storage::put($source->storage_path, 'material changed'));

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $actor);
        $this->assertSame('material changed', Storage::get($source->storage_path));
    }

    public function test_inbox_note_change_after_ai_return_rejects_old_prompt_context(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => $source->update(['content' => '重要な訂正: 数学以外']));

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $actor);
        $this->assertSame('重要な訂正: 数学以外', $source->fresh()->content);
    }

    public function test_source_deleted_after_provider_cannot_save_or_recreate_ai_draft(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => $source->delete());

        $this->assertDenied('native_ai_target_changed', $capture, $plan, $actor);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_human_confirmation_during_provider_is_never_overwritten_by_ai(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => $capture->update([
            'status' => 'confirmed',
            'exam_title' => 'ユーザー確定の試験',
            'draft_data' => ['items' => [['subject' => '国語']]],
            'confirmed_at' => now(),
        ]));

        $this->assertDenied('native_ai_target_changed', $capture, $plan, $actor, 'confirmed');
        $this->assertSame('confirmed', $capture->fresh()->status);
        $this->assertSame('ユーザー確定の試験', $capture->fresh()->exam_title);
        $this->assertSame('国語', data_get($capture->fresh()->draft_data, 'items.0.subject'));
    }

    public function test_feature_entitlement_removed_after_ai_response_disallows_draft(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $this->afterGateway(fn () => config([
            'entitlements.features.'.FeatureKey::StudyScopeCapture->value.'.free' => false,
        ]));

        $this->assertDenied('native_ai_purpose_not_entitled', $capture, $plan, $actor);
    }

    public function test_valid_free_scope_capture_still_creates_review_draft_without_confirmed_scope(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();

        $result = app(StudyScopeCaptureAnalysisService::class)
            ->analyze($capture, $plan, $actor->id);

        $this->assertSame(1, $result['item_count']);
        $this->assertSame('review', $capture->fresh()->status);
        $this->assertSame('中間試験', $capture->fresh()->exam_title);
        $this->assertSame('数学', data_get($capture->fresh()->draft_data, 'items.0.subject'));
        $this->assertSame('succeeded', NativeAiRun::firstOrFail()->status);
        $this->assertDatabaseCount('study_scope_items', 0);
        $this->assertDatabaseCount('tasks', 0);
        Http::assertSentCount(1);
    }

    public function test_gateway_failure_retains_original_file_and_retryable_failed_capture(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario(false);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'rate_limit_exceeded'],
        ], 429)]);

        $this->assertThrows(
            fn () => app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id),
            NativeAiExecutionException::class,
        );
        $this->assertSame('failed', $capture->fresh()->status);
        $this->assertSame('openai_rate_limit_exceeded', $capture->fresh()->failure_code);
        Storage::assertExists($source->storage_path);
    }

    public function test_uninterrupted_shared_editor_still_receives_reviewable_free_scope_draft(): void
    {
        [$editor, $plan, $source, $capture] = $this->sharedScenario();

        $result = app(StudyScopeCaptureAnalysisService::class)
            ->analyze($capture, $plan, $editor->id);

        $this->assertSame(1, $result['item_count']);
        $this->assertSame('review', $capture->fresh()->status);
        $this->assertSame('数学', data_get($capture->fresh()->draft_data, 'items.0.subject'));
        $this->assertDatabaseCount('study_scope_items', 0);
    }

    public function test_owner_transfer_and_return_after_provider_refuses_old_scope_draft(): void
    {
        [$owner, $plan, $source, $capture] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(function () use ($plan, $owner, $other) {
            $plan->update(['user_id' => $other->id]);
            $plan->update(['user_id' => $owner->id]);
        });

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $owner);
        $this->assertSame($owner->id, $plan->fresh()->user_id);
        Storage::assertExists($source->storage_path);
    }

    public function test_editor_downgrade_and_return_after_provider_refuses_old_scope_draft(): void
    {
        [$editor, $plan, $source, $capture, $member] = $this->sharedScenario();
        $this->afterGateway(function () use ($member) {
            $member->update(['role' => PlanMember::ROLE_VIEWER]);
            $member->update(['role' => PlanMember::ROLE_EDITOR]);
        });

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $editor);
        $this->assertSame(PlanMember::ROLE_EDITOR, $member->fresh()->role);
        Storage::assertExists($source->storage_path);
    }

    public function test_same_role_rejoin_after_provider_refuses_old_scope_draft(): void
    {
        [$editor, $plan, $source, $capture, $member] = $this->sharedScenario();
        $originalMemberId = $member->id;
        $this->afterGateway(function () use ($member, $plan, $editor) {
            $member->delete();
            PlanMember::query()->create([
                'plan_id' => $plan->id,
                'user_id' => $editor->id,
                'role' => PlanMember::ROLE_EDITOR,
                'joined_at' => now(),
            ]);
        });

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $editor);
        $this->assertNotEquals($originalMemberId, PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', $editor->id)->firstOrFail()->id);
    }

    public function test_collaboration_off_and_on_after_provider_invalidates_old_scope_draft(): void
    {
        [$editor, $plan, $source, $capture] = $this->sharedScenario();
        $this->afterGateway(function () use ($plan) {
            $plan->update(['is_collaborative' => false]);
            $plan->update(['is_collaborative' => true]);
        });

        $this->assertDenied('native_ai_source_changed', $capture, $plan, $editor);
        $this->assertTrue((bool) $plan->fresh()->is_collaborative);
    }

    public function test_unrelated_plan_title_change_does_not_invalidate_scope_draft(): void
    {
        [$editor, $plan, $source, $capture] = $this->sharedScenario();
        $this->afterGateway(fn () => $plan->update(['title' => '範囲確認用の改訂名']));

        $result = app(StudyScopeCaptureAnalysisService::class)
            ->analyze($capture, $plan, $editor->id);

        $this->assertSame(1, $result['item_count']);
        $this->assertSame('review', $capture->fresh()->status);
        $this->assertSame('範囲確認用の改訂名', $plan->fresh()->title);
    }

    public function test_provider_failure_after_editor_role_aba_cannot_mark_scope_failed(): void
    {
        [$editor, $plan, $source, $capture, $member] = $this->sharedScenario(false);

        Http::fake(['https://api.openai.com/v1/responses' => function () use ($member) {
            $member->update(['role' => PlanMember::ROLE_VIEWER]);
            $member->update(['role' => PlanMember::ROLE_EDITOR]);

            return Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429);
        }]);

        $this->assertThrows(
            fn () => app(StudyScopeCaptureAnalysisService::class)
                ->analyze($capture, $plan, $editor->id),
            NativeAiExecutionException::class,
        );

        $this->assertSame('captured', $capture->fresh()->status);
        $this->assertNull($capture->fresh()->failure_code);
        $this->assertNull($capture->fresh()->native_ai_run_id);
        Storage::assertExists($source->storage_path);
    }

    public function test_human_can_confirm_unmodified_signed_ai_scope_and_edit_values(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id);

        $signature = data_get($capture->fresh()->draft_data, 'source_signature');
        $this->assertIsString($signature);
        $this->assertSame(64, strlen($signature));

        $this->confirmAiDraft($actor, $plan, $capture)->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $capture->fresh()->status);
        $this->assertDatabaseHas('study_scope_items', [
            'study_scope_capture_id' => $capture->id, 'subject' => '数学',
            'unit' => '二次関数・復習',
        ]);
        $this->assertSame('processed', $source->fresh()->status);
    }

    public function test_owner_transfer_and_return_before_human_confirmation_rejects_old_ai_scope(): void
    {
        [$owner, $plan, $source, $capture] = $this->scenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $owner->id);
        $other = User::factory()->create();
        $plan->update(['user_id' => $other->id]);
        $plan->update(['user_id' => $owner->id]);

        $this->assertConfirmationRejected($owner, $plan, $capture, $source);
    }

    public function test_editor_role_aba_before_human_confirmation_rejects_old_ai_scope(): void
    {
        [$editor, $plan, $source, $capture, $member] = $this->sharedScenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $editor->id);
        $member->update(['role' => PlanMember::ROLE_VIEWER]);
        $member->update(['role' => PlanMember::ROLE_EDITOR]);

        $this->assertConfirmationRejected($editor, $plan, $capture, $source);
    }

    public function test_same_editor_rejoin_before_human_confirmation_rejects_old_ai_scope(): void
    {
        [$editor, $plan, $source, $capture, $member] = $this->sharedScenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $editor->id);
        $member->delete();
        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);

        $this->assertConfirmationRejected($editor, $plan, $capture, $source);
    }

    public function test_source_bytes_change_before_human_confirmation_rejects_old_ai_scope(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id);
        Storage::put($source->storage_path, 'changed study scope image');

        $this->assertConfirmationRejected($actor, $plan, $capture, $source);
    }

    public function test_unsigned_historical_ai_review_fails_closed_without_material_promotion(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id);
        $draft = $capture->fresh()->draft_data;
        unset($draft['source_signature']);
        $capture->update(['draft_data' => $draft]);

        $this->assertConfirmationRejected($actor, $plan, $capture, $source);
    }

    public function test_manually_entered_scope_is_still_confirmable_after_ai_failure(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        $capture->update(['status' => 'failed', 'native_ai_run_id' => null,
            'failure_code' => 'test_rate_limit']);

        $this->actingAs($actor)->post(
            route('plans.study_scope.confirm', [$plan, $capture]),
            ['exam_title' => '手動登録', 'items' => [
                ['subject' => '国語', 'unit' => '現代文', 'range_text' => 'p.1-10'],
            ]],
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $capture->fresh()->status);
        $this->assertDatabaseHas('study_scope_items', [
            'study_scope_capture_id' => $capture->id, 'subject' => '国語',
        ]);
    }

    public function test_stale_draft_index_cannot_be_promoted_while_reanalysis_in_progress(): void
    {
        [$actor, $plan, $source, $capture] = $this->scenario();
        app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id);
        $capture->update(['status' => 'captured']);

        $this->assertConfirmationRejected($actor, $plan, $capture, $source, 'captured');
    }

    private function confirmAiDraft(User $actor, Plan $plan, StudyScopeCapture $capture)
    {
        return $this->actingAs($actor)->post(
            route('plans.study_scope.confirm', [$plan, $capture]),
            ['exam_title' => '確認済み中間試験', 'items' => [
                ['draft_index' => 0, 'subject' => '数学', 'unit' => '二次関数・復習',
                    'range_text' => 'p.1-30'],
            ]],
        )->assertRedirect();
    }

    private function assertConfirmationRejected(
        User $actor,
        Plan $plan,
        StudyScopeCapture $capture,
        InboxItem $source,
        string $status = 'review',
    ): void {
        $this->confirmAiDraft($actor, $plan, $capture)->assertSessionHasErrors('items');
        $this->assertSame($status, $capture->fresh()->status);
        $this->assertSame('new', $source->fresh()->status);
        $this->assertDatabaseCount('study_scope_items', 0);
    }

    private function sharedScenario(bool $fakeSuccess = true): array
    {
        [$editor, $plan, $source, $capture] = $this->scenario($fakeSuccess);
        $owner = User::factory()->create();
        $plan->update(['user_id' => $owner->id, 'is_collaborative' => true]);
        $member = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);

        return [$editor, $plan, $source, $capture, $member, $owner];
    }

    private function fakeSuccessResponse(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-study-scope-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'exam_title' => '中間試験',
                        'exam_date_text' => null,
                        'exam_date' => null,
                        'overall_confidence' => 85,
                        'items' => [[
                            'subject' => '数学',
                            'unit' => '二次関数',
                            'range_text' => 'p.1-30',
                            'page_start' => 1,
                            'page_end' => 30,
                            'source_excerpt' => '数学 p.1-30',
                            'confidence' => 85,
                        ]],
                        'ambiguities' => [],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ], 200)]);
    }

    private function scenario(bool $fakeSuccess = true): array
    {
        if ($fakeSuccess) {
            $this->fakeSuccessResponse();
        }
        $actor = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $actor->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Scope G3',
            'category' => '試験勉強',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $path = 'inbox/'.$actor->id.'/test-scope.png';
        Storage::put($path, 'test image fixture');
        $source = InboxItem::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $actor->id,
            'source_type' => 'image',
            'status' => 'new',
            'storage_path' => $path,
            'original_name' => 'test-scope.png',
            'mime_type' => 'image/png',
            'content' => '数学テスト範囲',
            'metadata' => ['capture_surface' => 'study_scope', 'study_scope_capture' => true],
        ]);
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $actor->id,
            'inbox_item_id' => $source->id,
            'status' => 'captured',
            'extraction_version' => 'study_scope_v1',
        ]);

        return [$actor, $plan, $source, $capture];
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

    private function assertDenied(
        string $code,
        StudyScopeCapture $capture,
        Plan $plan,
        User $actor,
        string $expectedCaptureStatus = 'captured',
    ): void {
        try {
            app(StudyScopeCaptureAnalysisService::class)->analyze($capture, $plan, $actor->id);
            $this->fail('Stale or unauthorized AI result must not become a Study Scope draft.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertNotNull($exception->runId);
        }

        $this->assertSame($expectedCaptureStatus, $capture->fresh()->status);
        if ($expectedCaptureStatus === 'captured') {
            $this->assertNull($capture->fresh()->draft_data);
            $this->assertNull($capture->fresh()->native_ai_run_id);
        }
        $this->assertDatabaseCount('study_scope_items', 0);
        $this->assertSame('succeeded', NativeAiRun::firstOrFail()->status);
        Http::assertSentCount(1);
    }
}
