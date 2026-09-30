<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\MapLevel;
use App\Models\BehaviorEvent;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\HierarchyMapAttentionStateService;
use App\Services\HierarchyNavigationGraphService;
use App\Services\MapHierarchyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class SemanticZoomSpatialMemoryV443Test extends TestCase
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

    public function test_l0_intents_semantically_zoom_into_l1_instead_of_skipping_hierarchy(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-semantic-zoom', false)
            ->assertSee('data-map-zoom-direction="in"', false)
            ->assertSee(
                route('map.index', ['level' => MapLevel::Domain->value, 'intent' => 'execution']),
            );

        $graph = $response->viewData('graph');
        $execution = $graph['nodes']->firstWhere('id', 'intent:execution');

        $this->assertSame('zoom-in', data_get($execution, 'direct_navigation.kind'));
        $this->assertSame(0, data_get($graph, 'hierarchy.depth'));
        $this->assertNull($graph['spatial_dock']);
    }

    public function test_l1_execution_plan_graph_is_semantic_before_attention_and_keeps_space_station_as_navigation_chrome(): void
    {
        [$user, $devPlan, , $studyPlan] = $this->scenario();

        $request = Request::create('/map?level=l1&intent=execution', 'GET');
        $request->setUserResolver(fn () => $user);

        $context = app(MapHierarchyContextService::class)->resolve($request);
        $semantic = app(HierarchyNavigationGraphService::class)->build(MapLevel::Domain, $context);

        $semanticPlan = $semantic['nodes']->firstWhere('id', 'plan:'.$devPlan->id);
        $semanticEdge = $semantic['edges']->firstWhere('target', 'plan:'.$devPlan->id);

        $this->assertIsArray($semanticPlan);
        $this->assertArrayHasKey('attention_role', $semanticPlan);
        $this->assertArrayNotHasKey('importance', $semanticPlan);
        $this->assertArrayNotHasKey('position', $semanticPlan);
        $this->assertArrayNotHasKey('state', $semanticPlan);
        $this->assertArrayNotHasKey('strength', $semanticEdge);

        $attention = app(HierarchyMapAttentionStateService::class)->apply(
            $semantic['nodes'],
            $semantic['edges'],
            $semantic['center_node_id'],
        );
        $projectedPlan = $attention['nodes']->firstWhere('id', 'plan:'.$devPlan->id);

        $this->assertSame('hierarchy-child', $projectedPlan['position_role']);
        $this->assertArrayHasKey('position', $projectedPlan);
        $this->assertArrayNotHasKey('attention_role', $projectedPlan);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l1',
            'intent' => 'execution',
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l1"', false)
            ->assertSee('data-map-hierarchy-depth="1"', false)
            ->assertSee('data-map-spatial-dock', false)
            ->assertSee('Space Station')
            ->assertSee($devPlan->title)
            ->assertSee($studyPlan->title);

        $graph = $response->viewData('graph');
        $this->assertSame('hierarchy:intent:execution', $graph['center_node_id']);
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['type'] ?? null) === 'domain'
        ));
        $this->assertSame('bottom-right', data_get($graph, 'spatial_dock.position'));
        $this->assertSame(1, data_get($graph, 'hierarchy.depth'));
    }

    public function test_execution_l2_projects_only_selected_domain_plans_as_graph_children(): void
    {
        [$user, $devPlan, $devTask, $studyPlan] = $this->scenario();
        $hierarchy = app(MapHierarchyContextService::class);
        $domainKey = $hierarchy->domainKey($devPlan->category);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'execution',
            'domain' => $domainKey,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l2"', false)
            ->assertSee('data-map-hierarchy-depth="2"', false)
            ->assertSee('data-map-spatial-dock', false)
            ->assertSee('潜る ↘');

        $graph = $response->viewData('graph');
        $nodeIds = $graph['nodes']->pluck('id')->all();

        $this->assertContains('domain:'.$domainKey, $nodeIds);
        $this->assertContains('plan:'.$devPlan->id, $nodeIds);
        $this->assertNotContains('plan:'.$studyPlan->id, $nodeIds);
        $this->assertSame(2, data_get($graph, 'hierarchy.depth'));

        $planNode = $graph['nodes']->firstWhere('id', 'plan:'.$devPlan->id);
        $this->assertSame('zoom-in', data_get($planNode, 'direct_navigation.kind'));
        $this->assertStringContainsString('level=l3', data_get($planNode, 'direct_navigation.url'));
        $this->assertStringContainsString('plan='.$devPlan->id, data_get($planNode, 'direct_navigation.url'));

        $this->assertSame($devPlan->id, $devTask->plan_id);
    }

    public function test_l3_semantic_zoom_stays_scoped_to_the_selected_plan_instead_of_global_guidance(): void
    {
        [$user, $preferredPlan, $preferredTask, $selectedPlan, $selectedTask] = $this->twoPlanExecutionScenario();
        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $selectedPlan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l3"', false)
            ->assertSee('data-map-hierarchy-depth="2"', false)
            ->assertSee('data-map-spatial-dock', false)
            ->assertSee($selectedPlan->title)
            ->assertSee($selectedTask->title);

        $graph = $response->viewData('graph');

        $this->assertSame('task:'.$selectedTask->id, $graph['primary_node_id']);
        $this->assertSame($selectedPlan->id, data_get($graph, 'hierarchy.plan_id'));
        $this->assertSame($selectedPlan->title, data_get($graph, 'hierarchy.current_label'));
        $this->assertSame(
            route('map.index', ['level' => 'l1', 'intent' => 'execution']),
            data_get($graph, 'hierarchy.parent_url'),
        );
        $this->assertFalse($graph['nodes']->contains(
            fn (array $node) => ($node['id'] ?? null) === 'task:'.$preferredTask->id
        ));
        $this->assertNotSame($preferredPlan->id, data_get($graph, 'hierarchy.plan_id'));
    }

    public function test_space_station_dock_capture_returns_to_the_same_l3_hierarchy_context(): void
    {
        [$user, $plan] = $this->scenario();
        $hierarchy = app(MapHierarchyContextService::class);
        $domainKey = $hierarchy->domainKey($plan->category);

        $mapParams = [
            'level' => 'l3',
            'intent' => 'execution',
            'domain' => $domainKey,
            'plan' => $plan->id,
        ];

        $page = $this->actingAs($user)->get(route('map.index', $mapParams));

        $page
            ->assertOk()
            ->assertSee('name="return_to" value="map_station"', false)
            ->assertSee('name="map_level" value="l3"', false)
            ->assertSee('name="map_intent" value="execution"', false)
            ->assertSee('name="map_domain" value="'.$domainKey.'"', false)
            ->assertSee('name="map_plan" value="'.$plan->id.'"', false)
            ->assertSee('data-map-global-surface-template="space-station"', false);

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'content' => 'L3の作業中に思いついた入力',
                'return_to' => 'map_station',
                'map_level' => 'l3',
                'map_intent' => 'execution',
                'map_domain' => $domainKey,
                'map_plan' => $plan->id,
            ])
            ->assertRedirect(route('map.index', $mapParams).'#dock=space-station');

        $item = InboxItem::query()->latest('id')->firstOrFail();
        $this->assertSame('new', $item->status);
        $this->assertSame('L3の作業中に思いついた入力', $item->content);
    }

    public function test_hierarchy_zoom_telemetry_keeps_only_structural_metadata(): void
    {
        [$user] = $this->scenario();
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapClassicActionOpened->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'domain',
                    'position_role' => 'hierarchy-child',
                    'action_role' => 'zoom',
                    'is_primary' => false,
                    'elapsed_ms' => 1000,
                    'step_count' => 1,
                    'private_text' => 'Domain名や入力本文は保存しない',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapClassicActionOpened->value)
            ->firstOrFail();

        $this->assertSame('domain', data_get($event->metadata, 'node_type'));
        $this->assertSame('hierarchy-child', data_get($event->metadata, 'position_role'));
        $this->assertSame('zoom', data_get($event->metadata, 'action_role'));
        $this->assertArrayNotHasKey('private_text', $event->metadata);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $devPlan = $this->plan($user, 'Canoviaを改善する', '個人開発', 1);
        $devTask = $this->task($devPlan, 'Semantic Zoomを実装する', 1);
        $studyPlan = $this->plan($user, 'APに合格する', '資格学習', 2);
        $studyTask = $this->task($studyPlan, '午後問題を解く', 1);

        return [$user, $devPlan, $devTask, $studyPlan, $studyTask];
    }

    private function twoPlanExecutionScenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $preferredPlan = $this->plan($user, '優先度が高いPlan', '個人開発', 1);
        $preferredTask = $this->task($preferredPlan, '本来おすすめされるTask', 1);

        $selectedPlan = $this->plan($user, 'ユーザーが選んだPlan', '資格学習', 5);
        $selectedTask = $this->task($selectedPlan, '選択PlanのTask', 3);

        return [$user, $preferredPlan, $preferredTask, $selectedPlan, $selectedTask];
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
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title, int $priority): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 10,
            'status' => 'doing',
            'priority' => $priority,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
