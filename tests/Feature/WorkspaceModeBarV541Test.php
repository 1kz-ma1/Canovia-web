<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
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
                route('workspace_modes.enter', [
                    'workspaceMode' => WorkspaceMode::Study->value,
                ]),
                false,
            )
            ->assertSee(
                route('workspace_modes.enter', [
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
            ->assertRedirect(route('plans.study_scope.index', $study));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'development',
            ]))
            ->assertRedirect(route('github_workflow.index', [
                'plan_id' => $development->id,
            ]));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'overview',
            ]))
            ->assertRedirect(route('home'));
    }

    public function test_mode_entry_without_domain_plan_uses_ephemeral_home_context(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $redirect = $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => 'study',
            ]));

        $redirect
            ->assertRedirect(route('home', [
                'workspace_mode' => 'study',
            ]))
            ->assertSessionHas(
                'status',
                '学習Workspaceを始めるには、学習Planを作成してください。',
            );

        $this->actingAs($user)
            ->get(route('home', ['workspace_mode' => 'study']))
            ->assertOk()
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-current-workspace-mode="study"', false);

        // V54.1 selection is intentionally not persistent.
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

    public function test_focus_mode_does_not_render_workspace_bar(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        $plan = $this->plan($user, 'AP対策', '資格学習');
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'CIDR演習',
            'description' => 'CIDR',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
        $session = WorkSession::query()->create([
            'actor_token' => Str::random(40),
            'browser_session_id' => Str::random(40),
            'client_session_id' => Str::random(40),
            'start_request_id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now(),
            'paused_seconds' => 0,
            'source' => 'test',
        ]);

        $this->actingAs($user)
            ->get(route('work_sessions.active', $session))
            ->assertOk()
            ->assertDontSee('data-workspace-mode-bar', false);
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
