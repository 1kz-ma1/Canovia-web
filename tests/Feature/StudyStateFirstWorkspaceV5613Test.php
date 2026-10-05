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

class StudyStateFirstWorkspaceV5613Test extends TestCase
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

    public function test_ap_with_practice_history_uses_current_state_without_mandatory_scope(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'AP対策',
            '資格学習',
            '科目Aを11月に受験する',
        );
        $task = $this->task($plan, '科目Aの分野横断問題を解く');
        $this->attempt(
            $user,
            $plan,
            $task,
            84,
            ['データベース', 'ネットワーク'],
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="certification_exam"',
                false,
            )
            ->assertSee('data-study-workspace-current-state', false)
            ->assertSee('演習履歴から現在地を把握済み')
            ->assertSee('84%')
            ->assertSee('データベース')
            ->assertSee('ネットワーク')
            ->assertSee(
                route('plans.tasks.study_practice.show', [$plan, $task]),
                false,
            )
            ->assertSee('演習を続ける')
            ->assertDontSee(
                'data-study-workspace-missing-context="study_scope"',
                false,
            )
            ->assertDontSee('data-study-workspace-capture-first', false)
            ->assertDontSee('data-study-workspace-readiness', false);
    }

    public function test_new_certification_plan_prefers_baseline_over_scope_gate(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            '応用情報技術者試験 合格',
            '資格学習',
        );
        $task = $this->task($plan, '科目Aの問題演習');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="certification_exam"',
                false,
            )
            ->assertSee(
                'data-study-workspace-missing-context="baseline"',
                false,
            )
            ->assertSee('まず現在地を1回だけ測ります')
            ->assertSee('現在地を診断')
            ->assertSee(
                route('plans.tasks.study_practice.show', [$plan, $task]),
                false,
            )
            ->assertDontSee('今回の試験範囲がまだ分かりません');
    }

    public function test_toeic_600_routes_to_score_exam_and_asks_for_current_position(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'TOEIC 600点を取る',
            '英語学習',
            'まず600点を目標にする',
        );
        $task = $this->task($plan, 'TOEIC診断問題');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-learning-type="score_exam"', false)
            ->assertSee('目標 600')
            ->assertSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertSee('現在スコアがまだ分かりません')
            ->assertSee('診断を始める')
            ->assertSee(
                route('plans.tasks.study_practice.show', [$plan, $task]),
                false,
            )
            ->assertDontSee('今回の試験範囲がまだ分かりません');
    }

    public function test_school_test_without_scope_surfaces_scope_as_decision_changing_context(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            '数学II 中間テストで80点',
            '定期テスト学習',
        );
        $this->task($plan, '数学IIを勉強する');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-learning-type="school_test"', false)
            ->assertSee(
                'data-study-workspace-missing-context="study_scope"',
                false,
            )
            ->assertSee('今回の試験範囲がまだ分かりません')
            ->assertSee('試験範囲を追加')
            ->assertSee(route('plans.study_scope.index', $plan), false)
            ->assertDontSee('data-execution-setup', false);
    }

    public function test_skill_learning_does_not_require_exam_scope(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'Pythonを習得する',
            'プログラミング学習',
        );
        $task = $this->task($plan, 'Pythonで小さなCLIを作る');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-learning-type="skill_learning"', false)
            ->assertSee(
                'data-study-workspace-missing-context="baseline"',
                false,
            )
            ->assertSee(
                route('plans.tasks.study_practice.show', [$plan, $task]),
                false,
            )
            ->assertDontSee('今回の試験範囲がまだ分かりません');
    }

    public function test_memorization_prefers_recall_surface_when_task_exists(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            '英単語を暗記する',
            '英語学習',
        );
        $task = $this->task($plan, '英単語100語');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-learning-type="memorization"', false)
            ->assertSee(
                'data-study-workspace-missing-context="retention_baseline"',
                false,
            )
            ->assertSee('最初の定着度を確認します')
            ->assertSee(
                route('plans.tasks.study_recall.show', [$plan, $task]),
                false,
            )
            ->assertSee('Recallを開く');
    }

    public function test_confirmed_scope_keeps_existing_readiness_and_gap_surfaces(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'AP対策',
            '資格学習',
        );
        $this->scope($plan, 'ネットワーク', 'CIDR');
        $task = $this->task($plan, 'CIDRを演習する');
        $this->attempt($user, $plan, $task, 75, ['CIDR']);

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-workspace-readiness', false)
            ->assertSee('data-study-workspace-gap', false)
            ->assertSee('EXAM READINESS')
            ->assertSee('BIGGEST GAP')
            ->assertSee('data-study-workspace-priority-scope', false);
    }

    public function test_workspace_get_never_creates_task(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(
            $user,
            'AP対策',
            '資格学習',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk();

        $this->assertDatabaseCount('tasks', 0);
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        ?string $description = null,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $description ?? $title,
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

    /**
     * @param array<int,string> $weaknesses
     */
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
