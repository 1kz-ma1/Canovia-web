<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\ExecutionDistributionService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\NativeAiGateway;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ExecutionPacketAiCommitFenceG3Test extends TestCase
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
            'native_ai.providers.openai.model' => 'test-model',
        ]);

        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-execution-g3',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'schema_version' => '1.0',
                        'flow' => 'execution_packet',
                        'summary' => '現在の契約を検証する',
                        'execution_mode' => 'prepare',
                        'current_situation' => '前提データを確認する',
                        'role' => '検証担当',
                        'objective' => '検証を完了',
                        'reason' => '現時点でできる準備',
                        'actions' => [[
                            'title' => '検証する',
                            'details' => '契約を点検',
                            'estimated_minutes' => 20,
                        ]],
                        'inputs' => [],
                        'outputs' => [],
                        'dependencies' => [],
                        'assumptions' => [],
                        'do_not_touch' => [],
                        'completion_criteria' => [],
                        'confirmation_required' => [],
                        'next_phase' => '',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ], 200)]);
    }

    public function test_current_native_packet_is_kept_in_session_but_never_changes_task(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();

        $this->generate($user, $plan, $task)->assertSessionHasNoErrors();

        $state = session(ExecutionRequestHandoffService::sessionKey($plan, $task));
        $this->assertSame('現在の契約を検証する', data_get($state, 'packet.summary'));
        $this->assertGreaterThan(0, (int) ($state['native_run_id'] ?? 0));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        $this->assertSame('todo', $task->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_dependency_completes_after_provider_and_old_packet_is_never_committed(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $this->afterGateway(fn () => $dependency->update([
            'status' => 'done', 'progress_percent' => 100, 'remaining_minutes' => 0,
        ]));

        $this->generate($user, $plan, $task);
        $this->assertWithheld($plan, $task);
        $this->assertSame('done', $dependency->fresh()->status);
    }

    public function test_task_move_after_provider_does_not_persist_old_packet(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $other = $this->plan($user);
        $this->afterGateway(fn () => $task->update(['plan_id' => $other->id]));

        $this->generate($user, $plan, $task);
        $this->assertWithheld($plan, $task);
        $this->assertSame($other->id, $task->fresh()->plan_id);
    }

    public function test_plan_transfer_after_provider_does_not_persist_old_packet(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $other = User::factory()->create();
        $this->afterGateway(fn () => $plan->update(['user_id' => $other->id]));

        $this->generate($user, $plan, $task);
        $this->assertWithheld($plan, $task);
    }

    public function test_grant_expires_after_provider_and_old_packet_is_withheld(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $this->afterGateway(fn () => UserProductGrant::query()
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]));

        $this->generate($user, $plan, $task);
        $this->assertWithheld($plan, $task);
    }

    public function test_existing_native_session_packet_is_redacted_when_grant_revoked_before_read(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $this->generate($user, $plan, $task);
        $this->assertNotNull(data_get(session(ExecutionRequestHandoffService::sessionKey($plan, $task)), 'packet'));

        UserProductGrant::query()->where('user_id', $user->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertDontSee('現在の契約を検証する');

        $this->assertNull(data_get(session(ExecutionRequestHandoffService::sessionKey($plan, $task)), 'packet'));
    }

    public function test_multi_target_distribution_discards_first_packet_when_second_call_changes_its_context(): void
    {
        [$user, $plan, $dependency, $task] = $this->scenario();
        $second = $this->task($plan, 'Second target');
        $calls = 0;
        $this->afterGateway(function () use (&$calls, $task): void {
            $calls++;
            if ($calls === 2) {
                $task->update(['description' => '人が途中で修正した内容']);
            }
        }, 2);

        $this->actingAs($user)
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'native',
                'selected_task_ids' => [$task->id, $second->id],
                'actor_type' => [$task->id => 'human_ai', $second->id => 'human_ai'],
            ])
            ->assertRedirect();

        $bundle = session(ExecutionDistributionService::sessionKey($plan));
        $first = collect($bundle['targets'])->firstWhere('task_id', $task->id);
        $this->assertNotNull($first);
        $this->assertTrue($first['stale']);
        $this->assertNull($first['packet']);
        $this->assertSame('人が途中で修正した内容', $task->fresh()->description);
        $this->assertDatabaseCount('native_ai_runs', 2);
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->grant($user);
        $plan = $this->plan($user);
        $dependency = $this->task($plan, 'Dependency');
        $task = $this->task($plan, 'Target');
        app(TaskDependencyService::class)->sync($task, [$dependency->id]);

        return [$user, $plan, $dependency, $task];
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Execution G3',
            'category' => '個人開発',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Current contract',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
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

    private function afterGateway(callable $callback, int $times = 1): void
    {
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->times($times)
            ->andReturnUsing(function (...$args) use ($real, $callback) {
                $result = $real->generateStructured(...$args);
                $callback();

                return $result;
            });
        app()->instance(NativeAiGateway::class, $mock);
    }

    private function generate(User $user, Plan $plan, Task $task)
    {
        return $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.prepare', [$plan, $task]), [
                'generation_mode' => 'native',
                'actor_type' => 'human_ai',
                'available_minutes' => 30,
            ])
            ->assertRedirect();
    }

    private function assertWithheld(Plan $plan, Task $task): void
    {
        $state = session(ExecutionRequestHandoffService::sessionKey($plan, $task));
        $this->assertNull(data_get($state, 'packet'));
        $this->assertNull(data_get($state, 'native_run_id'));
        $this->assertNull(data_get($state, 'handoff_prompt'));
        $this->assertSame('succeeded', NativeAiRun::query()->firstOrFail()->status);
        Http::assertSentCount(1);
    }
}
