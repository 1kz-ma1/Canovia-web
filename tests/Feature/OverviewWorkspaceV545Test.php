<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\WorkspaceMode;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverviewWorkspaceV545Test extends TestCase
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

    public function test_overview_is_valid_without_any_plan_and_is_strong_overview_context(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'study',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-workspace', false)
            ->assertDontSee('data-overview-primary-action', false)
            ->assertSee('data-overview-first-use-workspaces', false)
            ->assertDontSee('data-overview-mode="study"', false)
            ->assertDontSee('data-overview-mode="development"', false)
            ->assertDontSee('data-overview-mode="career"', false)
            ->assertDontSee('data-overview-inbox', false)
            ->assertDontSee('data-overview-important-changes', false)
            ->assertSee('data-current-workspace-mode="overview"', false)
            ->assertSee('data-workspace-mode-source="route_hint"', false)
            ->assertDontSee('まだ優先Actionはありません。');

        $this->assertSame(
            'study',
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_overview_reuses_existing_global_guidance_instead_of_creating_new_priority_model(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            '最優先の一般Plan',
            'その他',
            priority: 1,
        );
        $task = $this->task($plan, 'Overviewで進めるTask');

        $response = $this->actingAs($user)
            ->get(route('workspace.overview.index'));

        $response
            ->assertOk()
            ->assertSee('GLOBAL CURRENT ACTION')
            ->assertSee($plan->title)
            ->assertSee($task->title)
            ->assertSee('data-overview-primary-action', false);
    }

    public function test_mode_summaries_hide_fake_numeric_readiness_until_domain_setup_exists(): void
    {
        $user = User::factory()->create();

        $study = $this->plan($user, 'AP対策', '資格学習');
        $development = $this->plan($user, 'Canovia', '個人開発');

        $response = $this->actingAs($user)
            ->get(route('workspace.overview.index'));

        $response
            ->assertOk()
            ->assertSee('data-overview-mode="study"', false)
            ->assertSee(
                'data-overview-mode-setup-needed="true"',
                false,
            )
            ->assertSee($study->title)
            ->assertSee($development->title)
            ->assertSee('理解度の確認待ち')
            ->assertSee('IPAの公式試験範囲は公開済みです。')
            ->assertDontSee('確定した試験範囲がまだありません。')
            ->assertSee('開発の作業状況を確認中')
            ->assertDontSee('Release判断に使えるDevelopment Evidenceがまだありません。')
            ->assertDontSee('セットアップ中')
            ->assertDontSee('data-overview-mode="career"', false)
            ->assertDontSee('data-overview-inbox', false)
            ->assertDontSee('data-overview-important-changes', false);
    }

    public function test_mode_summaries_use_existing_study_and_development_readiness_when_observed(): void
    {
        $user = User::factory()->create();

        $study = $this->plan($user, 'AP対策', '資格学習');
        $this->studyScope($study, 'ネットワーク', 'CIDR');

        $development = $this->plan($user, 'Canovia', '個人開発');
        $developmentTask = $this->task(
            $development,
            'Overview release candidate',
        );
        $this->commitEvidence($developmentTask);

        $response = $this->actingAs($user)
            ->get(route('workspace.overview.index'));

        $response
            ->assertOk()
            ->assertSee(
                'data-overview-mode="study"',
                false,
            )
            ->assertSee(
                'data-overview-mode="development"',
                false,
            )
            ->assertSee(
                'data-overview-mode-setup-needed="false"',
                false,
            )
            ->assertSee('Exam Readiness')
            ->assertSee('Release Readiness')
            ->assertSee('CIDR')
            ->assertSee('CIを通す');
    }

    public function test_overview_shows_bounded_identity_scoped_inbox_summary(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 5) as $index) {
            InboxItem::query()->create([
                'user_id' => $user->id,
                'source_type' => 'text',
                'status' => 'new',
                'title' => '自分のInbox '.$index,
                'content' => 'pending',
            ]);
        }

        InboxItem::query()->create([
            'user_id' => $other->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => '他人のInbox',
            'content' => 'must stay private',
        ]);

        $response = $this->actingAs($user)
            ->get(route('workspace.overview.index'));

        $response
            ->assertOk()
            ->assertSee('data-overview-inbox', false)
            ->assertSee('5件')
            ->assertSee('自分のInbox 5')
            ->assertSee('自分のInbox 2')
            ->assertDontSee('自分のInbox 1')
            ->assertDontSee('他人のInbox');
    }

    public function test_overview_reuses_action_home_signals_for_important_changes(): void
    {
        $user = User::factory()->create();

        $plan = $this->plan(
            $user,
            '遅れたPlan',
            'その他',
            priority: 1,
            startDate: today()->subDays(15),
            deadline: today()->addDays(5),
        );
        $this->task($plan, '遅れたTask');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-important-changes', false)
            ->assertSee('data-overview-change="plan_attention"', false)
            ->assertSee($plan->title);
    }

    public function test_overview_get_does_not_persist_intelligence_history_or_dashboard_telemetry(): void
    {
        $user = User::factory()->create();

        $study = $this->plan($user, 'AP対策', '資格学習');
        $this->studyScope($study, 'ネットワーク', 'CIDR');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $this->assertDatabaseCount('intelligence_state_snapshots', 0);
        $this->assertDatabaseCount('intelligence_decision_traces', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
        $this->assertDatabaseCount('behavior_events', 0);
    }

    public function test_persistent_overview_selection_lands_on_overview_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Overview->value,
            ]))
            ->assertRedirect(route('workspace.overview.index'));

        $this->assertSame(
            'overview',
            $user->fresh()->workspace_mode_preference,
        );
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        int $priority = 2,
        mixed $startDate = null,
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
            'start_date' => $startDate ?? today(),
            'deadline' => $deadline ?? today()->addMonth(),
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
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function studyScope(
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
                'branch' => 'feature/v54-5-overview-workspace',
            ],
        ]);
    }
}
