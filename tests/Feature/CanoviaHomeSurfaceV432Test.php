<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanoviaHomeSurfaceV432Test extends TestCase
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

    public function test_home_does_not_expose_map_entry_while_direct_explore_remains_available(): void
    {
        [$user] = $this->scenario();

        $home = $this->actingAs($user)->get(route('home'));
        $home->assertOk()
            ->assertDontSee('data-canovia-surface-nav', false)
            ->assertDontSee('SURFACES')
            ->assertDontSee('>Explore<', false)
            ->assertDontSee('HOME SURFACE')
            ->assertSee('data-canovia-surface="home"', false)
            ->assertDontSee('data-canovia-surface="explore"', false)
            ->assertSee('data-action-home', false)
            ->assertSee('data-canovia-nav-key="desktop-home"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace"', false)
            ->assertSee('data-canovia-nav-key="desktop-timeline"', false)
            ->assertDontSee('data-canovia-nav-key="desktop-inbox"', false)
            ->assertDontSee('data-canovia-nav-key="desktop-map"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-map"', false);

        $map = $this->actingAs($user)->get(route('map.index'));
        $map->assertOk()
            ->assertSee('data-canovia-surface-nav', false)
            ->assertSee('data-map-home-fallback', false)
            ->assertSee('data-canovia-surface="explore"', false)
            ->assertSee('data-canovia-nav-key="desktop-home"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace"', false)
            ->assertSee('data-canovia-nav-key="desktop-timeline"', false)
            ->assertDontSee('data-canovia-nav-key="desktop-map"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-map"', false);
    }

    public function test_explore_instant_fragment_is_not_marked_as_primary_home(): void
    {
        [$user] = $this->scenario();

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get(route('map.index'));

        $response->assertOk()
            ->assertSee('id="canovia-instant-meta"', false)
            ->assertSee('"mobileSection":"Explore"', false)
            ->assertSee('"desktop-home"', false)
            ->assertSee('"desktop-workspace"', false)
            ->assertSee('"desktop-timeline"', false)
            ->assertSee('data-canovia-page', false)
            ->assertSee('data-canovia-companion-slot', false)
            ->assertDontSee('desktop-app-header', false)
            ->assertDontSee('aria-label="Canovia Companionを現在の文脈で開く"', false);
    }

    public function test_instant_navigation_emits_map_lifecycle_hooks_and_preserves_flow_steps(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));
        $living = file_get_contents(resource_path('js/living-map.mjs'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("'/map'", $instant);
        $this->assertStringContainsString('canovia:before-instant-navigation', $instant);
        $this->assertStringContainsString('canovia:before-page-replace', $instant);
        $this->assertStringContainsString('canoviaInstantRendered', $instant);

        $this->assertStringContainsString('function trackInstantMapLink', $living);
        $this->assertStringContainsString("removeEventListener('canovia:before-page-replace'", $living);
        $this->assertStringContainsString('clearMapTelemetryFlow(windowRef)', $living);
        $this->assertStringContainsString("instantRoot?.dataset.canoviaInstantRendered === '1'", $living);

        $this->assertStringContainsString("document.addEventListener('canovia:before-instant-navigation'", $app);
        $this->assertStringContainsString('advanceMapTelemetryForClassicNavigation(link)', $app);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Explore Surfaceを整理する',
            'description' => 'HomeとExploreの責務を分ける',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '入口を一つにする',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan];
    }
}
