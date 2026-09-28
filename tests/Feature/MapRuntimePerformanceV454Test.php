<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapRuntimePerformanceV454Test extends TestCase
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

    public function test_runtime_optimization_state_never_enters_server_projection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-scene', false)
            ->assertSee('data-map-gesture-controls', false);

        $graph = $response->viewData('graph');

        foreach ($graph['nodes'] as $node) {
            $this->assertArrayNotHasKey('renderedBasePosition', $node);
            $this->assertArrayNotHasKey('renderedGeometry', $node);
            $this->assertArrayNotHasKey('viewportCache', $node);
            $this->assertArrayNotHasKey('mapView', $node);
            $this->assertArrayNotHasKey('visual_kind', $node);
        }

        foreach ($graph['edges'] as $edge) {
            $this->assertArrayNotHasKey('renderedVisibility', $edge);
            $this->assertArrayNotHasKey('renderedGeometry', $edge);
        }
    }

    public function test_runtime_optimization_preserves_fixed_l0_semantics(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $fixedIds = $graph['nodes']
            ->filter(fn (array $node) => in_array(
                $node['id'] ?? null,
                [
                    'intent:space-station',
                    'intent:plan',
                    'intent:execution',
                    'intent:reflection',
                    'intent:collaboration',
                ],
                true,
            ))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'intent:collaboration',
            'intent:execution',
            'intent:plan',
            'intent:reflection',
            'intent:space-station',
        ], $fixedIds);

        $this->assertSame('intent:space-station', $graph['center_node_id']);
        $this->assertNull($graph['primary_node_id']);
        $this->assertFalse($graph['has_primary_action']);
    }
}
