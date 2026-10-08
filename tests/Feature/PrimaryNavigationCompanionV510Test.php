<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrimaryNavigationCompanionV510Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'features.flags.'.FeatureKey::CanoviaCompanion->value.'.enabled' => true,
        ]);
    }

    public function test_primary_navigation_is_home_workspace_timeline_and_more(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-canovia-nav-key="desktop-home"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace"', false)
            ->assertSee('data-canovia-nav-key="desktop-timeline"', false)
            ->assertSee('data-canovia-nav-key="desktop-timeline"', false)
            ->assertSee('data-canovia-nav-key="mobile-home"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace"', false)
            ->assertSee('data-canovia-nav-key="mobile-timeline"', false)
            ->assertSee('data-canovia-nav-key="mobile-timeline"', false)
            ->assertDontSee('data-canovia-nav-key="desktop-inbox"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-inbox"', false)
            ->assertSee('星座・全体俯瞰')
            ->assertSee('従来の実行');
    }

    public function test_companion_is_a_floating_palette_and_remains_available_on_legacy_map(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        foreach ([route('home'), route('map.index')] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee('data-canovia-companion-slot', false)
                ->assertSee('data-companion-shell', false)
                ->assertSee('data-companion-palette-open', false)
                ->assertSee('data-companion-palette', false)
                ->assertSee('data-companion-palette-close', false)
                ->assertSee('action="'.route('companion.entry').'"', false)
                ->assertSee('この文脈で相談を始める');
        }
    }

    public function test_instant_navigation_metadata_uses_new_primary_navigation_keys(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get(route('roadmap.index'));

        $response
            ->assertOk()
            ->assertSee('"mobileSection":"星座"', false)
            ->assertSee('"desktop-workspace"', false)
            ->assertSee('"desktop-timeline"', false)
            ->assertSee('"mobile-workspace"', false)
            ->assertSee('"mobile-timeline"', false)
            ->assertDontSee('"desktop-inbox"', false)
            ->assertDontSee('"mobile-inbox"', false);
    }

    public function test_execution_route_is_the_interim_execution_surface(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('navigation.index'))
            ->assertOk()
            ->assertSee('EXECUTION /')
            ->assertSee('実行方法を選ぶ。')
            ->assertSee('data-canovia-more', false)
            ->assertSee(route('navigation.index'), false);
    }

    public function test_client_contract_mounts_companion_palette_after_full_and_instant_navigation(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/companion-palette.css'));
        $mobileNav = file_get_contents(resource_path('views/layouts/partials/mobile-nav.blade.php'));

        $this->assertStringContainsString('function mountCompanionPalette', $app);
        $this->assertGreaterThanOrEqual(2, substr_count($app, 'mountCompanionPalette();'));
        $this->assertStringContainsString("dialog.showModal", $app);
        $this->assertStringContainsString("data.companionPaletteMounted", str_replace('dataset.', 'data.', $app));

        $this->assertStringContainsString('.canovia-companion-orb', $css);
        $this->assertStringContainsString('.canovia-companion-palette', $css);
        $this->assertStringContainsString('pointer-events: auto;', $css);

        $this->assertStringContainsString("route('home')", $mobileNav);
        $this->assertStringContainsString("route('roadmap.index')", $mobileNav);
        $this->assertStringContainsString("route('navigation.index')", $mobileNav);
        $this->assertStringContainsString("route('timeline.index')", $mobileNav);
    }
}
