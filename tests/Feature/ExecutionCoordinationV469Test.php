<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionDistributionService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionCoordinationV469Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_completed_task_projects_ready_and_still_blocked_direct_dependents(): void
    {
        [$user, $plan, $source, $other, $ready, $blocked] = $this->scenario();

        $source->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $source]))
            ->assertOk()
            ->assertSee('EXECUTION COORDINATION')
            ->assertSee('完了後の実行可能範囲を再評価しました')
            ->assertSee('READY NEXT')
            ->assertSee('STILL BLOCKED')
            ->assertSee($ready->title)
            ->assertSee($blocked->title)
            ->assertSee('前提: '.$other->title)
            ->assertSee('個別Orchestration')
            ->assertSee('次の担当候補を分配画面で確認');
    }

    public function test_completed_source_marks_existing_distribution_stale_without_regenerating_it(): void
    {
        [$user, $plan, $source, , $ready] = $this->scenario();

        $this->actingAs($user)
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'external',
                'selected_task_ids' => [$ready->id],
                'actor_label' => [
                    $ready->id => 'B / Implementation',
                ],
                'actor_type' => [
                    $ready->id => 'human_ai',
                ],
            ])
            ->assertSessionHasNoErrors();

        $beforeBundle = session(ExecutionDistributionService::sessionKey($plan));
        $beforePrompt = (string) data_get($beforeBundle, 'targets.0.handoff_prompt');

        $this->assertNotSame('', $beforePrompt);
        $this->assertSame('blocked', data_get($beforeBundle, 'targets.0.dependency_state'));

        $source->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $source]))
            ->assertOk()
            ->assertSee('DISTRIBUTION STALE')
            ->assertSee('以前の指示をそのまま実行しないでください')
            ->assertSee('B / Implementation')
            ->assertSee('次の担当候補を分配画面で確認');

        $afterBundle = session(ExecutionDistributionService::sessionKey($plan));

        $this->assertTrue((bool) data_get($afterBundle, 'targets.0.stale'));
        $this->assertSame('ready', data_get($afterBundle, 'targets.0.dependency_state'));
        $this->assertSame($beforePrompt, data_get($afterBundle, 'targets.0.handoff_prompt'));

        $this->actingAs($user)
            ->get(route('plans.execution_distribution.show', [
                'plan' => $plan,
                'suggested_task_ids' => (string) $ready->id,
            ]))
            ->assertOk()
            ->assertSee('Coordinationから、現在readyな1件を今回の分配候補として選択しています。');
    }

    public function test_completed_source_marks_existing_individual_instruction_stale_without_regeneration(): void
    {
        [$user, $plan, $source, , $ready] = $this->scenario();

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.prepare', [$plan, $ready]), [
                'generation_mode' => 'external',
                'actor_type' => 'human_ai',
                'available_minutes' => 30,
            ])
            ->assertSessionHasNoErrors();

        $sessionKey = ExecutionRequestHandoffService::sessionKey($plan, $ready);
        $beforeState = session($sessionKey);
        $beforePrompt = (string) data_get($beforeState, 'handoff_prompt');

        $this->assertNotSame('', $beforePrompt);

        $source->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $source]))
            ->assertOk()
            ->assertSee('INDIVIDUAL STALE')
            ->assertSee($ready->title)
            ->assertSee('現在Contextで確認');

        $afterState = session($sessionKey);
        $this->assertSame($beforePrompt, data_get($afterState, 'handoff_prompt'));
    }

    public function test_unfinished_source_does_not_show_completion_coordination_projection(): void
    {
        [$user, $plan, $source] = $this->scenario();

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $source]))
            ->assertOk()
            ->assertDontSee('EXECUTION COORDINATION');
    }

    public function test_invalid_distribution_suggestion_is_ignored(): void
    {
        [$user, $plan, $source, , $ready] = $this->scenario();
        $otherPlan = $this->plan($user, 'Other Plan');
        $otherTask = $this->task($otherPlan, 'Other Task', 'todo', 0, 1);

        $source->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('plans.execution_distribution.show', [
                'plan' => $plan,
                'suggested_task_ids' => $ready->id.','.$otherTask->id.',999999',
            ]))
            ->assertOk()
            ->assertSee('Coordinationから、現在readyな1件を今回の分配候補として選択しています。')
            ->assertDontSee('Other Task');
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'HINANEX');

        $source = $this->task($plan, 'A / Evidence収集', 'doing', 80, 1);
        $other = $this->task($plan, 'B / Normalize', 'doing', 60, 2);
        $ready = $this->task($plan, 'C / Validation', 'todo', 0, 3);
        $blocked = $this->task($plan, 'D / Integration', 'todo', 0, 4);

        app(TaskDependencyService::class)->sync($ready, [$source->id]);
        app(TaskDependencyService::class)->sync($blocked, [$source->id, $other->id]);

        return [$user, $plan, $source, $other, $ready, $blocked];
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Task完了後のDependency / Distributionを再評価する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $progress,
        int $sort,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title.' scope',
            'estimated_minutes' => 120,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sort,
        ]);
    }
}
