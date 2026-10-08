<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Enums\WorkspaceModeSource;
use App\Models\Plan;
use App\Models\User;
use App\Services\WorkspaceModePreference;
use App\Services\WorkspaceModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceModePersistenceV542Test extends TestCase
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

    public function test_authenticated_manual_choice_persists_and_root_resumes_selected_workspace(): void
    {
        $user = User::factory()->create();
        $study = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Study->value,
            ]))
            ->assertRedirect(route('workspace.study.top'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'workspace_mode_preference' => 'study',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false);
    }

    public function test_plan_deep_link_overrides_manual_choice_without_erasing_it(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'development',
        ]);
        $study = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('plans.show', $study))
            ->assertOk()
            ->assertSee('data-workspace-mode="study"', false)
            ->assertSee('data-workspace-mode-source="plan_profile"', false)
            ->assertSee('Planに追従');

        $this->assertSame(
            'development',
            $user->fresh()->workspace_mode_preference,
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false);
    }

    public function test_strong_domain_route_overrides_manual_choice(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'study',
        ]);

        $request = $this->request('github_workflow.index', $user);
        $context = app(WorkspaceModeResolver::class)->resolve($request);

        $this->assertSame(WorkspaceMode::Development, $context->mode);
        $this->assertSame(WorkspaceModeSource::RouteHint, $context->source);
        $this->assertSame('study', $user->fresh()->workspace_mode_preference);
    }

    public function test_reset_clears_manual_choice_and_returns_to_auto_resolution(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'study',
        ]);

        $this->actingAs($user)
            ->delete(route('workspace_modes.preference.reset'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'workspace_mode_preference' => null,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false)
            ->assertSee('data-workspace-mode-source="default"', false)
            ->assertSee('data-navigation-shell="global"', false);
    }

    public function test_guest_manual_choice_uses_session_only(): void
    {
        $session = app('session')->driver();
        $session->flush();

        $request = Request::create('/', 'GET');
        $request->setLaravelSession($session);

        $preference = app(WorkspaceModePreference::class);
        $preference->remember($request, WorkspaceMode::Development);

        $this->assertSame(
            'development',
            $session->get(WorkspaceModePreference::SESSION_KEY),
        );
        $this->assertSame(
            WorkspaceMode::Development,
            $preference->selected($request),
        );

        $preference->clear($request);

        $this->assertNull(
            $session->get(WorkspaceModePreference::SESSION_KEY),
        );
    }

    public function test_career_mode_can_be_persisted_as_a_public_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Career->value,
            ]))
            ->assertRedirect(route('workspace.career.index'));

        $this->assertSame(
            'career',
            $user->fresh()->workspace_mode_preference,
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false);
    }

    public function test_unknown_mode_cannot_be_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/workspace/unknown/select')
            ->assertNotFound();

        $this->assertNull(
            $user->fresh()->workspace_mode_preference,
        );
    }

    private function request(string $name, User $user): Request
    {
        $route = new Route(['GET'], '/', fn () => null);
        $route->name($name);

        $request = Request::create('/', 'GET');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function plan(
        User $user,
        string $title,
        string $category,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }
}
