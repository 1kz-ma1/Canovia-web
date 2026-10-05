<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HomeSurfacePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CanoviaHomeSurfacePreferenceV433Test extends TestCase
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

    public function test_legacy_home_surface_preference_is_inert_and_settings_no_longer_expose_it(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie(
                HomeSurfacePreference::COOKIE,
                HomeSurfacePreference::MAP,
            )
            ->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-canovia-surface="home"', false)
            ->assertDontSee('data-canovia-surface-nav', false)
            ->assertDontSee('data-canovia-surface="explore"', false)
            ->assertDontSee('>Explore<', false)
            ->assertDontSee('data-home-surface-preference-options', false)
            ->assertDontSee('data-home-surface-preference="classic"', false)
            ->assertDontSee('data-home-surface-preference="map"', false)
            ->assertDontSee('ホームの既定表示')
            ->assertDontSee('試験中 · Context Map');
    }

    public function test_legacy_map_cookie_cannot_change_login_or_authenticated_guest_entry(): void
    {
        $user = User::factory()->create([
            'email' => 'map-home@example.com',
            'password' => Hash::make('password123'),
            'first_run_completed_at' => now(),
        ]);

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

        $this->actingAs($user)
            ->withUnencryptedCookie(
                HomeSurfacePreference::COOKIE,
                HomeSurfacePreference::MAP,
            )
            ->get(route('auth.login.form'))
            ->assertRedirect(route('home'));
    }

    public function test_map_remains_available_only_as_an_explicit_explore_route(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withUnencryptedCookie(
                HomeSurfacePreference::COOKIE,
                HomeSurfacePreference::CLASSIC,
            )
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-canovia-map-page', false)
            ->assertSee('data-canovia-surface="explore"', false)
            ->assertSee('Canovia Explore')
            ->assertSee('実行Contextを探索');
    }

    public function test_unfinished_first_run_account_keeps_safe_home_entry_even_with_legacy_map_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'unfinished-map@example.com',
            'password' => Hash::make('password123'),
            'first_run_completed_at' => null,
        ]);

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

        $this->get(route('home'))
            ->assertRedirect(route('first_run.show'));
    }

    public function test_legacy_service_always_resolves_canonical_home(): void
    {
        $request = request();
        $service = app(HomeSurfacePreference::class);

        $this->assertSame(
            HomeSurfacePreference::CLASSIC,
            $service->value($request),
        );
        $this->assertSame(route('home'), $service->url($request));
    }
}
