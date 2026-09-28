<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MapNodeVisualGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeVisualGrammarV453Test extends TestCase
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

    public function test_visual_kind_is_derived_only_from_existing_structural_node_fields(): void
    {
        $cases = [
            [['type' => 'space_station'], 'station'],
            [['type' => 'intent'], 'planet'],
            [['type' => 'domain'], 'planet'],
            [['type' => 'intent_context'], 'planet'],
            [['type' => 'plan'], 'moon'],
            [['type' => 'goal'], 'star'],
            [['type' => 'task', 'state' => 'primary', 'position_role' => 'now'], 'rocket'],
            [['type' => 'task', 'state' => 'action', 'position_role' => 'future-task'], 'beacon'],
            [['type' => 'tool'], 'module'],
            [['type' => 'evidence'], 'archive'],
            [['type' => 'inbox'], 'inbox-dock'],
            [['type' => 'satellite_plan'], 'satellite'],
            [['type' => 'satellite_tool'], 'satellite'],
            [['type' => 'collaboration_hub'], 'crew-station'],
            [['type' => 'collaboration_context'], 'crew'],
            [['type' => 'collaboration_item'], 'crew'],
            [['type' => 'unknown'], 'context'],
        ];

        foreach ($cases as [$node, $expected]) {
            $this->assertSame($expected, MapNodeVisualGrammar::kind($node));
        }
    }

    public function test_l0_renders_visual_grammar_without_mutating_projection_nodes(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-node-id="intent:space-station"', false)
            ->assertSee('data-map-visual-kind="station"', false)
            ->assertSee('visual-station', false)
            ->assertSee('data-map-node-glyph-kind="station"', false)
            ->assertSee('data-map-visual-kind="planet"', false)
            ->assertSee('data-map-node-glyph', false)
            ->assertDontSee('title="Space Station"', false)
            ->assertDontSee('title="Current Action Rocket"', false);

        $graph = $response->viewData('graph');
        $station = $graph['nodes']->firstWhere('id', 'intent:space-station');
        $plan = $graph['nodes']->firstWhere('id', 'intent:plan');

        $this->assertSame('space_station', $station['type']);
        $this->assertSame('intent', $plan['type']);
        $this->assertArrayNotHasKey('visual_kind', $station);
        $this->assertArrayNotHasKey('visual_kind', $plan);
        $this->assertArrayNotHasKey('glyph', $station);
        $this->assertArrayNotHasKey('glyph', $plan);
    }

}
