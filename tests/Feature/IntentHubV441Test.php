<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\IntentMapAttentionStateService;
use App\Services\IntentNavigationGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntentHubV441Test extends TestCase
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

    public function test_default_map_is_fixed_l0_intent_hub_with_space_station_at_center(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l0"', false)
            ->assertSee('Space Station')
            ->assertSee('計画')
            ->assertSee('実行')
            ->assertSee('振り返り')
            ->assertSee('共同')
            ->assertSee('data-map-node-id="intent:space-station"', false)
            ->assertSee('data-map-is-center="1"', false)
            ->assertSee('class="canovia-map-shell is-intent-hub"', false)
            ->assertSee(route('map.index', ['level' => 'l3']), false);

        $graph = $response->viewData('graph');

        $this->assertSame('l0', $graph['level']);
        $this->assertSame('intent:space-station', $graph['center_node_id']);
        $this->assertNull($graph['primary_node_id']);
        $this->assertFalse($graph['has_primary_action']);
        $this->assertCount(5, $graph['nodes']);
        $this->assertCount(4, $graph['edges']);
    }

    public function test_l0_navigation_graph_exists_before_attention_and_attention_only_projects_visual_state(): void
    {
        $semantic = app(IntentNavigationGraphService::class)->build();
        $station = $semantic['nodes']->firstWhere('id', 'intent:space-station');
        $execution = $semantic['nodes']->firstWhere('id', 'intent:execution');
        $edge = $semantic['edges']->firstWhere('target', 'intent:execution');

        $this->assertIsArray($station);
        $this->assertIsArray($execution);
        $this->assertArrayHasKey('attention_role', $station);
        $this->assertArrayNotHasKey('importance', $station);
        $this->assertArrayNotHasKey('position', $station);
        $this->assertArrayNotHasKey('state', $station);
        $this->assertArrayNotHasKey('strength', $edge);

        $projected = app(IntentMapAttentionStateService::class)->apply(
            $semantic['nodes'],
            $semantic['edges'],
        );

        $station = $projected['nodes']->firstWhere('id', 'intent:space-station');
        $execution = $projected['nodes']->firstWhere('id', 'intent:execution');
        $edge = $projected['edges']->firstWhere('target', 'intent:execution');

        $this->assertSame('intent:space-station', $projected['center_node_id']);
        $this->assertSame(['x' => 50, 'y' => 50], $station['position']);
        $this->assertSame('space-station', $station['position_role']);
        $this->assertSame('hub', $station['state']);
        $this->assertSame('intent-execution', $execution['position_role']);
        $this->assertSame(['x' => 76, 'y' => 23], $execution['position']);
        $this->assertSame(0.68, $edge['strength']);
        $this->assertArrayNotHasKey('attention_role', $station);
        $this->assertArrayNotHasKey('attention_role', $edge);
    }

    public function test_execution_intent_opens_preserved_l3_projection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'L3を残すPlan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Execution Mapを維持する',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)
            ->get(route('map.index', ['level' => 'l3']));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l3"', false)
            ->assertSee($plan->title)
            ->assertSee($task->title)
            ->assertSee('data-map-position-role="now"', false)
            ->assertSee('Canovia全体');

        $graph = $response->viewData('graph');

        $this->assertSame('l3', $graph['level']);
        $this->assertSame('task:'.$task->id, $graph['center_node_id']);
        $this->assertSame('task:'.$task->id, $graph['primary_node_id']);
    }

    public function test_space_station_remains_the_fixed_l0_hub_when_its_surface_evolves(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('Space Station')
            ->assertSee('Inboxをすべて見る')
            ->assertSee(route('inbox.index'), false)
            ->assertSee('data-map-node-id="intent:space-station"', false)
            ->assertSee('data-map-is-center="1"', false);
    }

    public function test_l0_telemetry_accepts_structural_roles_but_discards_arbitrary_content(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $flowId = (string) Str::uuid();

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::MapNodeFocused->value,
                'metadata' => [
                    'flow_id' => $flowId,
                    'surface' => 'web',
                    'device' => 'desktop',
                    'node_type' => 'space_station',
                    'position_role' => 'space-station',
                    'is_primary' => false,
                    'elapsed_ms' => 900,
                    'step_count' => 1,
                    'private_text' => 'この内容は保存しない',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::MapNodeFocused->value)
            ->firstOrFail();

        $this->assertSame('space_station', data_get($event->metadata, 'node_type'));
        $this->assertSame('space-station', data_get($event->metadata, 'position_role'));
        $this->assertArrayNotHasKey('private_text', $event->metadata);
    }
}
