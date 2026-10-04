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

    public function test_app_shell_renders_registry_driven_fixed_mode_bar(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-workspace-mode-bar', false)
            ->assertSee('data-current-workspace-mode="overview"', false)
            ->assertSee('WORKSPACE')
            ->assertSee('Overview')
            ->assertSee('学習')
            ->assertSee('開発')
            ->assertSee(
                route('workspace_modes.select', [
                    'workspaceMode' => WorkspaceMode::Study->value,
                ]),
                false,
            )
            ->assertSee(
                route('workspace_modes.select', [
                    'workspaceMode' => WorkspaceMode::Development->value,
                ]),
                false,
            );
    }

    public function test_mode_entry_routes_to_best_existing_domain_surface(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $study = $this->plan($user, 'AP対策', '資格学習', priority: 1);
        $this->plan($user, '別の学習', '勉強', priority: 4);
        $development = $this->plan(
            $user,
            'Canovia',
            '個人開発',
            priority: 1,
        );

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'study',
            ]))
            ->assertOk()
            ->assertSee('data-study-workspace', false)
            ->assertSee($study->title);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'development',
            ]))
            ->assertOk()
            ->assertSee('data-development-workspace', false)
            ->assertSee($development->title);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'overview',
            ]))
            ->assertOk()
            ->assertSee('data-overview-workspace', false)
            ->assertSee('data-current-workspace-mode="overview"', false);
    }

    public function test_mode_entry_without_domain_plan_uses_ephemeral_home_context(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'study',
            ]))
            ->assertOk()
            ->assertSee('data-study-workspace-no-plan', false)
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-current-workspace-mode="study"', false);

        // GET Study Workspace entry remains navigation-only.
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
            ->assertSee('data-current-workspace-mode="study"', false)
            ->assertSee('>学習<', false);
    }

    public function test_focus_mode_excludes_workspace_bar_from_normal_shell_boundary(): void
    {
        $layout = file_get_contents(
            resource_path('views/layouts/app.blade.php'),
        );

        $this->assertStringContainsString(
            '@unless ($focusMode)',
            $layout,
        );
        $this->assertStringContainsString(
            "layouts.partials.workspace-mode-bar",
            $layout,
        );

        $firstGuard = strpos($layout, '@unless ($focusMode)');
        $firstBar = strpos(
            $layout,
            "layouts.partials.workspace-mode-bar",
        );
        $guardEnd = strpos($layout, '@endunless', $firstGuard);

        $this->assertNotFalse($firstGuard);
        $this->assertNotFalse($firstBar);
        $this->assertNotFalse($guardEnd);
        $this->assertGreaterThan($firstGuard, $firstBar);
        $this->assertLessThan($guardEnd, $firstBar);
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
            ->assertSee('"key":"development"', false)
            ->assertSee('data-workspace-mode="development"', false);
    }

    public function test_invalid_mode_entry_is_not_a_public_workspace(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/workspace/career')
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
