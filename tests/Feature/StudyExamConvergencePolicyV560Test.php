<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\StudyScopeCapture;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyExamConvergencePolicyService;
use App\Services\StudyPracticePromptService;
use App\Services\StudyPracticeStrategyService;
use App\Services\StudyTaskProgressionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyExamConvergencePolicyV560Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-05 09:00:00');

        config([
            'study.exam_convergence.history_attempt_limit' => 16,
            'study.exam_convergence.graduation.minimum_targeted_sessions' => 2,
            'study.exam_convergence.graduation.minimum_targeted_question_budget' => 8,
            'study.exam_convergence.graduation.minimum_score_percent' => 80,
            'study.exam_convergence.reinforcement.maximum_sessions_per_cycle' => 3,
            'study.exam_convergence.reinforcement.maximum_question_budget_per_cycle' => 20,
            'study.exam_convergence.reentry.general_exam_window_attempts' => 3,
            'study.exam_convergence.reentry.required_failure_attempts' => 2,
            'study.exam_convergence.deadline.general_practice_days' => 30,
            'study.exam_convergence.deadline.exam_mode_days' => 14,
            'study.exam_convergence.practice.normal_question_count' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weakness_graduates_after_enough_targeted_evidence_and_returns_to_general_practice(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 88, 'DNS', false, 3);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 92, 'DNS', false, 4);

        $result = $this->policy($plan, $task, 'DNS');
        $topic = $this->topic($result, 'DNS');

        $this->assertSame('graduated', $topic['status']);
        $this->assertSame(2, $topic['targeted_sessions']);
        $this->assertGreaterThanOrEqual(8, $topic['targeted_question_budget']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $result['phase'],
        );
        $this->assertTrue($result['general_return_required']);
        $this->assertContains('DNS', $result['graduated_topics']);
    }

    public function test_insufficient_reinforcement_evidence_keeps_topic_active(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 90, 'DNS', false, 3);

        $result = $this->policy($plan, $task, 'DNS');
        $topic = $this->topic($result, 'DNS');

        $this->assertSame('active', $topic['status']);
        $this->assertSame(1, $topic['targeted_sessions']);
        $this->assertGreaterThan(0, $topic['remaining_sessions_to_graduation']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            $result['phase'],
        );
    }

    public function test_score_below_threshold_does_not_graduate_topic(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 90, 'DNS', false, 3);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 76, 'DNS', false, 4);

        $result = $this->policy($plan, $task, 'DNS');

        $this->assertSame('active', $this->topic($result, 'DNS')['status']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            $result['phase'],
        );
    }

    public function test_overtraining_cap_stops_focused_reinforcement_even_without_graduation(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 65, 'DNS', true, 3);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 70, 'DNS', true, 4);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 72, 'DNS', true, 5);

        $result = $this->policy($plan, $task, 'DNS');
        $topic = $this->topic($result, 'DNS');

        $this->assertSame('capped', $topic['status']);
        $this->assertSame(3, $topic['targeted_sessions']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $result['phase'],
        );
        $this->assertContains('DNS', $result['capped_topics']);
    }

    public function test_strategy_forces_broad_general_practice_after_graduation(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'Database', 1);
        $this->detectWeakness($user, $plan, $task, 'Database', 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 86, 'Database', false, 3);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 90, 'Database', false, 4);

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($task),
        );

        $this->assertSame('general_practice', $strategy['key']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            data_get($strategy, 'learning_phase.phase'),
        );
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame([
            'primary' => 0,
            'secondary' => 0,
            'diagnostic' => 10,
        ], $strategy['question_mix']);
    }

    public function test_graduated_topic_reopens_only_after_repeated_broad_failures(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->graduate($user, $plan, $task, 'DNS');

        $this->attempt($user, $plan, $task, 'general_practice', 78, 'DNS', true, 5);
        $afterOne = $this->policy($plan, $task, 'DNS');

        $this->assertSame('graduated', $this->topic($afterOne, 'DNS')['status']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $afterOne['phase'],
        );

        $this->attempt($user, $plan, $task, 'general_practice', 74, 'DNS', true, 6);
        $afterTwo = $this->policy($plan, $task, 'DNS');

        $this->assertSame('reopened', $this->topic($afterTwo, 'DNS')['status']);
        $this->assertContains('DNS', $afterTwo['reopened_topics']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            $afterTwo['phase'],
        );
    }

    public function test_single_broad_practice_mistake_does_not_immediately_reopen_graduated_topic(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->graduate($user, $plan, $task, 'DNS');
        $this->attempt($user, $plan, $task, 'general_practice', 80, 'DNS', true, 5);

        $result = $this->policy($plan, $task, 'DNS');
        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($task),
        );

        $this->assertSame('graduated', $this->topic($result, 'DNS')['status']);
        $this->assertNotContains('DNS', $result['reopened_topics']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $result['phase'],
        );
        $this->assertNotSame('weakness_reinforcement', $strategy['key']);
        $this->assertSame([], $strategy['focus_topics']);
    }

    public function test_graduating_one_topic_forces_general_before_another_active_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->detectWeakness($user, $plan, $task, 'Database', 1);
        $this->detectWeakness($user, $plan, $task, 'Network', 2);
        $this->detectWeakness($user, $plan, $task, 'Database', 3);
        $this->detectWeakness($user, $plan, $task, 'Network', 4);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 90, 'Database', false, 5);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 92, 'Database', false, 6);

        $result = app(StudyExamConvergencePolicyService::class)->resolve(
            $plan,
            $task,
            $this->attempts($task),
            [
                'ranked' => [
                    ['topic' => 'Network', 'priority_score' => 1.0],
                    ['topic' => 'Database', 'priority_score' => 0.8],
                ],
                'primary_topics' => ['Network'],
                'secondary_topics' => [],
            ],
        );

        $this->assertSame('graduated', $this->topic($result, 'Database')['status']);
        $this->assertSame('active', $this->topic($result, 'Network')['status']);
        $this->assertTrue($result['general_return_required']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $result['phase'],
        );
    }

    public function test_exam_date_within_fourteen_days_overrides_new_weakness_drilling(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-10-15');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($task),
        );

        $this->assertSame('exam_mode', $strategy['key']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
            data_get($strategy, 'learning_phase.phase'),
        );
        $this->assertSame(10, data_get($strategy, 'learning_phase.days_until_exam'));
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame(10, $strategy['question_mix']['diagnostic']);
    }

    public function test_general_practice_window_overrides_local_weakness_reinforcement(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-10-25');

        $this->detectWeakness($user, $plan, $task, 'DNS', 1);
        $this->detectWeakness($user, $plan, $task, 'DNS', 2);

        $result = $this->policy($plan, $task, 'DNS');

        $this->assertSame(20, $result['days_until_exam']);
        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            $result['phase'],
        );
    }

    public function test_ai_practice_suggestion_cannot_override_general_or_exam_phase(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $latest = $this->attempt(
            $user,
            $plan,
            $task,
            'general_practice',
            70,
            'DNS',
            true,
            1,
            nextKind: 'practice',
        );

        $progression = app(StudyTaskProgressionService::class);

        $general = $progression->resolve(
            $plan,
            $task,
            collect([$latest]),
            ['phase' => StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE],
        );
        $exam = $progression->resolve(
            $plan,
            $task,
            collect([$latest]),
            ['phase' => StudyExamConvergencePolicyService::PHASE_EXAM_MODE],
        );

        $this->assertSame('general_practice_return', $general['kind']);
        $this->assertSame('exam_mode', $exam['kind']);
    }

    public function test_exam_mode_prompt_keeps_ap_format_and_forbids_micro_topic_expansion(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-10-15');

        $this->detectWeakness($user, $plan, $task, 'Database', 1);
        $this->detectWeakness($user, $plan, $task, 'Database', 2);

        $attempts = $this->attempts($task);
        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $attempts,
        );
        $prompt = app(StudyPracticePromptService::class)->generationPrompt(
            $plan,
            $task,
            $attempts,
            $strategy,
        );

        $this->assertStringContainsString('Phase: exam_mode / Exam Mode', $prompt);
        $this->assertStringContainsString('本番に近い分野バランス', $prompt);
        $this->assertStringContainsString('新しい細かい弱点探索', $prompt);
        $this->assertStringContainsString('AI側で延長・解除・再開しない', $prompt);
        $this->assertStringContainsString('AP科目Aの本番想定', $prompt);
    }

    public function test_current_ap_like_eighty_four_percent_case_converges_back_to_general_practice_after_short_reinforcement(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->attempt(
            $user,
            $plan,
            $task,
            'diagnosis',
            84,
            'Database',
            true,
            1,
            questionCount: 10,
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            'diagnosis',
            82,
            'Database',
            true,
            2,
            questionCount: 10,
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            'weakness_reinforcement',
            90,
            'Database',
            false,
            3,
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            'weakness_reinforcement',
            92,
            'Database',
            false,
            4,
        );

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($task),
        );

        $this->assertSame('general_practice', $strategy['key']);
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame(10, $strategy['question_mix']['diagnostic']);
        $this->assertContains(
            'Database',
            data_get($strategy, 'weakness_control.graduated_topics', []),
        );
        $this->assertStringContainsString(
            '総合演習',
            (string) $strategy['label'],
        );
    }

    public function test_existing_history_without_phase_snapshot_remains_compatible(): void
    {
        [$user, $plan, $task] = $this->scenario('2026-11-10');

        $this->legacyAttempt($user, $plan, $task, 64, 'DNS', 1);
        $this->legacyAttempt($user, $plan, $task, 66, 'DNS', 2);

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($task),
        );

        $this->assertSame(
            StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            data_get($strategy, 'learning_phase.phase'),
        );
        $this->assertContains(
            'DNS',
            data_get($strategy, 'learning_phase.active_topics', []),
        );
    }

    private function graduate(
        User $user,
        Plan $plan,
        Task $task,
        string $topic,
    ): void {
        $this->detectWeakness($user, $plan, $task, $topic, 1);
        $this->detectWeakness($user, $plan, $task, $topic, 2);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 88, $topic, false, 3);
        $this->attempt($user, $plan, $task, 'weakness_reinforcement', 92, $topic, false, 4);
    }

    private function detectWeakness(
        User $user,
        Plan $plan,
        Task $task,
        string $topic,
        int $order,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            'diagnosis',
            70,
            $topic,
            true,
            $order,
        );
    }

    private function policy(
        Plan $plan,
        Task $task,
        string $topic,
    ): array {
        return app(StudyExamConvergencePolicyService::class)->resolve(
            $plan,
            $task,
            $this->attempts($task),
            [
                'ranked' => [[
                    'topic' => $topic,
                    'priority_score' => 1.0,
                ]],
            ],
        );
    }

    private function topic(array $result, string $topic): array
    {
        return collect($result['topic_states'])
            ->firstWhere('topic', $topic)
            ?? [];
    }

    private function attempts(Task $task)
    {
        return StudyPracticeAttempt::query()
            ->where('task_id', $task->id)
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        string $phase,
        int $score,
        string $topic,
        bool $blocking,
        int $order,
        string $nextKind = 'continue_task',
        int $questionCount = 10,
    ): StudyPracticeAttempt {
        $strategyKey = match ($phase) {
            'diagnosis' => 'baseline_assessment',
            'weakness_reinforcement' => 'weakness_reinforcement',
            'exam_mode' => 'exam_mode',
            default => 'general_practice',
        };

        $isReinforcement = $phase === 'weakness_reinforcement';
        $primaryCount = $isReinforcement ? 5 : 0;

        $session = StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_COMPLETED,
            'strategy' => $strategyKey,
            'strategy_version' => 'v3',
            'selector_type' => 'test',
            'selector_version' => 'test-v1',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => [
                    'key' => $strategyKey,
                    'version' => 'v3',
                    'learning_phase' => [
                        'phase' => $phase,
                    ],
                    'focus_topics' => $isReinforcement ? [$topic] : [],
                    'weakness_priority' => [
                        'primary_topics' => $isReinforcement ? [$topic] : [],
                        'secondary_topics' => [],
                    ],
                    'question_mix' => [
                        'primary' => $primaryCount,
                        'secondary' => 0,
                        'diagnostic' => $questionCount - $primaryCount,
                    ],
                    'target_question_count' => $questionCount,
                ],
            ],
            'completed_at' => now()->subMinutes(100 - $order),
            'created_at' => now()->subMinutes(100 - $order),
            'updated_at' => now()->subMinutes(100 - $order),
        ]);

        $feedback = [[
            'question_id' => 'q1',
            'correctness' => $blocking ? 'incorrect' : 'correct',
            'feedback' => '',
            'reasoning_feedback' => '',
            'error_type' => $blocking ? 'concept_gap' : 'none',
            'weakness_topics' => $blocking ? [$topic] : [],
            'misconceptions' => [],
        ]];

        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => $strategyKey,
            'questions' => collect(range(1, $questionCount))
                ->map(fn ($index) => ['id' => 'q'.$index])
                ->all(),
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => $feedback,
                'strengths' => $blocking ? [] : [$topic],
                'weaknesses' => $blocking ? [$topic] : [],
                'recommended_task_progress_percent' => 60,
                'evidence_summary' => 'V56.0 test',
                'next_action' => '次へ',
                'next_step' => [
                    'kind' => $nextKind,
                    'label' => '次へ',
                    'reason' => '',
                    'focus_topics' => $nextKind === 'practice'
                        ? [$topic]
                        : [],
                    'question_count' => $nextKind === 'practice'
                        ? 5
                        : 0,
                ],
            ],
            'score_percent' => $score,
            'strengths' => $blocking ? [] : [$topic],
            'weaknesses' => $blocking ? [$topic] : [],
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'V56.0 test',
            'next_action' => '次へ',
            'created_at' => now()->subMinutes(100 - $order),
            'updated_at' => now()->subMinutes(100 - $order),
        ]);
    }

    private function legacyAttempt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
        string $topic,
        int $order,
    ): StudyPracticeAttempt {
        return StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => 'legacy',
            'questions' => [['id' => 'q1']],
            'answers' => [],
            'assessment' => [
                'question_feedback' => [[
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                    'error_type' => 'concept_gap',
                    'weakness_topics' => [$topic],
                    'misconceptions' => [],
                ]],
                'next_step' => [
                    'kind' => 'practice',
                    'label' => '再確認',
                    'reason' => '',
                    'focus_topics' => [$topic],
                    'question_count' => 5,
                ],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => [$topic],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'legacy',
            'next_action' => '再確認',
            'created_at' => now()->subMinutes(100 - $order),
            'updated_at' => now()->subMinutes(100 - $order),
        ]);
    }

    private function scenario(string $examDate): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験 科目A',
            'description' => '科目Aで合格点を取る',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => $examDate,
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'exam_title' => '応用情報技術者試験 科目A',
            'exam_date_text' => $examDate,
            'exam_date' => $examDate,
            'confidence' => 1,
            'extraction_version' => 'v56-test',
            'confirmed_at' => now(),
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'AP科目Aの総合演習と弱点補完',
            'description' => 'Network Database Performance Availability Quality',
            'estimated_minutes' => 600,
            'remaining_minutes' => 360,
            'progress_percent' => 60,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
