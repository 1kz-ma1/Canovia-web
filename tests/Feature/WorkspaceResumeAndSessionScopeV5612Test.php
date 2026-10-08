<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\ContinuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceResumeAndSessionScopeV5612Test extends TestCase
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
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_root_resumes_each_persisted_public_workspace(): void
    {
        foreach ([
            'overview' => 'workspace.overview.index',
            'study' => 'workspace.study.top',
            'development' => 'workspace.development.top',
            'career' => 'workspace.career.index',
        ] as $mode => $routeName) {
            $user = User::factory()->create([
                'first_run_completed_at' => now(),
                'workspace_mode_preference' => $mode,
            ]);

            $this->actingAs($user)
                ->get(route('home'))
                ->assertOk()->assertSee('data-navigation-shell="global"', false);

            auth()->logout();
        }
    }

    public function test_root_without_manual_preference_remains_action_home(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => null,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();
    }

    public function test_explicit_root_mode_context_overrides_resume_redirect(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'study',
        ]);

        $this->actingAs($user)
            ->get(route('home', ['workspace_mode' => 'overview']))
            ->assertOk()
            ->assertSee('data-workspace-mode="overview"', false);;
    }

    public function test_home_continuity_excludes_sessions_outside_editable_plan_scope(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $eligiblePlan = $this->plan($user, 'Eligible');
        $excludedPlan = $this->plan($user, 'Excluded');
        $excludedTask = $this->task($excludedPlan, 'Stale Session Task');
        $actorToken = Str::random(64);

        WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5612',
            'plan_id' => $excludedPlan->id,
            'task_id' => $excludedTask->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now()->subMinute(),
            'source' => 'dashboard',
        ]);

        $context = app(ContinuityService::class)->homeContext(
            collect([$eligiblePlan]),
            $actorToken,
        );

        $this->assertNull($context['active_work_session']);
        $this->assertNull($context['continuity']);
        $this->assertCount(0, $context['pending_plan_updates']);
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
