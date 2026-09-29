<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionDistributionService;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionDistributionV472Test extends TestCase
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

    public function test_plan_can_prepare_multiple_external_prompts_from_the_same_canonical_context(): void
    {
        [$user, $plan, $a, $b, $c] = $this->scenario();

        $before = [
            $a->id => $a->only(['title', 'description', 'status', 'progress_percent', 'remaining_minutes']),
            $c->id => $c->only(['title', 'description', 'status', 'progress_percent', 'remaining_minutes']),
        ];

        $this->actingAs($user)
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'external',
                'selected_task_ids' => [$a->id, $c->id],
                'actor_label' => [
                    $a->id => 'A / Evidence担当',
                    $c->id => 'C / Validation担当',
                ],
                'actor_type' => [
                    $a->id => 'ai',
                    $c->id => 'human_ai',
                ],
                'available_minutes' => [
                    $a->id => 30,
                    $c->id => 45,
                ],
            ])
            ->assertRedirect(route('plans.execution_distribution.show', $plan))
            ->assertSessionHasNoErrors();

        $bundle = session(ExecutionDistributionService::sessionKey($plan));
        $this->assertIsArray($bundle);
        $this->assertSame('execution_distribution', data_get($bundle, 'flow'));
        $this->assertCount(2, data_get($bundle, 'targets', []));

        $targets = collect(data_get($bundle, 'targets', []))->keyBy('task_id');
        $aTarget = $targets->get($a->id);
        $cTarget = $targets->get($c->id);

        $this->assertSame('A / Evidence担当', data_get($aTarget, 'actor_label'));
        $this->assertSame('ai', data_get($aTarget, 'actor_type'));
        $this->assertSame(30, data_get($aTarget, 'available_minutes'));
        $this->assertStringContainsString('【担当ラベル】', (string) data_get($aTarget, 'handoff_prompt'));
        $this->assertStringContainsString('A / Evidence担当', (string) data_get($aTarget, 'handoff_prompt'));
        $this->assertStringContainsString($plan->title, (string) data_get($aTarget, 'handoff_prompt'));
        $this->assertStringContainsString($b->title, (string) data_get($aTarget, 'handoff_prompt'));

        $this->assertSame('C / Validation担当', data_get($cTarget, 'actor_label'));
        $this->assertSame('blocked', data_get($cTarget, 'dependency_state'));
        $this->assertStringContainsString($a->title, (string) data_get($cTarget, 'handoff_prompt'));
        $this->assertStringContainsString($b->title, (string) data_get($cTarget, 'handoff_prompt'));

        $a->refresh();
        $c->refresh();
        $this->assertSame($before[$a->id], $a->only(array_keys($before[$a->id])));
        $this->assertSame($before[$c->id], $c->only(array_keys($before[$c->id])));
        $this->assertDatabaseCount('tasks', 3);

        $this->actingAs($user)
            ->get(route('plans.execution_distribution.show', $plan))
            ->assertOk()
            ->assertSee('DISTRIBUTION BUNDLE')
            ->assertSee('A / Evidence担当')
            ->assertSee('C / Validation担当')
            ->assertSee('この担当のPromptをコピー');
    }

    public function test_distribution_rejects_task_from_another_plan(): void
    {
        [$user, $plan, $a] = $this->scenario();
        $otherPlan = $this->plan($user, '別Plan');
        $otherTask = $this->task($otherPlan, '外部Task', 'todo', 0, 1);

        $this->actingAs($user)
            ->from(route('plans.execution_distribution.show', $plan))
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'external',
                'selected_task_ids' => [$a->id, $otherTask->id],
                'actor_type' => [
                    $a->id => 'human_ai',
                    $otherTask->id => 'ai',
                ],
            ])
            ->assertRedirect(route('plans.execution_distribution.show', $plan))
            ->assertSessionHasErrors('selected_task_ids');

        $this->assertNull(session(ExecutionDistributionService::sessionKey($plan)));
    }

    public function test_distribution_marks_generated_targets_stale_after_canonical_context_changes(): void
    {
        [$user, $plan, $a, $b, $c] = $this->scenario();

        $this->actingAs($user)
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'external',
                'selected_task_ids' => [$a->id, $c->id],
                'actor_label' => [
                    $a->id => 'A',
                    $c->id => 'C',
                ],
                'actor_type' => [
                    $a->id => 'ai',
                    $c->id => 'ai',
                ],
            ])
            ->assertSessionHasNoErrors();

        $b->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('plans.execution_distribution.show', $plan))
            ->assertOk()
            ->assertSee('再生成が必要')
            ->assertSee('Task / Dependency / EvidenceなどのContextが生成後に変わっています。');
    }

    public function test_completed_task_cannot_be_selected_as_distribution_target(): void
    {
        [$user, $plan, $a] = $this->scenario();
        $done = $this->task($plan, '完了済み担当', 'done', 100, 4);

        $this->actingAs($user)
            ->from(route('plans.execution_distribution.show', $plan))
            ->post(route('plans.execution_distribution.prepare', $plan), [
                'generation_mode' => 'external',
                'selected_task_ids' => [$a->id, $done->id],
                'actor_type' => [
                    $a->id => 'human_ai',
                    $done->id => 'human_ai',
                ],
            ])
            ->assertRedirect(route('plans.execution_distribution.show', $plan))
            ->assertSessionHasErrors('selected_task_ids');
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'HINANEX');

        $a = $this->task($plan, 'A / Evidence収集', 'doing', 40, 1);
        $b = $this->task($plan, 'B / Normalize', 'doing', 60, 2);
        $c = $this->task($plan, 'C / Validation', 'todo', 0, 3);

        app(TaskDependencyService::class)->sync($c, [$b->id]);

        return [$user, $plan, $a, $b, $c];
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => '全体Contextを保って複数担当へ実行を分配する',
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
