<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContextualWorkspaceNavigationV5883Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_home_is_overview_even_after_specialized_preference_and_has_secondary_legacy_links(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'study',
        ]);
        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace"', false)
            ->assertSee('data-workspace-mode="overview"', false)
            ->assertDontSee('data-workspace-mode-bar', false)
            ->assertSee(route('roadmap.index'), false)
            ->assertSee(route('navigation.index'), false);
    }

    public function test_specialized_shell_has_workspace_navigation_and_explicit_home_exit(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user)->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-navigation-shell="workspace"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace-study"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace-development"', false)
            ->assertSee('data-workspace-exit', false)
            ->assertDontSee('data-canovia-nav-key="mobile-home"', false);
    }

    public function test_workspace_entrance_resumes_per_mode_and_home_never_redirects(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user)->get(route('workspace.study.index'))->assertOk();
        $this->get(route('workspace_modes.resume'))->assertRedirect(route('workspace.study.index'));

        $this->get(route('workspace.development.top'))->assertOk();
        $this->get(route('workspace_modes.resume'))->assertRedirect(route('workspace.development.top'));
        $this->get(route('workspace_modes.enter', ['workspaceMode' => 'study']))
            ->assertRedirect(route('workspace.study.index'));
        $this->get(route('home'))->assertOk();
    }

    public function test_unavailable_saved_plan_falls_back_to_workspace_top(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $other->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '他人の計画',
            'description' => '', 'category' => '資格学習', 'priority' => 1,
            'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addMonth(), 'is_public' => false,
        ]);
        $this->actingAs($user)->get(route('workspace.study.top'))->assertOk();
        $this->withSession([
            'canovia_workspace_navigation_v1:user:'.$user->id => [
                'last' => 'study',
                'screens' => ['study' => [
                    'url' => '/plans/'.$plan->id.'/study-scope',
                    'plan_id' => $plan->id, 'task_id' => null,
                ]],
            ],
        ])->get(route('workspace_modes.resume'))->assertRedirect(route('workspace.study.top'));
    }
    public function test_downgraded_release_level_never_resumes_hidden_career_mode(): void
    {
        config(['release_levels.public_level' => \App\Enums\\ReleaseLevel::ProductPreview->value]);
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'career',
        ]);

        $this->actingAs($user)->withSession([
            'canovia_workspace_navigation_v1:user:'.$user->id => [
                'last' => 'career',
                'screens' => ['career' => ['url' => '/workspace/career', 'plan_id' => null, 'task_id' => null]],
            ],
        ])->get(route('workspace_modes.resume'))
            ->assertRedirect(route('workspace.study.top'));
        $this->assertSame('career', $user->fresh()->workspace_mode_preference);
    }

    public function test_workspace_entry_with_no_specialized_modes_falls_back_to_overview(): void
    {
        config(['release_levels.public_level' => \App\Enums\\ReleaseLevel::CoreStable->value]);
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'development',
        ]);

        $this->actingAs($user)->get(route('workspace_modes.resume'))
            ->assertRedirect(route('home'));
    }


}
