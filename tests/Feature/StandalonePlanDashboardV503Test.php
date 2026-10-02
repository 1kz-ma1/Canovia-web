<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StandalonePlanDashboardV503Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_owner_can_open_plan_dashboard_without_map_ownership(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Standalone Dashboard');
        $task = $this->task($plan, 'Documentで確認する');

        $response = $this->actingAs($user)->get(route('plans.dashboard', $plan));

        $response
            ->assertOk()
            ->assertSee('data-plan-dashboard-standalone', false)
            ->assertSee('data-dashboard-document', false)
            ->assertSee('data-dashboard-document-scroll', false)
            ->assertSee('data-dashboard-document-stage', false)
            ->assertSee('data-dashboard-document-canvas', false)
            ->assertDontSee('data-dashboard-document-owner="map"', false)
            ->assertDontSee('data-map-plan-workspace', false)
            ->assertSee('data-plan-dashboard-board', false)
            ->assertSee('data-plan-board-region="overview"', false)
            ->assertSee('data-plan-board-region="progress"', false)
            ->assertSee('data-plan-board-region="next"', false)
            ->assertSee('data-plan-board-region="attention"', false)
            ->assertSee('data-plan-board-region="activity"', false)
            ->assertSee('data-plan-board-region="roadmap"', false)
            ->assertSee('data-roadmap-region-focus', false)
            ->assertSee('data-roadmap-task-detail-open', false)
            ->assertSee('data-plan-dashboard-task-detail', false)
            ->assertSee('data-map-surface-template="roadmap-task:'.$task->id.'"', false)
            ->assertSee('Classic Plan')
            ->assertSee('実行へ')
            ->assertSee($task->title);

        $workspace = $response->viewData('workspace');

        $this->assertSame($plan->id, data_get($workspace, 'plan.id'));
        $this->assertSame(1, data_get($workspace, 'dashboard_board.schema_version'));
        $this->assertSame($task->id, data_get($workspace, 'dashboard_board.next.task_id'));
    }

    public function test_private_plan_dashboard_is_not_visible_to_another_user(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'Private Dashboard');
        $this->task($plan, '秘密のTask');

        $this->actingAs($other)
            ->get(route('plans.dashboard', $plan))
            ->assertNotFound();
    }

    public function test_standalone_dashboard_and_map_compatibility_share_workspace_service(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/PlanDashboardController.php'));
        $projection = file_get_contents(app_path('Services/HierarchyMapProjectionService.php'));
        $runtime = file_get_contents(resource_path('js/dashboard-document.mjs'));
        $appCss = file_get_contents(resource_path('css/app.css'));
        $mapCss = file_get_contents(resource_path('css/map/index.css'));

        $this->assertStringContainsString('PlanDashboardWorkspaceService', $controller);
        $this->assertStringContainsString('PlanDashboardWorkspaceService', $projection);
        $this->assertStringContainsString("data-roadmap-region-focus", $runtime);
        $this->assertStringContainsString('state.focusElement = focusElement', $runtime);
        $this->assertStringContainsString("@import './plan-dashboard-board.css';", $appCss);
        $this->assertStringNotContainsString("plan-dashboard-board.css", $mapCss);
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の説明',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today()->subWeek(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 90,
            'remaining_minutes' => 60,
            'progress_percent' => 30,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
