<?php

namespace Tests\Feature;

use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyOfficialExamReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OfficialExamScopeAwareStudyV5864Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_ap_has_official_source_and_practice_first_even_with_no_personal_scope(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '応用情報技術者試験 合格', '資格学習');
        $task = $this->task($plan, '科目Aの分野横断問題演習を進める');

        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);
        $reference = data_get($result->intelligence->state->facts, 'official_exam_reference');

        $this->assertSame('ipa_ap_2026', $reference['key']);
        $this->assertSame('7.2', $reference['syllabus_version']);
        $this->assertSame('2026年度', $reference['applicable_period']);
        $this->assertSame(StudyOfficialExamReferenceService::AP_REFERENCE_URL, $reference['source_url']);
        $this->assertSame(0, data_get($result->intelligence->state->metrics, 'confirmed_scope_count'));
        $this->assertNull($result->intelligence->readiness->score);
        $this->assertSame('establish_official_exam_baseline', $result->decision->type);
        $this->assertSame('official_scope_known_mastery_unmeasured', $result->decision->reasonCode);
        $this->assertSame('study_official_exam_baseline', $result->primaryAction()?->kind);
        $this->assertSame('study_practice', data_get($result->primaryAction()?->metadata, 'route_kind'));
        $this->assertSame($task->id, data_get($result->primaryAction()?->metadata, 'target_task_id'));
        $this->assertSame(StudyOfficialExamReferenceService::AP_REFERENCE_URL, data_get($result->primaryAction()?->metadata, 'official_source_url'));

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-workspace-action', false)
            ->assertSee('最初の演習で理解度を確認する')
            ->assertDontSee('試験範囲を確定する');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'preparation']))
            ->assertOk()
            ->assertSee('data-study-official-exam-reference="ipa_ap_2026"', false)
            ->assertSee(StudyOfficialExamReferenceService::AP_REFERENCE_URL, false)
            ->assertSee('Ver.7.2')
            ->assertSee('理解度は演習Evidenceから確認します。');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertDontSee('data-overview-official-study-action', false)
            ->assertSee('理解度の確認待ち')
            ->assertDontSee('確定した試験範囲がまだありません。');

        $this->assertDatabaseCount('study_scope_items', 0);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
        $this->assertDatabaseCount('intelligence_decision_traces', 0);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_ap_without_task_can_start_baseline_with_one_explicit_action(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'AP対策', '資格学習');

        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);
        $this->assertSame('establish_official_exam_baseline', $result->decision->type);
        $this->assertSame('project_task', data_get($result->primaryAction()?->metadata, 'route_kind'));
        $this->assertDatabaseCount('tasks', 0);

        $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan))
            ->assertRedirect();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('最初の演習で理解度を確認する', Task::query()->sole()->title);
        $this->assertDatabaseCount('study_scope_items', 0);
    }

    public function test_school_test_with_personal_scope_unknown_still_requests_school_specific_range(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '期末テスト対策', '定期テスト学習');

        $this->assertNull(app(StudyOfficialExamReferenceService::class)->forPlan($plan));
        $result = app(StudyAdaptiveActionService::class)->evaluate($plan);
        $this->assertSame('capture_scope', $result->decision->type);

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('試験範囲を取り込む')
            ->assertDontSee('data-study-official-exam-reference=', false);
    }

    public function test_unregistered_or_overridden_qualification_is_not_falsely_said_to_have_official_reference(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $unknown = $this->plan($user, 'オリジナル資格試験対策', '資格学習');
        $overridden = $this->plan($user, '応用情報技術者', '資格学習');
        $overridden->study_learning_type_override = 'school_test';
        $overridden->save();

        $reference = app(StudyOfficialExamReferenceService::class);
        $this->assertNull($reference->forPlan($unknown));
        $this->assertNull($reference->forPlan($overridden));
        $this->assertSame(
            'capture_scope',
            app(StudyAdaptiveActionService::class)->evaluate($unknown)->decision->type,
        );
        $this->assertSame(
            'capture_scope',
            app(StudyAdaptiveActionService::class)->evaluate($overridden)->decision->type,
        );
    }

    public function test_old_manual_scope_task_is_not_selected_as_executable_ap_practice(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '応用情報技術者試験', '資格学習');
        $this->task($plan, '試験範囲を確定する');

        $action = app(StudyAdaptiveActionService::class)->evaluate($plan)->primaryAction();
        $this->assertSame('study_official_exam_baseline', $action?->kind);
        $this->assertNull(data_get($action?->metadata, 'target_task_id'));
        $this->assertSame('project_task', data_get($action?->metadata, 'route_kind'));
    }

    private function plan(User $owner, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(40),
            'is_public' => false,
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
}
