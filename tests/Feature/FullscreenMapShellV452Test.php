<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FullscreenMapShellV452Test extends TestCase
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

    public function test_map_places_home_explore_surface_navigation_inside_fullscreen_top_bar_once(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-fullscreen-topbar', false)
            ->assertSee('data-canovia-surface-nav', false)
            ->assertSee('data-canovia-surface="home"', false)
            ->assertSee('data-canovia-surface="explore"', false)
            ->assertSee('data-map-hierarchy-path', false);

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-canovia-surface-nav'));
        $this->assertLessThan(
            strpos($html, 'data-canovia-surface-nav'),
            strpos($html, 'data-map-fullscreen-topbar'),
        );
        $this->assertLessThan(
            strpos($html, 'data-map-hierarchy-path'),
            strpos($html, 'data-map-fullscreen-topbar'),
        );
    }

    public function test_map_route_keeps_app_shell_dom_for_instant_return_but_css_contract_hides_it_in_map_mode(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-route-name="map.index"', false)
            ->assertSee('desktop-app-header', false)
            ->assertSee('mobile-app-header', false)
            ->assertSee('mobile-tabbar', false);

        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('body[data-route-name="map.index"] .desktop-app-header', $css);
        $this->assertStringContainsString('body[data-route-name="map.index"] .mobile-app-header', $css);
        $this->assertStringContainsString('body[data-route-name="map.index"] .mobile-tabbar', $css);
        $this->assertStringContainsString('height: 100dvh;', $css);
        $this->assertStringContainsString('overflow: hidden;', $css);
    }

    public function test_fullscreen_map_workspace_fills_remaining_viewport_and_context_overlays_it(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'body[data-route-name="map.index"] .canovia-map-page',
            $css,
        );
        $this->assertStringContainsString(
            'grid-template-rows: auto minmax(0, 1fr);',
            $css,
        );
        $this->assertStringContainsString(
            'body[data-route-name="map.index"] .canovia-map-workspace.is-context-open',
            $css,
        );
        $this->assertStringContainsString(
            'body[data-route-name="map.index"] .canovia-map-context-surface',
            $css,
        );
    }

    public function test_instant_map_fragment_contains_fullscreen_top_bar_and_route_name_for_shell_sync(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('id="canovia-instant-meta"', false)
            ->assertSee('data-map-fullscreen-topbar', false)
            ->assertSee('data-canovia-surface-nav', false)
            ->assertSee('<body data-route-name="map.index">', false);

        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));
        $this->assertStringContainsString(
            "documentRef.body.dataset.routeName = payload.routeName || ''",
            $instant,
        );
    }

    public function test_classic_home_remains_normal_scrollable_app_surface(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-route-name="home"', false)
            ->assertSee('desktop-app-header', false)
            ->assertSee('mobile-app-header', false)
            ->assertSee('mobile-tabbar', false)
            ->assertDontSee('data-map-fullscreen-topbar', false);
    }
}
