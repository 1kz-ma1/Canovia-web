<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileMapGestureUxV451Test extends TestCase
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

    public function test_map_renders_transformable_scene_and_non_persistent_view_controls(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-scene', false)
            ->assertSee('data-map-gesture-controls', false)
            ->assertSee('data-map-zoom-out', false)
            ->assertSee('data-map-view-reset', false)
            ->assertSee('data-map-zoom-in', false)
            ->assertSee('aria-label="Map表示を中央へ戻す"', false);
    }

    public function test_context_surface_keeps_explicit_close_and_expand_controls_for_touch_selection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-context-close', false)
            ->assertSee('aria-label="詳細を閉じる"', false)
            ->assertSee('data-map-context-expand', false)
            ->assertSee('aria-label="詳細パレットの表示サイズを切り替える"', false);
    }

    public function test_mobile_gesture_layer_does_not_change_l0_semantic_projection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $graph = $this->actingAs($user)
            ->get(route('map.index'))
            ->viewData('graph');

        $this->assertSame('l0', $graph['level']);
        $this->assertSame('intent:space-station', $graph['center_node_id']);
        $this->assertNull($graph['primary_node_id']);

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
    }
}
