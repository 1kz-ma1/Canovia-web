<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\PlanDashboardBoardService;
use App\Services\PlanProgressService;
use App\Services\RoadmapService;
use App\Services\RoadmapSpatialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanDashboardBoardV502Test extends TestCase
{
    use RefreshDatabase;

    public function test_board_projects_next_ready_blocked_and_recent_activity_without_ai(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user);

        $current = $this->task($plan, 'Current Task', 'doing', 1, 1);
        $ready = $this->task($plan, 'Ready Task', 'todo', 2, 2);
        $blocked = $this->task($plan, 'Blocked Task', 'todo', 3, 3, $current->id);

        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $ready->id,
            'task_title_snapshot' => $ready->title,
            'worked_on' => today(),
            'actual_minutes' => 45,
            'progress_delta_percent' => 20,
            'progress_before_percent' => 0,
            'progress_after_percent' => 20,
            'remaining_minutes_before' => 90,
            'remaining_minutes_after' => 72,
            'difficulty' => 2,
            'memo' => '進めた',
            'outcome' => 'checkpoint',
        ]);

        $plan->load([
            'tasks.prerequisite',
            'tasks.prerequisites',
            'tasks.resources',
            'workLogs' => fn ($query) => $query
                ->with('task')
                ->latest('worked_on')
                ->latest('id'),
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $progress = app(PlanProgressService::class)->calculate($plan);
        $roadmap = app(RoadmapService::class)->build($plan);
        $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);
        $board = app(PlanDashboardBoardService::class)->build(
            $plan,
            $progress,
            $roadmap,
            $spatial,
        );

        $this->assertSame(1, $board['schema_version']);
        $this->assertSame($current->id, data_get($board, 'next.task_id'));
        $this->assertSame('Current Task', data_get($board, 'next.title'));

        $this->assertContains(
            $ready->id,
            collect($board['ready'])->pluck('task_id')->all(),
        );
        $this->assertContains(
            $blocked->id,
            collect($board['blocked'])->pluck('task_id')->all(),
        );

        $this->assertSame(1, data_get($board, 'signals.blocked_count'));
        $this->assertSame('alert', data_get($board, 'signals.attention.level'));
        $this->assertSame('前提待ちあり', data_get($board, 'signals.attention.label'));

        $this->assertSame('Ready Task', data_get($board, 'activity.0.task_title'));
        $this->assertSame(45, data_get($board, 'activity.0.actual_minutes'));
        $this->assertSame(20, data_get($board, 'activity.0.progress_delta_percent'));

        $this->assertSame(3, data_get($board, 'overview.task_count'));
        $this->assertArrayNotHasKey('ai', $board);
        $this->assertArrayNotHasKey('recommendation', $board);
    }

    private function plan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Board Plan',
            'description' => '複数情報を一枚で確認するPlan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today()->subWeek(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        string $status,
        int $priority,
        int $sortOrder,
        ?int $dependsOn = null,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $dependsOn,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 90,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $status === 'doing' ? 30 : ($status === 'done' ? 100 : 0),
            'status' => $status,
            'priority' => $priority,
            'activation_cost' => 1,
            'sort_order' => $sortOrder,
        ]);
    }
}
