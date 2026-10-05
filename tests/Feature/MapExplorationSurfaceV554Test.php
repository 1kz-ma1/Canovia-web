<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HomeSurfacePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MapExplorationSurfaceV554Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_home_is_now_and_map_is_explicit_explore_surface(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-canovia-surface="home"', false)
            ->assertDontSee('data-canovia-surface-nav', false)
            ->assertSee('data-action-home', false)
            ->assertDontSee('>Explore<', false)
            ->assertDontSee('HOME SURFACE')
            ->assertDontSee('>Classic<', false);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-canovia-surface="explore"', false)
            ->assertSee('data-canovia-map-page', false)
            ->assertSee('Canovia Explore')
            ->assertSee('L0 · CANOVIA EXPLORE')
            ->assertSee('全体像・関係性・過去・共同・実行Context')
            ->assertSee('実行Contextを探索')
            ->assertDontSee('実行へ潜る');
    }

    public function test_legacy_map_preference_is_inert_for_service_and_authentication_entry(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy-map@example.com',
            'password' => Hash::make('password123'),
            'first_run_completed_at' => now(),
        ]);

        $service = app(HomeSurfacePreference::class);

        $this->assertSame(
            HomeSurfacePreference::CLASSIC,
            $service->value(request()),
        );
        $this->assertSame(route('home'), $service->url(request()));

        $this->withUnencryptedCookie(
            HomeSurfacePreference::COOKIE,
            HomeSurfacePreference::MAP,
        )
            ->post(route('auth.login'), [
                'email' => $user->email,
                'password' => 'password123',
                'remember' => '1',
            ])
            ->assertRedirect(route('home'));
    }

    public function test_settings_no_longer_ask_user_to_choose_a_home_surface(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('ホームの既定表示')
            ->assertDontSee('data-home-surface-preference-options', false)
            ->assertDontSee('data-home-surface-preference=', false)
            ->assertDontSee('試験中 · Context Map');
    }

    public function test_product_runtime_no_longer_mounts_legacy_home_preference_logic(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString(
            "from './home-surface-preference.mjs'",
            $app,
        );
        $this->assertStringNotContainsString(
            'resolveHomeSurface',
            $app,
        );
        $this->assertStringNotContainsString(
            'persistHomeSurface',
            $app,
        );
        $this->assertStringNotContainsString(
            '[data-home-surface-preference]',
            $app,
        );
        $this->assertStringNotContainsString(
            '[data-preferred-home-link]',
            $app,
        );
    }

    public function test_non_map_app_pages_are_not_mislabeled_as_home_surface(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('timeline.index'))
            ->assertOk()
            ->assertSee('data-canovia-surface="app"', false)
            ->assertDontSee('data-canovia-surface="explore"', false);
    }
}
