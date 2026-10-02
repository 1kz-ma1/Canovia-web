<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActivityLog;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\AchievementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimelineAchievementV514Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_timeline_projects_work_collaboration_and_plan_completion_events(): void
    {
        $user = User::factory()->create(['name' => 'Owner']);
        $peer = User::factory()->create(['name' => 'Peer']);

        $plan = $this->createPlan($user, '完成したPlan', true);
        $task = $this->createTask($plan, '完成Task', 'done', 100);

        WorkLog::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today(),
            'actual_minutes' => 45,
            'progress_delta_percent' => 100,
            'memo' => '最後の仕上げ',
        ]);

        PlanActivityLog::create([
            'plan_id' => $plan->id,
            'user_id' => $peer->id,
            'action' => 'task_updated',
            'target_type' => 'task',
            'target_id' => $task->id,
            'metadata' => ['task_title' => $task->title],
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->get(route('timeline.index'));

        $response
            ->assertOk()
            ->assertSee('data-timeline-schema="2"', false)
            ->assertSee('data-timeline-event-kind="work"', false)
            ->assertSee('data-timeline-event-kind="collaboration"', false)
            ->assertSee('data-timeline-event-kind="plan_completed"', false)
            ->assertSee('完成したPlanを達成しました')
            ->assertSee('Peerさんがタスクを更新しました')
            ->assertSee('最後の仕上げ');
    }

    public function test_achievement_constellation_contains_completed_plans_only(): void
    {
        $user = User::factory()->create();

        $completed = $this->createPlan($user, '達成済み');
        $completed->update(['accent_key' => 'violet']);
        $this->createTask($completed, 'Done', 'done', 100);

        $active = $this->createPlan($user, '進行中');
        $this->createTask($active, 'Todo', 'todo', 0);

        $response = $this->actingAs($user)->get(route('timeline.index'));

        $response
            ->assertOk()
            ->assertSee('data-achievement-constellation', false)
            ->assertSee('data-achievement-plan-id="'.$completed->id.'"', false)
            ->assertSee('data-plan-accent="violet"', false)
            ->assertDontSee('data-achievement-plan-id="'.$active->id.'"', false)
            ->assertSee(route('achievements.show', $completed), false);
    }

    public function test_achievement_projection_position_is_deterministic_and_shared_with_achievement_index(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan($user, '共有判定');
        $this->createTask($plan, 'Done', 'done', 100);

        $loaded = Plan::with(['tasks', 'workLogs', 'adjustments'])->findOrFail($plan->id);
        $service = app(AchievementProjectionService::class);

        $first = $service->project($loaded, 0);
        $second = $service->project($loaded, 0);

        $this->assertTrue($service->isCompleted($loaded));
        $this->assertSame($first['constellation'], $second['constellation']);
        $this->assertSame(['x' => 18.0, 'y' => 34.0, 'ring' => 1], $first['constellation']);

        $this->actingAs($user)
            ->get(route('achievements.index'))
            ->assertOk()
            ->assertSee('共有判定');
    }


    public function test_achievement_detail_belongs_to_timeline_navigation(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan($user, 'Timeline配下の達成');
        $this->createTask($plan, 'Done', 'done', 100);

        $response = $this->actingAs($user)->get(route('achievements.show', $plan));

        $response
            ->assertOk()
            ->assertSee('data-canovia-nav-key="desktop-timeline"', false)
            ->assertSee('data-canovia-nav-key="mobile-timeline"', false)
            ->assertSee('>タイムライン<', false);
    }

    public function test_incomplete_plan_does_not_create_completion_event(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan($user, '未完成Plan');
        $this->createTask($plan, 'Todo', 'todo', 20);

        $this->actingAs($user)
            ->get(route('timeline.index'))
            ->assertOk()
            ->assertDontSee('data-timeline-event-kind="plan_completed"', false)
            ->assertDontSee('data-achievement-star', false);
    }

    private function createPlan(User $user, string $title, bool $collaborative = false): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => Str::uuid()->toString(),
            'title' => $title,
            'description' => 'V51.4 test',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function createTask(Plan $plan, string $title, string $status, int $progress): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Timeline event test',
            'estimated_minutes' => 60,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
