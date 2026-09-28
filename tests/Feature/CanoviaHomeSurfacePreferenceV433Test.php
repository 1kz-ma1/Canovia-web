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

    public function test_default_is_classic_and_settings_expose_an_explicit_opt_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-surface="classic"', false)
            ->assertSee('data-home-surface-preference-options', false)
            ->assertSee('data-home-surface-preference="classic"', false)
            ->assertSee('data-home-surface-preference="map"', false)
            ->assertSee('標準 · 安定版')
            ->assertSee('試験中 · Context Map')
            ->assertSee('href="'.route('home').'" data-preferred-home-link', false);
    }

    public function test_map_preference_changes_home_entries_without_changing_explicit_routes(): void
    {
        $user = User::factory()->create();

        $classic = $this->actingAs($user)
            ->withUnencryptedCookie(HomeSurfacePreference::COOKIE, HomeSurfacePreference::MAP)
            ->get(route('home'));

        $classic->assertOk()
            ->assertSee('id="behaviorDashboard"', false)
            ->assertSee('data-home-surface="map"', false)
            ->assertSee('href="'.route('map.index').'" data-preferred-home-link', false)
            ->assertSee('data-home-surface="classic"', false);

        $this->actingAs($user)
            ->withUnencryptedCookie(HomeSurfacePreference::COOKIE, HomeSurfacePreference::CLASSIC)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-canovia-map-page', false)
            ->assertSee('data-home-surface="map"', false);
    }

    public function test_existing_account_login_and_guest_middleware_honor_map_preference(): void
    {
        $user = User::factory()->create([
            'email' => 'map-home@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->withUnencryptedCookie(HomeSurfacePreference::COOKIE, HomeSurfacePreference::MAP)
            ->post(route('auth.login'), [
                'email' => $user->email,
                'password' => 'password123',
                'remember' => '1',
            ])
            ->assertRedirect(route('map.index'));

        $this->post(route('auth.logout'))->assertRedirect(route('home'));

        $this->actingAs($user)
            ->withUnencryptedCookie(HomeSurfacePreference::COOKIE, HomeSurfacePreference::MAP)
            ->get(route('auth.login.form'))
            ->assertRedirect(route('map.index'));
    }

    public function test_unfinished_first_run_account_keeps_safe_classic_login_entry_even_with_map_preference(): void
    {
        $user = User::factory()->create([
            'email' => 'unfinished-map@example.com',
            'password' => Hash::make('password123'),
            'first_run_completed_at' => null,
        ]);

        $this->withUnencryptedCookie(HomeSurfacePreference::COOKIE, HomeSurfacePreference::MAP)
            ->post(route('auth.login'), [
                'email' => $user->email,
                'password' => 'password123',
                'remember' => '1',
            ])
            ->assertRedirect(route('home'));

        $this->get(route('home'))
            ->assertRedirect(route('first_run.show'));
    }

    public function test_invalid_cookie_fails_closed_to_classic(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withUnencryptedCookie(HomeSurfacePreference::COOKIE, 'something-else')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-surface="classic"', false)
            ->assertSee('href="'.route('home').'" data-preferred-home-link', false);
    }
}
