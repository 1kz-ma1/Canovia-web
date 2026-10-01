<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapPlanWorkspaceV491Test extends TestCase
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

    public function test_plan_intent_l1_places_plans_directly_without_category_nodes(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $dev = $this->plan($user, 'Canoviaを改善する', '個人開発', 1);
        $study = $this->plan($user, 'APに合格する', '資格学習', 2);
        $this->task($dev, 'Mapを直す');
        $this->task($study, '過去問を解く');

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'plan',
        ]));

        $response
            ->assertOk()
            ->assertSee('L1 · PLANS')
            ->assertSee($dev->title)
            ->assertSee($study->title);

        $graph = $response->viewData('graph');
        $nodeIds = $graph['nodes']->pluck('id')->all();

        $this->assertSame('hierarchy:intent:plan', $graph['center_node_id']);
        $this->assertContains('plan:'.$dev->id, $nodeIds);
        $this->assertContains('plan:'.$study->id, $nodeIds);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));

        $devNode = $graph['nodes']->firstWhere('id', 'plan:'.$dev->id);
        $this->assertSame('zoom-in', data_get($devNode, 'direct_navigation.kind'));
        $this->assertSame(
            route('map.index', [
                'level' => 'l2',
                'intent' => 'plan',
                'plan' => $dev->id,
            ]),
            data_get($devNode, 'direct_navigation.url'),
        );
    }

    public function test_plan_l2_reuses_classic_summary_and_roadmap_as_workspace_palette(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Plan Workspaceを仕上げる', '個人開発', 1);
        $task = $this->task($plan, 'Semantic Zoomを安定させる');

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'plan',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('L2 · PLAN DASHBOARD')
            ->assertSee('data-map-plan-workspace', false)
            ->assertSee('data-map-document-viewport', false)
            ->assertSee('data-map-document-chrome', false)
            ->assertSee('data-map-document-scroll', false)
            ->assertSee('data-map-document-stage', false)
            ->assertSee('data-map-document-canvas', false)
            ->assertSee('data-map-document-camera', false)
            ->assertSee('data-map-document-zoom-out', false)
            ->assertSee('data-map-document-fit', false)
            ->assertSee('data-map-document-zoom-in', false)
            ->assertSee('data-map-document-position', false)
            ->assertSee('aria-labelledby="canovia-plan-dashboard-title"', false)
            ->assertSee('id="canovia-plan-dashboard-title"', false)
            ->assertSee('data-plan-summary-metrics', false)
            ->assertSee('data-roadmap-fixed-view="map"', false)
            ->assertSee('data-roadmap-spatial-mode="dashboard-overview"', false)
            ->assertSee('data-roadmap-spatial-auto-center="0"', false)
            ->assertSee('data-roadmap-spatial-map', false)
            ->assertSee('data-roadmap-region-focus', false)
            ->assertSee('data-roadmap-region-type="phase"', false)
            ->assertSee('data-roadmap-region-type="cluster"', false)
            ->assertSee('data-roadmap-task-detail-open', false)
            ->assertSee('data-map-surface-template="roadmap-task:'.$task->id.'"', false)
            ->assertSee('data-map-presentation-kind="leaf"', false)
            ->assertDontSee('data-roadmap-view-panel="list"', false)
            ->assertSee('Roadmap')
            ->assertSee($task->title)
            ->assertSee('Classic Plan')
            ->assertSee('実行Mapへ')
            ->assertSee('← Plan一覧');

        $graph = $response->viewData('graph');

        $this->assertTrue((bool) ($graph['plan_workspace_mode'] ?? false));
        $this->assertNotEmpty(data_get($graph, 'plan_workspace.roadmap_spatial.nodes'));
        $this->assertNotEmpty(data_get($graph, 'plan_workspace.roadmap_spatial.projection_key'));
        $this->assertSame('plan:'.$plan->id, $graph['center_node_id']);
        $this->assertCount(1, $graph['nodes']);
        $this->assertSame($plan->id, data_get($graph, 'hierarchy.plan_id'));
        $this->assertSame(
            route('map.index', [
                'level' => 'l1',
                'intent' => 'plan',
            ]),
            data_get($graph, 'hierarchy.parent_url'),
        );
        $this->assertSame(
            route('plans.show', $plan),
            data_get(
                $graph['nodes']->firstWhere('id', 'plan:'.$plan->id),
                'classic_surface.actions.1.url',
            ),
        );
    }

    public function test_plan_workspace_exposes_authoritative_parent_route_for_zoom_out(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '戻れるPlan', '資格学習', 1);
        $this->task($plan, '縮小で戻る');

        $parent = route('map.index', [
            'level' => 'l1',
            'intent' => 'plan',
        ]);

        $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l2',
                'intent' => 'plan',
                'plan' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-map-parent-url="'.e($parent).'"', false);
    }

    public function test_execution_intent_places_plans_directly_and_returns_there_from_execution(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $dev = $this->plan($user, '開発Plan', '個人開発', 1);
        $study = $this->plan($user, '学習Plan', '資格学習', 2);
        $this->task($dev, '開発Task');
        $this->task($study, '学習Task');

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'execution',
        ]));

        $response
            ->assertOk()
            ->assertSee('L1 · EXECUTION PLANS')
            ->assertSee($dev->title)
            ->assertSee($study->title);

        $graph = $response->viewData('graph');
        $nodeIds = $graph['nodes']->pluck('id')->all();

        $this->assertSame('hierarchy:intent:execution', $graph['center_node_id']);
        $this->assertContains('plan:'.$dev->id, $nodeIds);
        $this->assertContains('plan:'.$study->id, $nodeIds);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));

        $devNode = $graph['nodes']->firstWhere('id', 'plan:'.$dev->id);
        $executionUrl = route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $dev->id,
        ]);
        $this->assertSame($executionUrl, data_get($devNode, 'direct_navigation.url'));

        $execution = $this->actingAs($user)->get($executionUrl)->assertOk()->viewData('graph');
        $this->assertSame(2, data_get($execution, 'hierarchy.depth'));
        $this->assertSame(
            route('map.index', ['level' => 'l1', 'intent' => 'execution']),
            data_get($execution, 'hierarchy.parent_url'),
        );
    }

    private function plan(User $user, string $title, string $category, int $priority): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の説明',
            'category' => $category,
            'priority' => $priority,
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
