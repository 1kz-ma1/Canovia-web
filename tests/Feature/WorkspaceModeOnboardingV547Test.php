<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceModeOnboardingV547Test extends TestCase
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

    public function test_study_onboarding_ends_after_plan_creation_and_state_first_takes_over(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee('data-study-workspace-no-plan', false)
            ->assertSee('data-workspace-mode-onboarding="study"', false)
            ->assertSee('data-workspace-mode-onboarding-step="create_plan"', false)
            ->assertSee(
                route('plans.create.manual', ['workspace_mode' => 'study']),
                false,
            );

        $plan = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertDontSee('data-workspace-mode-onboarding="study"', false)
            ->assertSee('data-study-workspace-composed', false)
            ->assertSee('data-study-workspace-missing-context="baseline"', false)
            ->assertDontSee('試験範囲から始める');
    }

    public function test_development_onboarding_ends_after_plan_creation_and_developer_home_takes_over(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertSee('data-development-workspace-no-plan', false)
            ->assertSee('data-workspace-mode-onboarding="development"', false)
            ->assertSee('data-workspace-mode-onboarding-step="create_plan"', false)
            ->assertSee(
                route('plans.create.manual', ['workspace_mode' => 'development']),
                false,
            );

        $plan = $this->plan($user, 'Canovia', '個人開発');

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-workspace-mode-onboarding="development"',
                false,
            )
            ->assertSee('data-development-home-v1', false)
            ->assertSee('data-development-surface="work"', false)
            ->assertSee('data-development-home-next-action', false)
            ->assertSee('data-development-home-active', false)
            ->assertSee('data-development-top-link', false)
            ->assertSee('data-development-surface-select', false)
            ->assertDontSee('data-development-home-recent-activity', false)
            ->assertDontSee('data-development-home-readiness', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'repository',
            ]))
            ->assertOk()
            ->assertSee('data-development-home-recent-activity', false)
            ->assertSee('data-development-home-readiness', false);
    }

    public function test_study_mode_plan_creation_prefills_category_and_returns_to_study_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'study']))
            ->assertOk()
            ->assertSee('data-plan-create-workspace-mode="study"', false)
            ->assertSee('name="workspace_mode" value="study"', false)
            ->assertSee('value="資格学習" selected', false)
            ->assertSee('Study Workspaceが学習タイプと現在Stateを判定');

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => 'AP対策',
                'category' => '資格学習',
                'priority_mode' => 'auto',
                'workspace_mode' => 'study',
                'create_request_id' => (string) Str::uuid(),
            ]);

        $plan = Plan::query()->where('title', 'AP対策')->firstOrFail();

        $response
            ->assertRedirect(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertSessionHas(
                'status',
                '学習Planを作成しました。Study Workspaceで現在地から次のActionを決めます。',
            );

        $this->assertSame('資格学習', $plan->category);
    }

    public function test_development_mode_plan_creation_prefills_category_and_returns_to_development_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual', [
                'workspace_mode' => 'development',
            ]))
            ->assertOk()
            ->assertSee(
                'data-plan-create-workspace-mode="development"',
                false,
            )
            ->assertSee(
                'name="workspace_mode" value="development"',
                false,
            )
            ->assertSee('value="個人開発" selected', false)
            ->assertSee(
                'Developer Homeが現在Stateから次のActionを提示',
            );

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => 'Canovia',
                'category' => '個人開発',
                'priority_mode' => 'auto',
                'workspace_mode' => 'development',
                'create_request_id' => (string) Str::uuid(),
            ]);

        $plan = Plan::query()->where('title', 'Canovia')->firstOrFail();

        $response
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertSessionHas(
                'status',
                '開発Planを作成しました。Developer Homeで次のActionから始めます。',
            );
    }

    public function test_changed_category_does_not_force_semantically_false_workspace_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => 'Canoviaを学ぶ',
                'category' => '個人開発',
                'priority_mode' => 'auto',
                'workspace_mode' => 'study',
                'create_request_id' => (string) Str::uuid(),
            ]);

        $plan = Plan::query()
            ->where('title', 'Canoviaを学ぶ')
            ->firstOrFail();

        $response->assertRedirect(
            route('plans.show', $plan),
        );
        $this->assertSame('個人開発', $plan->category);
    }

    public function test_invalid_or_overview_plan_creation_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'overview']))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'unknown']))
            ->assertNotFound();
    }

    public function test_overview_first_use_choice_is_registry_driven_and_bounded_to_specialized_modes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-first-use-workspaces', false)
            ->assertSee(
                'data-overview-first-use-workspace="study"',
                false,
            )
            ->assertSee(
                'data-overview-first-use-workspace="development"',
                false,
            )
            ->assertSee(
                'data-overview-first-use-workspace="career"',
                false,
            )
            ->assertDontSee(
                'data-overview-first-use-workspace="overview"',
                false,
            );

        $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertDontSee('data-overview-first-use-workspaces', false);
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
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function scope(
        Plan $plan,
        string $subject,
        string $unit,
    ): StudyScopeItem {
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $plan->user_id,
            'status' => 'confirmed',
            'exam_title' => '試験',
            'exam_date' => $plan->deadline?->format('Y-m-d'),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        return StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => $subject,
            'unit' => $unit,
            'range_text' => $unit,
            'confidence' => 1,
            'sort_order' => 0,
        ]);
    }

    private function studyEvidence(Task $task): TaskEvidence
    {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan?->user_id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'practice:'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'score_percent' => 82,
                'strengths' => ['CIDR'],
                'weaknesses' => [],
                'weakness_topics' => [],
            ],
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
                'commit_sha' => str_repeat('b', 40),
                'branch' => 'feature/v54-7-mode-onboarding',
            ],
        ]);
    }
}
