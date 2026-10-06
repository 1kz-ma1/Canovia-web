<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyWorkspaceFocusedSurfacesV583Test extends TestCase
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

    public function test_default_study_view_is_execution_only_and_exposes_grouped_view_selector(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]));

        $response
            ->assertOk()
            ->assertSee('data-study-surface="work"', false)
            ->assertSee('data-study-view-panel="work"', false)
            ->assertSee('data-study-current-category="execution"', false)
            ->assertSee('data-study-navigation-form', false)
            ->assertSee('data-study-surface-select', false)
            ->assertSee('<optgroup label="実行">', false)
            ->assertSee('<optgroup label="準備">', false)
            ->assertSee('<optgroup label="分析">', false)
            ->assertSee('<optgroup label="記録">', false)
            ->assertSee('value="work"', false)
            ->assertSee('value="preparation"', false)
            ->assertSee('value="analysis"', false)
            ->assertSee('value="history"', false)
            ->assertSee('data-study-top-link', false)
            ->assertSee(route('workspace.study.top'), false)
            ->assertSee('data-study-workspace-methods', false)
            ->assertDontSee('data-study-workspace-current-state', false)
            ->assertDontSee('data-study-workspace-readiness', false)
            ->assertDontSee('data-study-workspace-history', false);
    }

    public function test_analysis_view_contains_state_readiness_weakness_and_results_without_execution_controls(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->scope($plan, 'ネットワーク', 'CIDR');
        $this->attempt(
            $user,
            $plan,
            $task,
            70,
            ['CIDR', 'DNS'],
        );

        $response = $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-study-surface="analysis"', false)
            ->assertSee('data-study-view-panel="analysis"', false)
            ->assertSee('data-study-current-category="analysis"', false)
            ->assertSee('data-study-workspace-current-state', false)
            ->assertSee('data-study-workspace-readiness', false)
            ->assertSee('data-study-workspace-gap', false)
            ->assertSee('data-study-workspace-weaknesses', false)
            ->assertSee('data-study-workspace-recent-results', false)
            ->assertSee('CIDR')
            ->assertSee('DNS')
            ->assertDontSee('data-execution-setup', false)
            ->assertDontSee('data-study-workspace-methods', false)
            ->assertDontSee('data-study-workspace-history', false);
    }

    public function test_preparation_view_owns_scope_resources_scores_and_plan_inputs(): void
    {
        [$user, $plan] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'preparation',
            ]));

        $response
            ->assertOk()
            ->assertSee('data-study-surface="preparation"', false)
            ->assertSee(
                'data-study-view-panel="preparation"',
                false,
            )
            ->assertSee(
                'data-study-current-category="preparation"',
                false,
            )
            ->assertSee('学習範囲')
            ->assertSee('教材・資料')
            ->assertSee('外部スコア')
            ->assertSee(
                route('plans.study_scope.index', $plan),
                false,
            )
            ->assertSee(
                route('plans.resources.index', $plan),
                false,
            )
            ->assertSee(
                route('plans.study_scores.index', $plan),
                false,
            )
            ->assertSee(route('plans.show', $plan), false)
            ->assertDontSee('data-study-workspace-readiness', false)
            ->assertDontSee('data-study-workspace-history', false);
    }

    public function test_history_view_is_the_only_view_that_renders_intelligence_history(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-study-workspace-history',
                false,
            );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'history',
            ]))
            ->assertOk()
            ->assertSee('data-study-surface="history"', false)
            ->assertSee('data-study-view-panel="history"', false)
            ->assertSee(
                'data-study-current-category="history"',
                false,
            )
            ->assertSee('data-study-workspace-history', false)
            ->assertSee('判断とStateの変化')
            ->assertSee(
                route('plans.study_scores.index', $plan),
                false,
            )
            ->assertDontSee(
                'data-study-workspace-current-state',
                false,
            );
    }

    public function test_unknown_surface_falls_back_to_work(): void
    {
        [$user, $plan] = $this->scenario();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'everything',
            ]))
            ->assertOk()
            ->assertSee('data-study-surface="work"', false)
            ->assertSee('data-study-view-panel="work"', false);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 2026秋',
            'description' => '応用情報技術者試験',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '科目Aの問題演習',
            'description' => '弱点探索',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
        array $weaknesses,
    ): StudyPracticeAttempt {
        return StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => '分野横断演習',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => [[
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                    'error_type' => 'knowledge_gap',
                    'weakness_topics' => $weaknesses,
                ]],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => $weaknesses,
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => '診断結果',
            'next_action' => '次の演習へ',
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
}
