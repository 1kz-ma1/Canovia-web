<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PracticeQuestionCandidate;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\PracticeQuestionCandidateReliabilitySignalService;
use App\Services\StudyActivityPolicyService;
use App\Services\StudyPracticeReliabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionCandidateReliabilityV5813Test extends TestCase
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

    public function test_no_exam_profile_keeps_candidate_signal_unavailable(): void
    {
        $signal = app(
            PracticeQuestionCandidateReliabilitySignalService::class,
        )->summarize(null, []);

        $this->assertSame('unavailable', $signal['status']);
        $this->assertSame(0, $signal['applied_adjustment']);
        $this->assertNull($signal['exam_profile_key']);
    }

    public function test_fewer_than_five_reviewed_candidates_are_observation_only_and_pending_is_not_in_denominator(): void
    {
        [$user] = $this->scenario();

        $this->candidate('promoted', 'a', $user);
        $this->candidate('promoted', 'b', $user);
        $this->candidate('rejected', 'c', $user);
        $this->candidate('rejected', 'd', $user);

        for ($i = 0; $i < 6; $i++) {
            $this->candidate('pending', 'pending-'.$i, $user);
        }

        $signal = $this->signal();

        $this->assertSame('observing', $signal['status']);
        $this->assertSame(10, $signal['candidate_count']);
        $this->assertSame(6, $signal['pending_count']);
        $this->assertSame(4, $signal['reviewed_count']);
        $this->assertSame(2, $signal['promoted_count']);
        $this->assertSame(2, $signal['rejected_count']);
        $this->assertSame(50, $signal['promotion_rate_percent']);
        $this->assertSame(0, $signal['applied_adjustment']);
    }

    public function test_assessed_reuse_increases_signal_strength_and_bounded_adjustment(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $questions = collect(range(1, 5))
            ->map(fn (int $i) =>
                $this->candidate(
                    'promoted',
                    'promoted-'.$i,
                    $user,
                )->promotedQuestion,
            )
            ->values();

        $beforeReuse = $this->signal();

        $this->assertSame('active', $beforeReuse['status']);
        $this->assertSame(100, $beforeReuse['promotion_rate_percent']);
        $this->assertSame(6, $beforeReuse['raw_adjustment']);
        $this->assertSame(1, $beforeReuse['applied_adjustment']);

        for ($i = 0; $i < 4; $i++) {
            $session = $this->session(
                $plan,
                $task,
                $user,
                $questions
                    ->map(fn (Question $q) => $q->id)
                    ->all(),
            );
            $this->attempt($session, 80);
        }

        $afterReuse = $this->signal();

        $this->assertSame(20, $afterReuse['selected_reuse_count']);
        $this->assertSame(20, $afterReuse['assessed_reuse_count']);
        $this->assertSame(5, $afterReuse['reused_question_count']);
        $this->assertGreaterThan(
            $beforeReuse['evidence_strength_percent'],
            $afterReuse['evidence_strength_percent'],
        );
        $this->assertSame(3, $afterReuse['applied_adjustment']);
        $this->assertLessThanOrEqual(6, $afterReuse['applied_adjustment']);
    }

    public function test_selected_but_unassessed_reuse_is_tracked_separately(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $question = $this->candidate(
            'promoted',
            'reuse-only',
            $user,
        )->promotedQuestion;

        $this->session(
            $plan,
            $task,
            $user,
            [$question->id],
        );

        $signal = $this->signal();

        $this->assertSame(1, $signal['selected_reuse_count']);
        $this->assertSame(0, $signal['assessed_reuse_count']);
        $this->assertSame(1, $signal['reused_question_count']);
    }

    public function test_learner_correctness_does_not_change_candidate_signal(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $question = $this->candidate(
            'promoted',
            'correctness-neutral',
            $user,
        )->promotedQuestion;

        for ($i = 0; $i < 4; $i++) {
            $this->candidate(
                'promoted',
                'extra-'.$i,
                $user,
            );
        }

        $session = $this->session(
            $plan,
            $task,
            $user,
            [$question->id],
        );
        $attempt = $this->attempt($session, 100, 'correct');

        $before = $this->signal();

        $attempt->update([
            'score_percent' => 0,
            'assessment' => [
                'question_feedback' => [[
                    'question_id' => 'bank_'.$question->id,
                    'correctness' => 'incorrect',
                ]],
            ],
        ]);

        $after = $this->signal();

        $this->assertSame(
            $before['assessed_reuse_count'],
            $after['assessed_reuse_count'],
        );
        $this->assertSame(
            $before['evidence_strength_percent'],
            $after['evidence_strength_percent'],
        );
        $this->assertSame(
            $before['applied_adjustment'],
            $after['applied_adjustment'],
        );
    }

    public function test_current_session_reports_reviewed_candidate_question_selection(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $promoted = $this->candidate(
            'promoted',
            'current-promoted',
            $user,
        )->promotedQuestion;
        $normal = $this->question(
            'normal-bank',
            'canovia_original',
        );

        $session = $this->session(
            $plan,
            $task,
            $user,
            [$promoted->id, $normal->id],
        );

        $signal = app(
            PracticeQuestionCandidateReliabilitySignalService::class,
        )->summarize(
            $session,
            $this->strategy(),
        );

        $this->assertSame(
            1,
            $signal['current_session_promoted_candidate_count'],
        );
    }

    public function test_native_and_hybrid_apply_candidate_adjustment_but_bank_and_external_do_not(): void
    {
        [, $plan, $task] = $this->scenario();
        $user = $plan->user;

        $questions = collect(range(1, 5))
            ->map(fn (int $i) =>
                $this->candidate(
                    'promoted',
                    'reliability-'.$i,
                    $user,
                )->promotedQuestion,
            )
            ->values();

        for ($i = 0; $i < 4; $i++) {
            $session = $this->session(
                $plan,
                $task,
                $user,
                $questions
                    ->map(fn (Question $q) => $q->id)
                    ->all(),
            );
            $this->attempt($session, 50 + $i);
        }

        $activity = app(StudyActivityPolicyService::class)
            ->forPlanTask($plan, $task);
        $service = app(StudyPracticeReliabilityService::class);
        $strategy = $this->strategy();

        $native = $service->evaluate(
            $activity,
            ['provider' => 'native_ai'],
            null,
            $strategy,
        );
        $hybrid = $service->evaluate(
            $activity,
            ['provider' => 'hybrid_ai'],
            null,
            $strategy,
        );
        $bank = $service->evaluate(
            $activity,
            ['provider' => 'question_bank'],
            null,
            $strategy,
        );
        $external = $service->evaluate(
            $activity,
            ['provider' => 'external_ai'],
            null,
            $strategy,
        );

        $nativeQuality = collect($native['metrics'])
            ->firstWhere('key', 'question_quality');
        $hybridQuality = collect($hybrid['metrics'])
            ->firstWhere('key', 'question_quality');
        $bankQuality = collect($bank['metrics'])
            ->firstWhere('key', 'question_quality');
        $externalQuality = collect($external['metrics'])
            ->firstWhere('key', 'question_quality');

        $this->assertSame(3, $nativeQuality['candidate_adjustment']);
        $this->assertSame(77, $nativeQuality['score']);
        $this->assertSame(3, $hybridQuality['candidate_adjustment']);
        $this->assertSame(87, $hybridQuality['score']);

        $this->assertSame(0, $bankQuality['candidate_adjustment']);
        $this->assertSame(95, $bankQuality['score']);

        $this->assertSame(0, $externalQuality['candidate_adjustment']);
        $this->assertSame(66, $externalQuality['score']);

        foreach ([$native, $hybrid, $bank, $external] as $result) {
            $this->assertGreaterThanOrEqual(0, $result['overall_score']);
            $this->assertLessThanOrEqual(100, $result['overall_score']);
        }
    }

    public function test_reliability_ui_explains_candidate_operations_and_correctness_boundary(): void
    {
        $view = file_get_contents(
            resource_path('views/study_practice/show.blade.php'),
        );

        $this->assertStringContainsString(
            'data-practice-candidate-reliability',
            $view,
        );
        $this->assertStringContainsString(
            'Question Candidate運営実績',
            $view,
        );
        $this->assertStringContainsString(
            '学習者の正答率はQuestion品質の判定に使っていません。',
            $view,
        );
    }

    public function test_candidate_signal_and_reliability_are_provider_free(): void
    {
        [$user, $plan, $task] = $this->scenario();

        for ($i = 0; $i < 5; $i++) {
            $this->candidate(
                'promoted',
                'provider-free-'.$i,
                $user,
            );
        }

        Http::fake();

        $activity = app(StudyActivityPolicyService::class)
            ->forPlanTask($plan, $task);

        app(StudyPracticeReliabilityService::class)->evaluate(
            $activity,
            ['provider' => 'native_ai'],
            null,
            $this->strategy(),
        );

        Http::assertNothingSent();
    }

    private function signal(): array
    {
        return app(
            PracticeQuestionCandidateReliabilitySignalService::class,
        )->summarize(
            null,
            $this->strategy(),
        );
    }

    private function strategy(): array
    {
        return [
            'target_question_count' => 10,
            'exam_profile' => [
                'key' => 'ap_subject_a_exam',
                'label' => 'AP科目A',
            ],
        ];
    }

    private function candidate(
        string $status,
        string $key,
        User $reviewer,
    ): PracticeQuestionCandidate {
        $question = $status === 'promoted'
            ? $this->question(
                'candidate-'.$key,
                'ai_generated',
            )
            : null;

        return PracticeQuestionCandidate::query()->create([
            'fingerprint' => hash('sha256', 'candidate-'.$key),
            'status' => $status,
            'provider' => 'native_ai',
            'model' => 'gpt-test',
            'exam_profile_key' => 'ap_subject_a_exam',
            'question_payload' => [
                'id' => 'native_'.$key,
                'prompt' => 'Question '.$key,
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'single_choice',
                    'label' => '回答',
                    'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => 'A'],
                        ['id' => 'B', 'label' => 'B'],
                    ],
                ]],
            ],
            'generation_count' => 1,
            'last_seen_at' => now(),
            'promoted_question_pack_id' =>
                $question?->question_pack_id,
            'promoted_question_id' =>
                $question?->id,
            'reviewed_by_user_id' =>
                $status === 'pending'
                    ? null
                    : $reviewer->id,
            'reviewed_at' =>
                $status === 'pending'
                    ? null
                    : now(),
        ])->load('promotedQuestion');
    }

    private function question(
        string $externalKey,
        string $sourceType,
    ): Question {
        static $packId = null;

        if (
            $packId === null
            || ! QuestionPack::query()->whereKey($packId)->exists()
        ) {
            $pack = QuestionPack::query()->create([
                'slug' => 'reliability-'.Str::uuid(),
                'title' => 'Reliability Pack',
                'exam_code' => 'AP',
                'subject' => '科目A',
                'version' => '1',
                'status' => 'published',
                'downloadable' => true,
                'metadata' => [
                    'match_terms' => ['AP'],
                ],
            ]);
            $packId = $pack->id;
        }

        return Question::query()->create([
            'question_pack_id' => $packId,
            'external_key' => $externalKey,
            'source_type' => $sourceType,
            'source_reference' => 'test',
            'prompt' => 'Question '.$externalKey,
            'response_schema' => [[
                'id' => 'answer',
                'type' => 'single_choice',
                'label' => '回答',
                'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'A'],
                    ['id' => 'B', 'label' => 'B'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice',
                'field_id' => 'answer',
                'answer' => 'A',
            ],
            'learning_metadata' => [
                'concepts' => ['test'],
            ],
            'difficulty' => 3,
            'sort_order' => Question::query()
                ->where('question_pack_id', $packId)
                ->count() + 1,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<int,int> $questionIds
     */
    private function session(
        Plan $plan,
        Task $task,
        User $user,
        array $questionIds,
    ): StudyPracticeSession {
        return StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_COMPLETED,
            'strategy' => 'weakness_reinforcement',
            'strategy_version' => 'v1',
            'selector_type' => 'question_bank',
            'selector_version' => 'v1',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => $this->strategy(),
            ],
            'selected_questions' => collect($questionIds)
                ->map(fn (int $id) => [
                    'question_ref' => 'bank_'.$id,
                    'question_id' => $id,
                    'source_type' => Question::query()
                        ->findOrFail($id)
                        ->source_type,
                ])
                ->all(),
            'completed_at' => now(),
        ]);
    }

    private function attempt(
        StudyPracticeSession $session,
        int $score,
        string $correctness = 'correct',
    ): StudyPracticeAttempt {
        $first = collect($session->selected_questions)
            ->first();

        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $session->plan_id,
            'task_id' => $session->task_id,
            'user_id' => $session->user_id,
            'request_hash' => hash(
                'sha256',
                'attempt-'.$session->id.'-'.$score.'-'.$correctness,
            ),
            'exercise_title' => 'Reliability',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'question_feedback' => [[
                    'question_id' =>
                        $first['question_ref'] ?? 'q1',
                    'correctness' => $correctness,
                ]],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 0,
            'evidence_summary' => 'test',
            'next_action' => 'continue',
        ]);
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
            'title' => '応用情報 科目A',
            'description' => 'AP科目A対策',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '科目A 横断問題演習',
            'description' => '過去問・類題を解いて理解を確認する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
