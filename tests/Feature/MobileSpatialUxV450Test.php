<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileSpatialUxV450Test extends TestCase
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

    public function test_server_projection_keeps_canonical_l0_attention_coordinates_for_client_mobile_reprojection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-level="l0"', false)
            ->assertSee('data-map-position-role="intent-plan"', false)
            ->assertSee('data-map-x="24"', false)
            ->assertSee('data-map-y="23"', false)
            ->assertSee('data-map-position-role="intent-execution"', false)
            ->assertSee('data-map-x="76"', false);

        $graph = $response->viewData('graph');
        $planNode = $graph['nodes']->firstWhere('id', 'intent:plan');
        $executionNode = $graph['nodes']->firstWhere('id', 'intent:execution');

        $this->assertSame(['x' => 24, 'y' => 23], $planNode['position']);
        $this->assertSame(['x' => 76, 'y' => 23], $executionNode['position']);
        $this->assertSame('intent:space-station', $graph['center_node_id']);
    }

    public function test_mobile_projection_does_not_change_map_semantic_contract(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $fixedIds = $graph['nodes']
            ->filter(fn (array $node) => ($node['type'] ?? null) === 'intent' || ($node['type'] ?? null) === 'space_station')
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
        $this->assertNull($graph['primary_node_id']);
        $this->assertFalse($graph['has_primary_action']);
    }
}
