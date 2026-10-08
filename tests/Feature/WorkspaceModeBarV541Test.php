<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceModeBarV541Test extends TestCase
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
        ]);
    }

    public function test_home_keeps_overview_outside_the_specialized_workspace_strip(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSee('data-workspace-mode="overview"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace"', false)
            ->assertDontSee('data-workspace-mode-bar', false);
        $this->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-canovia-nav-key="mobile-workspace-study"', false)
            ->assertSee('data-workspace-exit', false);
    }

    public function test_mode_entry_routes_to_mode_level_surfaces(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'study',
            ]))
            ->assertRedirect(route('workspace.study.top'));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'development',
            ]))
            ->assertRedirect(route('workspace.development.top'));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'career',
            ]))
            ->assertRedirect(route('workspace.career.index'));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'overview',
            ]))
            ->assertRedirect(route('workspace.overview.index'));
    }

    public function test_mode_entry_is_navigation_only_and_does_not_persist_preference(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'study',
            ]))
            ->assertRedirect(route('workspace.study.top'));

        $this->assertNull(
            $user->fresh()->workspace_mode_preference,
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false);
    }

    public function test_plan_context_automatically_sets_current_workspace_mode(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $study = $this->plan($user, '定期テスト', '定期テスト学習');

        $this->actingAs($user)
            ->get(route('plans.show', $study))
            ->assertOk()
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-workspace-mode-source="plan_profile"', false);
    }

    public function test_focus_mode_still_guards_the_contextual_navigation(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('@unless ($focusMode)', $layout);
        $this->assertStringContainsString("layouts.partials.primary-navigation-desktop", $layout);
        $guard = strpos($layout, '@unless ($focusMode)');
        $nav = strpos($layout, "layouts.partials.primary-navigation-desktop");
        $this->assertGreaterThan($guard, $nav);
    }

    public function test_instant_fragment_exposes_workspace_mode_metadata(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get(route('home', [
                'workspace_mode' => 'development',
            ]))
            ->assertOk()
            ->assertSee('id="canovia-instant-meta"', false)
            ->assertSee('"workspaceMode":', false)
            ->assertSee('"key":"overview"', false)
            ->assertSee('data-workspace-mode="overview"', false);
    }

    public function test_unknown_mode_entry_is_not_a_public_workspace(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/workspace/unknown')
            ->assertNotFound();
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority = 2,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => $priority,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }
}
