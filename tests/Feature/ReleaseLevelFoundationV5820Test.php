<?php

namespace Tests\Feature;

use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Services\ReleaseLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseLevelFoundationV5820Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
            'release_levels.public_level' => ReleaseLevel::InternalPreview->value,
        ]);
    }

    public function test_default_level_preserves_current_workspace_visibility(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="4"', false)
            ->assertSee('data-navigation-shell="global"', false);

        $this->actingAs($user)
            ->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-canovia-nav-key="mobile-workspace-career"', false);
    }

    public function test_public_level_two_hides_career_and_blocks_direct_workspace_entry(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="2"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-career"', false);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'career',
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('workspace.career.index'))
            ->assertRedirect(route('workspace.overview.index'))
            ->assertSessionHas(
                'status',
                'この機能は現在の公開レベルでは利用できません。Beta公開後に利用できます。',
            );
    }

    public function test_saved_career_preference_falls_back_to_overview_when_level_is_too_low(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'career',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-career"', false);

        $this->assertSame(
            'career',
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_admin_release_preview_is_independent_from_public_users(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('admin.release_level.preview.update'), [
                'level' => (string) ReleaseLevel::InternalPreview->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="4"', false)
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSessionHas(
                ReleaseLevelService::ADMIN_PREVIEW_SESSION_KEY,
                ReleaseLevel::InternalPreview->value,
            );

        $this->actingAs($admin)->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-canovia-nav-key="mobile-workspace-career"', false);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="2"', false)
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-career"', false);
    }

    public function test_admin_can_grant_and_revoke_beta_level_for_one_user(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $beta = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $normal = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(
                route('admin.release_level.user.update', $beta),
                ['level' => (string) ReleaseLevel::BetaExpansion->value],
            )
            ->assertRedirect(
                route('admin.economy.index', ['user_id' => $beta->id]),
            );

        $this->assertDatabaseHas('users', [
            'id' => $beta->id,
            'release_level_override' => ReleaseLevel::BetaExpansion->value,
        ]);

        $beta->refresh();

        $this->actingAs($beta)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-release-level="3"', false)
            ->assertSee('data-navigation-shell="global"', false);
        $this->actingAs($beta)->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-canovia-nav-key="mobile-workspace-career"', false);

        $this->actingAs($normal)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-canovia-nav-key="mobile-workspace-career"', false);

        $this->actingAs($admin)
            ->post(
                route('admin.release_level.user.update', $beta),
                ['level' => 'public'],
            )
            ->assertRedirect(
                route('admin.economy.index', ['user_id' => $beta->id]),
            );

        $this->assertDatabaseHas('users', [
            'id' => $beta->id,
            'release_level_override' => null,
        ]);
    }

    public function test_internal_preview_cannot_be_assigned_as_user_override(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('admin.economy.index', ['user_id' => $user->id]))
            ->post(
                route('admin.release_level.user.update', $user),
                ['level' => (string) ReleaseLevel::InternalPreview->value],
            )
            ->assertRedirect(
                route('admin.economy.index', ['user_id' => $user->id]),
            )
            ->assertSessionHasErrors('level');

        $this->assertNull($user->fresh()->release_level_override);
    }
}
