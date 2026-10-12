<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\FutureMemo;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionConversationService;
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CompanionAiCommitFenceV05Test extends TestCase
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
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-test',
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);

        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-companion-commit-fence',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'reply' => 'Planの進め方を提案します。',
                        'memories' => [[
                            'kind' => 'interest',
                            'category' => 'other',
                            'source_quote' => '長く続けたいです',
                        ]],
                        'candidates' => [[
                            'type' => 'update_task',
                            'title' => '次のActionを確認する',
                            'summary' => 'まだ未反映です',
                            'payload_json' => '{"next_action_note":"確認する"}',
                        ]],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ], 200)]);
    }

    public function test_expired_premium_grant_after_gateway_return_prevents_any_ai_derived_commit(): void
    {
        [$user, $plan, $task, $thread, $grant] = $this->scenario();
        $this->afterGateway(fn () => $grant->update(['expires_at' => now()->subMinute()]));

        $this->assertDeniedCommit('native_ai_purpose_not_entitled', $thread, $user, $plan, $task);
    }

    public function test_plan_owner_transfer_after_gateway_return_drops_reply_candidates_and_memories(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(fn () => $plan->update(['user_id' => $other->id]));

        $this->assertDeniedCommit('native_ai_target_changed', $thread, $user, $plan, $task);
    }

    public function test_task_move_after_gateway_return_blocks_stale_context(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $other = $this->plan($user);
        $this->afterGateway(fn () => $task->update(['plan_id' => $other->id]));

        $this->assertDeniedCommit('native_ai_target_changed', $thread, $user, $plan, $task);
    }

    public function test_thread_scope_change_after_gateway_return_blocks_old_scoped_reply(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->afterGateway(fn () => $thread->update(['task_id' => null]));

        $this->assertDeniedCommit('native_ai_target_changed', $thread, $user, $plan, $task);
    }

    public function test_valid_purpose_and_unchanged_context_still_persist_reviewable_candidate(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario();
        $this->afterGateway(fn () => null);

        $result = app(CompanionConversationService::class)->send(
            $thread, $user, '長く続けたいです', (string) Str::uuid(), $plan, $task,
        );

        $this->assertSame('assistant', $result['message']->role);
        $this->assertDatabaseCount('companion_messages', 2);
        $this->assertDatabaseCount('companion_mutation_candidates', 1);
        $this->assertSame(
            CompanionMutationCandidate::STATUS_PENDING,
            CompanionMutationCandidate::firstOrFail()->status,
        );
        $this->assertDatabaseCount('native_ai_runs', 1);
        Http::assertSentCount(1);
    }

    private function assertDeniedCommit(
        string $code,
        CompanionThread $thread,
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        try {
            app(CompanionConversationService::class)->send(
                $thread, $user, '長く続けたいです', (string) Str::uuid(), $plan, $task,
            );
            $this->fail('Revoked AI output must not reach durable Companion data.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }

        // The original human message and already completed provider run remain
        // auditable; only unauthorized model-derived writes are withheld.
        $this->assertSame(['user'], CompanionMessage::query()->pluck('role')->all());
        $this->assertDatabaseCount('companion_mutation_candidates', 0);
        $this->assertDatabaseCount('future_memos', 0);
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertNull($thread->fresh()->last_message_at);
        Http::assertSentCount(1);
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

    private function scenario(): array
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Current Task',
            'status' => 'doing',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);
        $grant = UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);

        return [$user, $plan, $task, $thread, $grant];
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Companion G3',
            'category' => '仕事',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }
}
