<?php

namespace Tests\Feature;

use App\Enums\MapLevel;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionNavigationGraphService;
use App\Services\MapAttentionStateService;
use App\Services\MapProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HierarchicalMapFoundationV440Test extends TestCase
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

    public function test_map_level_contract_reserves_l0_through_l3(): void
    {
        $this->assertSame(
            ['l0', 'l1', 'l2', 'l3'],
            array_map(fn (MapLevel $level) => $level->value, MapLevel::cases()),
        );
    }

    public function test_execution_navigation_graph_is_semantic_until_attention_is_applied(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V44 Navigation Graph',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $current = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Current execution',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $next = Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $current->id,
            'title' => 'Next execution',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        $plan = Plan::query()
            ->with(['tasks', 'goalContext'])
            ->findOrFail($plan->id);
        $current = $plan->tasks->firstWhere('id', $current->id);
        $next = $plan->tasks->firstWhere('id', $next->id);

        $context = [
            'plan' => $plan,
            'current_task' => $current,
            'primary_tool' => null,
            'next_task' => $next,
            'pending_inbox_count' => 0,
            'latest_inbox' => null,
        ];

        $semantic = app(ExecutionNavigationGraphService::class)->build($context);
        $semanticPrimary = $semantic['nodes']->firstWhere('id', 'task:'.$current->id);
        $semanticEdge = $semantic['edges']->firstWhere('relation', 'current_action');

        $this->assertIsArray($semanticPrimary);
        $this->assertArrayHasKey('attention_role', $semanticPrimary);
        $this->assertArrayNotHasKey('importance', $semanticPrimary);
        $this->assertArrayNotHasKey('position', $semanticPrimary);
        $this->assertArrayNotHasKey('state', $semanticPrimary);
        $this->assertIsArray($semanticEdge);
        $this->assertArrayNotHasKey('strength', $semanticEdge);

        $attention = app(MapAttentionStateService::class)->apply(
            $semantic['nodes'],
            $semantic['edges'],
            $context,
        );
        $projectedPrimary = $attention['nodes']->firstWhere('id', 'task:'.$current->id);
        $projectedEdge = $attention['edges']->firstWhere('relation', 'current_action');

        $this->assertSame('task:'.$current->id, $attention['primary_node_id']);
        $this->assertSame(1.0, $projectedPrimary['importance']);
        $this->assertSame('primary', $projectedPrimary['state']);
        $this->assertSame('now', $projectedPrimary['position_role']);
        $this->assertSame(['x' => 50, 'y' => 50], $projectedPrimary['position']);
        $this->assertArrayNotHasKey('attention_role', $projectedPrimary);
        $this->assertSame(1.0, $projectedEdge['strength']);
        $this->assertArrayNotHasKey('attention_role', $projectedEdge);
    }

    public function test_map_route_keeps_v43_execution_projection_contract(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V43 compatible Plan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'V43 compatible Task',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('map.index', ['level' => 'l3']));

        $response
            ->assertOk()
            ->assertSee('V43 compatible Plan')
            ->assertSee('V43 compatible Task')
            ->assertSee('data-map-position-role="now"', false)
            ->assertSee('data-map-context-surface', false);

        $graph = $response->viewData('graph');
        $primary = $graph['nodes']->firstWhere('id', 'task:'.$task->id);

        $this->assertSame('task:'.$task->id, $graph['primary_node_id']);
        $this->assertTrue($graph['has_primary_action']);
        $this->assertNotEmpty($graph['projection_key']);
        $this->assertSame(1.0, $primary['importance']);
        $this->assertSame(['x' => 50, 'y' => 50], $primary['position']);
        $this->assertArrayNotHasKey('attention_role', $primary);
    }

    public function test_reserved_l1_and_l2_levels_are_now_projected_without_changing_level_contract(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Hierarchy implementation Plan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        $l1 = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => 'plan',
        ]));
        $l1->assertOk()
            ->assertSee('data-map-level="l1"', false)
            ->assertSee('個人開発');

        $domainKey = app(\App\Services\MapHierarchyContextService::class)->domainKey($plan->category);

        $l2 = $this->actingAs($user)->get(route('map.index', [
            'level' => MapLevel::Plan->value,
            'intent' => 'plan',
            'domain' => $domainKey,
        ]));
        $l2->assertOk()
            ->assertSee('data-map-level="l2"', false)
            ->assertSee($plan->title);
    }
}
