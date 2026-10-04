<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeOrchestrationV401Test extends TestCase
{
    use RefreshDatabase;

    public function test_external_ai_flow_is_tracked_as_one_practice_session_without_extra_prepare_step(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $show = route('plans.tasks.study_practice.show', [$plan, $task]);

        $this->actingAs($user)
            ->get($show)
            ->assertOk()
            ->assertSee('PRACTICE STRATEGY')
            ->assertSee('初回理解度確認')
            ->assertSee('演習準備プロンプトをコピー')
            ->assertSee('prepare_request_id', false)
            ->assertSee('外部AI')
            ->assertSee('このプロンプトに含まれる情報')
            ->assertSee('クリップボードから貼り付けて読み込む')
            ->assertSee('手動で貼り付ける / JSONを確認する');

        $prepareRequestId = (string) Str::uuid();
        $questionPayload = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => 'DNS確認',
            'questions' => [[
                'id' => 'q1',
                'type' => 'text',
                'prompt' => 'DNSの役割を説明してください。',
                'choices' => [],
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
                'prepare_request_id' => $prepareRequestId,
                'questions_json' => json_encode($questionPayload, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $practiceSession = StudyPracticeSession::where('prepare_request_id', $prepareRequestId)->firstOrFail();

        $this->assertSame(StudyPracticeSession::STATUS_READY, $practiceSession->status);
        $this->assertSame('baseline_assessment', $practiceSession->strategy);
        $this->assertSame('external_ai', $practiceSession->question_provider);
        $this->assertSame('handoff', $practiceSession->question_provider_mode);
        $this->assertSame('baseline_assessment', data_get($practiceSession->selection_context, 'strategy.key'));
        $this->assertStringContainsString(
            'Canoviaが決めた今回の演習方針',
            (string) data_get($practiceSession->provider_payload, 'generation_prompt')
        );
        $this->assertSame('q1', data_get($practiceSession->selected_questions, '0.question_ref'));

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.answers', [$plan, $task]), [
                'answers' => ['q1' => 'ドメイン名とIPアドレスの対応付けを行う。'],
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get($show)
            ->assertOk()
            ->assertSee('評価プロンプトをコピー')
            ->assertSee('この評価依頼に含まれる情報')
            ->assertSee('クリップボードから貼り付けて確認')
            ->assertSee('手動で貼り付ける / 評価JSONを確認する')
            ->assertDontSee('min-h-[320px] font-mono', false);

        $practiceSession->refresh();
        $this->assertSame(StudyPracticeSession::STATUS_ANSWERED, $practiceSession->status);
        $this->assertSame('external_ai', $practiceSession->assessment_provider);
        $this->assertSame('handoff', $practiceSession->assessment_provider_mode);
        $this->assertStringContainsString(
            'Canoviaの学習評価AI',
            (string) data_get($practiceSession->assessment_payload, 'evaluation_prompt')
        );

        $assessment = [
            'schema_version' => '1.0',
            'flow' => 'study_assessment',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'score_percent' => 90,
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'correct',
                'feedback' => '名前解決の役割を説明できています。',
                'reasoning_feedback' => '',
                'misconceptions' => [],
            ]],
            'strengths' => ['DNSの役割'],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 80,
            'evidence_summary' => '主要概念を説明できています。',
            'next_action' => 'DNSレコードの種類を確認する',
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode($assessment, JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $practiceSession->refresh();
        $attempt = StudyPracticeAttempt::latest('id')->firstOrFail();

        $this->assertSame(StudyPracticeSession::STATUS_ASSESSED, $practiceSession->status);
        $this->assertSame($practiceSession->id, $attempt->study_practice_session_id);

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.apply', [$plan, $task]), [
                'attempt_id' => $attempt->id,
                'request_hash' => $attempt->request_hash,
            ])
            ->assertRedirect($show)
            ->assertSessionHasNoErrors();

        $practiceSession->refresh();
        $this->assertSame(StudyPracticeSession::STATUS_COMPLETED, $practiceSession->status);
        $this->assertNotNull($practiceSession->completed_at);
    }

    public function test_strategy_uses_learning_history_and_passes_weaknesses_to_provider_prompt(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        StudyPracticeAttempt::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', 'v401-history'),
            'exercise_title' => '前回演習',
            'questions' => [['id' => 'q1']],
            'answers' => [['question_id' => 'q1', 'answer' => 'A']],
            'assessment' => [
                'question_feedback' => [[
                    'question_id' => 'q1',
                    'misconceptions' => ['TCP分割'],
                ]],
            ],
            'score_percent' => 65,
            'strengths' => ['DNS'],
            'weaknesses' => ['MTU計算'],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'MTU計算に誤り。',
            'next_action' => 'MTUとTCP分割を復習する',
        ]);

        $response = $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]));

        $response
            ->assertOk()
            ->assertSee('弱点補強')
            ->assertSee('MTU計算')
            ->assertSee('TCP分割')
            ->assertSee('Canoviaが決めた今回の演習方針');
    }

    public function test_resolved_one_off_weakness_does_not_permanently_lock_strategy(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        StudyPracticeAttempt::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', 'v401-old-weakness'),
            'exercise_title' => '古い演習',
            'questions' => [['id' => 'q1']],
            'answers' => [['question_id' => 'q1', 'answer' => 'A']],
            'assessment' => ['question_feedback' => []],
            'score_percent' => 60,
            'strengths' => [],
            'weaknesses' => ['MTU計算'],
            'recommended_task_progress_percent' => 40,
            'evidence_summary' => '以前の弱点。',
            'next_action' => '復習する',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        StudyPracticeAttempt::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', 'v401-resolved-weakness'),
            'exercise_title' => '最新演習',
            'questions' => [['id' => 'q1']],
            'answers' => [['question_id' => 'q1', 'answer' => 'A']],
            'assessment' => ['question_feedback' => []],
            'score_percent' => 92,
            'strengths' => ['MTU計算'],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 85,
            'evidence_summary' => '弱点を克服。',
            'next_action' => '応用問題へ進む',
        ]);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('定着・応用確認')
            ->assertDontSee('弱点補強（偏り防止）');
    }

    public function test_reset_marks_incomplete_practice_session_as_abandoned(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $prepareRequestId = (string) Str::uuid();

        $payload = [
            'schema_version' => '1.0',
            'flow' => 'study_practice',
            'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id],
            'title' => '確認',
            'questions' => [[
                'id' => 'q1',
                'type' => 'text',
                'prompt' => '説明してください。',
                'choices' => [],
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
                'prepare_request_id' => $prepareRequestId,
                'questions_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

        $practiceSession = StudyPracticeSession::where('prepare_request_id', $prepareRequestId)->firstOrFail();

        $this->actingAs($user)
            ->post(route('plans.tasks.study_practice.reset', [$plan, $task]))
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]));

        $this->assertSame(
            StudyPracticeSession::STATUS_ABANDONED,
            $practiceSession->fresh()->status,
        );
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP対策',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク弱点補強',
            'description' => '科目Aのネットワーク分野を補強する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
