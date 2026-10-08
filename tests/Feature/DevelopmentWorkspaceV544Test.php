<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentWorkspaceV544Test extends TestCase
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

    public function test_development_workspace_is_valid_without_a_development_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertSee('data-development-workspace', false)
            ->assertSee('data-development-workspace-no-plan', false)
            ->assertSee('開発Planを作る')
            ->assertSee(route('plans.create'), false)
            ->assertSee('data-current-workspace-mode="development"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false);
    }

    public function test_workspace_selects_highest_priority_development_plan_and_preserves_explicit_deep_link(): void
    {
        $user = User::factory()->create();

        $lower = $this->plan(
            $user,
            '小規模ゲーム',
            'ゲーム開発',
            priority: 4,
            deadline: today()->addDays(5),
        );
        $primary = $this->plan(
            $user,
            'Canovia',
            '個人開発',
            priority: 1,
            deadline: today()->addDays(20),
        );
        $this->plan(
            $user,
            'AP対策',
            '資格学習',
            priority: 1,
            deadline: today()->addDay(),
        );

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertSee($primary->title)
            ->assertDontSee($lower->title)
            ->assertSee('data-development-top-link', false)
            ->assertSee(route('workspace.development.top'), false)
            ->assertDontSee('data-development-plan-select', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $lower->id,
            ]))
            ->assertOk()
            ->assertSee($lower->title)
            ->assertSee('data-development-top-link', false)
            ->assertDontSee('data-development-plan-select', false);
    }

    public function test_workspace_is_state_first_without_release_evidence_and_does_not_mutate_core_state(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canovia', '個人開発');

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-home-v1', false)
            ->assertSee('data-development-surface="work"', false)
            ->assertSee('data-development-home-next-action', false)
            ->assertSee('data-development-home-active', false)
            ->assertDontSee('data-development-home-recent-activity', false)
            ->assertDontSee('data-development-workspace-readiness', false)
            ->assertSee('data-development-local-first-action', false)
            ->assertSee('data-development-local-create-action', false)
            ->assertSee(
                route('plans.ai_task_assistant.show', [
                    'plan' => $plan,
                    'return_to_workspace' => 1,
                ]),
                false,
            );

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-home-recent-activity', false)
            ->assertSee('data-development-workspace-readiness', false)
            ->assertDontSee('data-development-home-next-action', false);

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
        $this->assertDatabaseCount('intelligence_state_snapshots', 0);
        $this->assertDatabaseCount('intelligence_decision_traces', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
    }

    public function test_release_evidence_renders_existing_readiness_gap_action_and_seven_gates(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'Canovia', '個人開発');
        $task = $this->task($plan, 'V54.4 Development Workspace');
        $this->commitEvidence($task);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-development-workspace-action', false)
            ->assertSee('NEXT ACTION')
            ->assertSee($task->title)
            ->assertSee('CIを通す')
            ->assertDontSee('data-development-workspace-readiness', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-workspace-readiness', false)
            ->assertSee('data-development-workspace-gap', false)
            ->assertSee('data-development-workspace-quality-gates', false)
            ->assertSee('RELEASE READINESS')
            ->assertSee('BIGGEST RELEASE GAP')
            ->assertSee('CI / Test')
            ->assertSee('Review')
            ->assertSee('Production Deploy')
            ->assertSee('実機・本番確認')
            ->assertSee('仕様同期')
            ->assertSee(
                'data-development-workspace-gate="implementation"',
                false,
            )
            ->assertSee(
                'data-development-workspace-gate-status="passed"',
                false,
            );

        $this->assertDatabaseCount('intelligence_state_snapshots', 0);
        $this->assertDatabaseCount('intelligence_decision_traces', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
    }

    public function test_explicit_plan_selection_rejects_inaccessible_or_non_development_plan(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $privateDevelopment = $this->plan(
            $other,
            '他人の開発',
            '個人開発',
        );
        $study = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $privateDevelopment->id,
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $study->id,
            ]))
            ->assertNotFound();
    }

    public function test_development_workspace_context_overrides_study_preference_without_erasing_it(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'study',
        ]);
        $this->plan($user, 'Canovia', '個人開発');

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertSee('data-current-workspace-mode="development"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false);

        $this->assertSame(
            'study',
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_persistent_development_selection_lands_on_development_top(): void
    {
        $user = User::factory()->create();
        $this->plan($user, 'Canovia', '個人開発');

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Development->value,
            ]))
            ->assertRedirect(route('workspace.development.top'));

        $this->assertSame(
            'development',
            $user->fresh()->workspace_mode_preference,
        );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority = 2,
        mixed $deadline = null,
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
            'deadline' => $deadline ?? today()->addWeeks(3),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 120,
            'remaining_minutes' => 60,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }

    private function commitEvidence(Task $task): TaskEvidence
    {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::GitHub->value,
            'type' => 'github_commit_observed',
            'external_key' => 'commit:'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'commit_sha' => str_repeat('a', 40),
                'branch' => 'feature/v54-4-development-workspace',
            ],
        ]);
    }
}
