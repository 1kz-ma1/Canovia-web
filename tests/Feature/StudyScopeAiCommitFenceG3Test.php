<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\NativeAiRun;
use App\Models\Plan;
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
