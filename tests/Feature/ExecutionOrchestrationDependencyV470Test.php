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
        $plan = Plan::factory()->for(User::factory())->create();
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
        $plan = Plan::factory()->for(User::factory())->create();
        $a = $this->task($plan, 'A');
        $b = $this->task($plan, 'B');

        app(TaskDependencyService::class)->sync($b, [$a->id]);

        $this->expectException(ValidationException::class);
        app(TaskDependencyService::class)->sync($a, [$b->id]);
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
