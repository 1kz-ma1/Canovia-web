<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\RoadmapService;
use App\Services\RoadmapSpatialProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoadmapSpatialMapV480Test extends TestCase
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

    public function test_dependency_depth_and_shared_prerequisites_create_spatial_phases_and_parallel_clusters(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user, 'Spatial Roadmap');

        $rootA = $this->task($plan, 'A: 基盤', 1, 'doing', 35, priority: 1);
        $rootB = $this->task($plan, 'B: 調査', 2, 'todo', 0, priority: 2);

        $childC = $this->task($plan, 'C: UI', 3, 'todo', 0, priority: 1, dependsOn: $rootA);
        $childD = $this->task($plan, 'D: 評価', 4, 'todo', 0, priority: 1, dependsOn: $rootA);

        $final = $this->task($plan, 'E: 統合', 5, 'todo', 0, priority: 1);
        $final->prerequisites()->sync([$childC->id, $childD->id]);

        $roadmap = app(RoadmapService::class)->build(
            $plan->fresh('tasks'),
            $rootA->id,
        );
        $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);

        $this->assertCount(3, $spatial['phases']);
        $this->assertSame($rootA->id, $spatial['current_task_id']);
        $this->assertGreaterThanOrEqual(2, $spatial['parallel_cluster_count']);

        $nodes = collect($spatial['nodes'])->keyBy('task_id');
        $this->assertSame(0, $nodes[$rootA->id]['dependency_depth']);
        $this->assertSame(0, $nodes[$rootB->id]['dependency_depth']);
        $this->assertSame($nodes[$rootA->id]['cluster_id'], $nodes[$rootB->id]['cluster_id']);
        $this->assertSame($nodes[$rootA->id]['x'], $nodes[$rootB->id]['x']);
        $this->assertNotSame($nodes[$rootA->id]['y'], $nodes[$rootB->id]['y']);

        $this->assertSame(1, $nodes[$childC->id]['dependency_depth']);
        $this->assertSame('blocked', $nodes[$childC->id]['dependency_state']);
        $this->assertSame([$rootA->id], $nodes[$childC->id]['blocker_task_ids']);
        $this->assertSame(1, $nodes[$childD->id]['dependency_depth']);
        $this->assertSame('blocked', $nodes[$childD->id]['dependency_state']);
        $this->assertSame($nodes[$childC->id]['cluster_id'], $nodes[$childD->id]['cluster_id']);
        $this->assertGreaterThan($nodes[$rootA->id]['x'], $nodes[$childC->id]['x']);

        $this->assertSame(2, $nodes[$final->id]['dependency_depth']);
        $this->assertGreaterThan($nodes[$childC->id]['x'], $nodes[$final->id]['x']);

        $dependencyPairs = collect($spatial['edges'])
            ->where('relation', 'dependency')
            ->map(fn (array $edge) => $edge['source'].'>'.$edge['target'])
            ->all();

        $this->assertContains('task:'.$rootA->id.'>task:'.$childC->id, $dependencyPairs);
        $this->assertContains('task:'.$rootA->id.'>task:'.$childD->id, $dependencyPairs);
        $this->assertContains('task:'.$childC->id.'>task:'.$final->id, $dependencyPairs);
        $this->assertContains('task:'.$childD->id.'>task:'.$final->id, $dependencyPairs);

        $rootA->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $afterRoadmap = app(RoadmapService::class)->build($plan->fresh('tasks'));
        $afterSpatial = app(RoadmapSpatialProjectionService::class)->build($afterRoadmap);
        $afterNodes = collect($afterSpatial['nodes'])->keyBy('task_id');

        $this->assertSame('ready', $afterNodes[$childC->id]['dependency_state']);
        $this->assertSame([], $afterNodes[$childC->id]['blocker_task_ids']);
        $this->assertSame('ready', $afterNodes[$childD->id]['dependency_state']);
    }

    public function test_roadmap_page_uses_spatial_map_as_primary_and_keeps_list_as_secondary_view(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user, 'Roadmap UI');

        $root = $this->task($plan, '並行作業の起点', 1, 'doing', 20);
        $blocked = $this->task($plan, '後続Task', 2, 'todo', 0, dependsOn: $root);

        $response = $this->actingAs($user)->get(route('roadmap.index', [
            'plan_id' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-roadmap-renderer-active="spatial_map"', false)
            ->assertSee('data-roadmap-spatial-map', false)
            ->assertSee('data-roadmap-spatial-node', false)
            ->assertSee('data-roadmap-edge-relation="dependency"', false)
            ->assertSee('data-roadmap-view-panel="list"', false)
            ->assertSee('MapでPlanを見る')
            ->assertSee('一覧で見る')
            ->assertSee('並行作業の起点')
            ->assertSee('後続Task');

        $this->assertIsArray($response->viewData('roadmapSpatial'));
        $this->assertNotEmpty(data_get($response->viewData('roadmapSpatial'), 'nodes'));

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);

        $blockedForms = $xpath->query(
            '//*[@data-roadmap-task-id="'.$blocked->id.'"]//form[@data-work-start-form]'
        );
        $this->assertSame(0, $blockedForms->length);

        $blockedExecutionLinks = $xpath->query(
            '//*[@data-roadmap-task-id="'.$blocked->id.'"]//a[contains(normalize-space(.),"今やることを見る")]'
        );
        $this->assertSame(1, $blockedExecutionLinks->length);
    }

    public function test_dependency_cycle_degrades_to_a_bounded_spatial_layout(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user, 'Cycle safe');

        $left = $this->task($plan, '循環A', 1, 'todo', 0);
        $right = $this->task($plan, '循環B', 2, 'todo', 0, dependsOn: $left);
        $left->update(['depends_on_task_id' => $right->id]);

        $roadmap = app(RoadmapService::class)->build($plan->fresh('tasks'));
        $spatial = app(RoadmapSpatialProjectionService::class)->build($roadmap);

        $this->assertCount(2, $spatial['nodes']);
        $this->assertNotEmpty($spatial['phases']);

        foreach ($spatial['nodes'] as $node) {
            $this->assertGreaterThanOrEqual(0, $node['x']);
            $this->assertLessThanOrEqual($spatial['width'], $node['x']);
            $this->assertGreaterThanOrEqual(0, $node['y']);
            $this->assertLessThanOrEqual($spatial['height'], $node['y']);
        }
    }

    public function test_shared_roadmap_partial_keeps_legacy_map_fallback_for_preview_callers(): void
    {
        $source = file_get_contents(resource_path('views/plans/partials/roadmap.blade.php'));

        $this->assertStringContainsString("is_array(\$roadmapSpatial)", $source);
        $this->assertStringContainsString("plans.partials.roadmap-spatial-map", $source);
        $this->assertStringContainsString("plans.partials.roadmap-map", $source);
    }

    public function test_mobile_plan_swipe_does_not_capture_spatial_roadmap_gestures(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            '[data-roadmap-plan-tabs], [data-roadmap-spatial-scroll]',
            $script,
        );
        $this->assertStringContainsString(
            "[data-roadmap-spatial-node][data-roadmap-current=\"1\"]",
            $script,
        );
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Dependency Spatial Roadmap',
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
        int $sortOrder,
        string $status,
        int $progress,
        int $priority = 1,
        ?Task $dependsOn = null,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $dependsOn?->id,
            'title' => $title,
            'description' => 'Spatial projection用Task',
            'estimated_minutes' => 60,
            'remaining_minutes' => $progress >= 100 ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => $priority,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
