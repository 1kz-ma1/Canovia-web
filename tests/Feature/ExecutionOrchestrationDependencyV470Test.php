<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExecutionOrchestrationDependencyV470Test extends TestCase
{
    use RefreshDatabase;

    public function test_task_can_have_multiple_dependencies_and_legacy_column_mirrors_first(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $a = $this->task($plan, 'A');
        $b = $this->task($plan, 'B');
        $c = $this->task($plan, 'C');

        app(TaskDependencyService::class)->sync($c, [$b->id, $a->id]);

        $c->refresh()->load('prerequisites');

        $this->assertSame([$a->id, $b->id], $c->dependencyIds());
        $this->assertSame($a->id, (int) $c->depends_on_task_id);
        $this->assertCount(2, $c->prerequisites);
    }

    public function test_dependency_cycle_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $a = $this->task($plan, 'A');
        $b = $this->task($plan, 'B');

        app(TaskDependencyService::class)->sync($b, [$a->id]);

        $this->expectException(ValidationException::class);
        app(TaskDependencyService::class)->sync($a, [$b->id]);
    }

    public function test_task_update_syncs_multiple_dependencies_through_controller(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $a = $this->task($plan, 'A');
        $b = $this->task($plan, 'B');
        $target = $this->task($plan, 'C');

        $this->actingAs($user)
            ->put(route('tasks.update', $target), [
                'title' => $target->title,
                'description' => $target->description,
                'estimated_minutes' => 60,
                'remaining_minutes' => 60,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 2,
                'activation_cost' => 2,
                'next_action_note' => null,
                'dependency_task_ids' => [$b->id, $a->id],
                'resource_ids' => [],
            ])
            ->assertRedirect(route('plans.show', $plan))
            ->assertSessionHasNoErrors();

        $target->refresh()->load('prerequisites');

        $this->assertSame([$a->id, $b->id], $target->dependencyIds());
        $this->assertSame($a->id, (int) $target->depends_on_task_id);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => \Illuminate\Support\Str::random(64),
            'public_slug' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Dependency Test',
            'description' => 'V47 dependency graph',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
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
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
