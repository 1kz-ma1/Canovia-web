<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileMapUsabilityPolishV455Test extends TestCase
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

    public function test_mobile_map_markup_supports_spatial_loading_and_compact_navigation(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-gesture-controls', false)
            ->assertSee('data-route-lock-skip', false)
            ->assertSee('data-map-semantic-zoom', false)
            ->assertSee('data-canovia-surface-nav', false);
    }

    public function test_space_station_keeps_secondary_capture_inputs_collapsed_by_default(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('canovia-space-station-attachments', false)
            ->assertSee('URL・スクリーンショット・PDFを追加');
    }

    public function test_mobile_polish_css_keeps_map_chrome_subordinate_to_spatial_canvas(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.canovia-map-axis-label {', $css);
        $this->assertStringContainsString('.canovia-map-node-direct-open:not(.is-external-tool)', $css);
        $this->assertStringContainsString('.canovia-map-context-card::before', $css);
        $this->assertStringContainsString('.canovia-map-spatial-dock-copy', $css);
        $this->assertStringContainsString('max-height: min(38dvh, 21rem);', $css);
        $this->assertStringContainsString('.canovia-map-page.is-map-semantic-loading .canovia-map-scene', $css);
    }

    public function test_instant_navigation_uses_spatial_feedback_for_map_to_map_navigation(): void
    {
        $source = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString(
            "const spatial = windowRef.location.pathname === '/map' && url.pathname === '/map';",
            $source,
        );
        $this->assertStringContainsString("page?.classList.add('is-map-semantic-loading')", $source);
        $this->assertStringContainsString('withUncachedFeedback', $source);
    }
}
