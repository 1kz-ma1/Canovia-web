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
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weakness_graduates_after_enough_stable_targeted_evidence_and_returns_to_general_practice(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(4));
        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(3));
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            90,
            now()->subHours(2),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            92,
            now()->subHour(),
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('general_practice', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertSame('general_practice', $strategy['key']);
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame([
            'primary' => 0,
            'secondary' => 0,
            'diagnostic' => 10,
        ], $strategy['question_mix']);
        $this->assertContains(
            'DNS',
            data_get($strategy, 'weakness_control.graduated_topics', []),
        );

        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('graduated', $state['status']);
        $this->assertSame(2, $state['targeted_sessions']);
        $this->assertGreaterThanOrEqual(
            8,
            $state['targeted_question_budget'],
        );
        $this->assertTrue(data_get(
            $strategy,
            'learning_phase.general_return_required',
        ));
    }

    public function test_insufficient_targeted_evidence_keeps_weakness_reinforcement(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(3));
        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(2));
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            92,
            now()->subHour(),
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('weakness_reinforcement', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertSame(['DNS'], data_get(
            $strategy,
            'weakness_control.active_topics',
        ));

        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('active', $state['status']);
        $this->assertSame(1, $state['remaining_sessions_to_graduation']);
        $this->assertGreaterThan(
            0,
            $state['remaining_question_budget_to_graduation'],
        );
    }

    public function test_score_below_threshold_prevents_graduation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(4));
        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(3));
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            90,
            now()->subHours(2),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            76,
            now()->subHour(),
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('weakness_reinforcement', data_get(
            $strategy,
            'learning_phase.phase',
        ));

        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('active', $state['status']);
        $this->assertSame([90, 76], $state['latest_targeted_scores']);
    }

    public function test_reinforcement_cap_stops_infinite_deep_dive_and_forces_general_practice(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(5));
        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHours(4));
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            65,
            now()->subHours(3),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            70,
            now()->subHours(2),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            74,
            now()->subHour(),
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('general_practice', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertContains(
            'DNS',
            data_get($strategy, 'weakness_control.capped_topics', []),
        );
        $this->assertSame([], $strategy['focus_topics']);

        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('capped', $state['status']);
        $this->assertSame(3, $state['targeted_sessions']);
    }

    public function test_one_broad_mistake_does_not_reopen_a_graduated_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->graduatedDnsHistory($user, $plan, $task);

        $this->broadMiss(
            $user,
            $plan,
            $task,
            'DNS',
            now(),
        );

        $strategy = $this->strategy($plan, $task);
        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('graduated', $state['status']);
        $this->assertSame('general_practice', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertNotContains(
            'DNS',
            data_get($strategy, 'weakness_control.reopened_topics', []),
        );
    }

    public function test_repeated_broad_failures_reopen_a_graduated_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->graduatedDnsHistory($user, $plan, $task);

        $this->broadMiss(
            $user,
            $plan,
            $task,
            'DNS',
            now()->subMinutes(20),
        );
        $this->broadSuccess(
            $user,
            $plan,
            $task,
            now()->subMinutes(10),
        );
        $this->broadMiss(
            $user,
            $plan,
            $task,
            'DNS',
            now(),
        );

        $strategy = $this->strategy($plan, $task);
        $state = collect(data_get(
            $strategy,
            'weakness_control.topic_states',
            [],
        ))->firstWhere('topic', 'DNS');

        $this->assertSame('reopened', $state['status']);
        $this->assertContains(
            'DNS',
            data_get($strategy, 'weakness_control.reopened_topics', []),
        );
        $this->assertSame('weakness_reinforcement', data_get(
            $strategy,
            'learning_phase.phase',
        ));
    }

    public function test_confirmed_exam_date_drives_exam_mode_before_plan_deadline(): void
    {
        [$user, $plan, $task] = $this->scenario(
            deadline: '2026-12-15',
        );

        StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'exam_title' => '応用情報 科目A',
            'exam_date' => '2026-10-15',
            'confidence' => 1,
            'extraction_version' => 'test',
            'confirmed_at' => now(),
        ]);

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('exam_mode', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertSame('confirmed_scope_capture', data_get(
            $strategy,
            'learning_phase.exam_date_source',
        ));
        $this->assertSame(10, data_get(
            $strategy,
            'learning_phase.days_until_exam',
        ));
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame(10, $strategy['question_mix']['diagnostic']);
    }

    public function test_general_practice_is_preferred_inside_thirty_day_window(): void
    {
        [$user, $plan, $task] = $this->scenario(
            deadline: '2026-10-25',
        );

        $this->broadMiss($user, $plan, $task, 'DNS', now()->subHour());
        $this->broadMiss($user, $plan, $task, 'DNS', now());

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('general_practice', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertSame(20, data_get(
            $strategy,
            'learning_phase.days_until_exam',
        ));
        $this->assertSame([], $strategy['focus_topics']);
    }

    public function test_current_ap_84_percent_case_does_not_restart_every_observed_area_as_focused_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->attempt(
            $user,
            $plan,
            $task,
            phase: 'diagnosis',
            strategyKey: 'baseline_assessment',
            score: 84,
            weaknesses: [
                'Network',
                'Database',
                'Performance / Availability calculations',
                'Quality characteristics',
            ],
            blockingTopics: [
                'Network',
                'Database',
                'Performance / Availability calculations',
                'Quality characteristics',
            ],
            createdAt: now(),
            questionCount: 10,
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('general_practice', data_get(
            $strategy,
            'learning_phase.phase',
        ));
        $this->assertSame([], data_get(
            $strategy,
            'weakness_control.active_topics',
        ));
        $this->assertSame([
            'primary' => 0,
            'secondary' => 0,
            'diagnostic' => 10,
        ], $strategy['question_mix']);
    }

    public function test_prompt_makes_phase_control_authoritative_over_ai_variants(): void
    {
        [, $plan, $task] = $this->scenario(
            deadline: '2026-10-15',
        );

        $strategy = $this->strategy($plan, $task);
        $prompt = app(StudyPracticePromptService::class)
            ->generationPrompt($plan, $task, collect(), $strategy);

        $this->assertStringContainsString(
            'Phase: exam_mode / Exam Mode',
            $prompt,
        );
        $this->assertStringContainsString(
            '本番に近い分野バランス',
            $prompt,
        );
        $this->assertStringContainsString(
            'AIが「まだ別パターンを作れる」という理由だけで弱点補完を拡張しない',
            $prompt,
        );

        $evaluation = app(StudyPracticePromptService::class)
            ->evaluationPrompt(
                $plan,
                $task,
                [[
                    'id' => 'q1',
                    'prompt' => 'DNSについて選べ。',
                    'response_fields' => [],
                ]],
                [[
                    'question_id' => 'q1',
                    'fields' => [],
                ]],
            );

        $this->assertStringContainsString(
            'Weakness Reinforcementを続けるか・卒業するか・General Practiceへ戻すかはCanovia Policyが決める',
            $evaluation,
        );
        $this->assertStringContainsString(
            '最小の実用的Topic',
            $evaluation,
        );
    }

    private function graduatedDnsHistory(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $this->broadMiss(
            $user,
            $plan,
            $task,
            'DNS',
            now()->subHours(6),
        );
        $this->broadMiss(
            $user,
            $plan,
            $task,
            'DNS',
            now()->subHours(5),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            90,
            now()->subHours(4),
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'DNS',
            92,
            now()->subHours(3),
        );
    }

    private function broadMiss(
        User $user,
        Plan $plan,
        Task $task,
        string $topic,
        $createdAt,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            phase: 'general_practice',
            strategyKey: 'general_practice',
            score: 70,
            weaknesses: [$topic],
            blockingTopics: [$topic],
            createdAt: $createdAt,
            questionCount: 10,
        );
    }

    private function broadSuccess(
        User $user,
        Plan $plan,
        Task $task,
        $createdAt,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            phase: 'general_practice',
            strategyKey: 'general_practice',
            score: 90,
            weaknesses: [],
            blockingTopics: [],
            createdAt: $createdAt,
            questionCount: 10,
        );
    }

    private function reinforcement(
        User $user,
        Plan $plan,
        Task $task,
        string $topic,
        int $score,
        $createdAt,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            phase: 'weakness_reinforcement',
            strategyKey: 'weakness_reinforcement',
            score: $score,
            weaknesses: [],
            blockingTopics: [],
            createdAt: $createdAt,
            questionCount: 5,
            focusTopic: $topic,
        );
    }

    /**
     * @param array<int,string> $weaknesses
     * @param array<int,string> $blockingTopics
     */
    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        string $phase,
        string $strategyKey,
        int $score,
        array $weaknesses,
        array $blockingTopics,
        $createdAt,
        int $questionCount,
        ?string $focusTopic = null,
    ): StudyPracticeAttempt {
        $primaryTopics = $focusTopic ? [$focusTopic] : [];
        $primaryCount = $focusTopic ? $questionCount : 0;

        $strategy = [
            'key' => $strategyKey,
            'version' => 'v3',
            'learning_phase' => [
                'phase' => $phase,
            ],
            'focus_topics' => $focusTopic ? [$focusTopic] : [],
            'target_question_count' => $questionCount,
            'weakness_priority' => [
                'primary_topics' => $primaryTopics,
                'secondary_topics' => [],
            ],
            'question_mix' => [
                'primary' => $primaryCount,
                'secondary' => 0,
                'diagnostic' => $focusTopic
                    ? 0
                    : $questionCount,
            ],
        ];

        $session = StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => $strategyKey,
            'strategy_version' => 'v3',
            'selector_type' => 'test',
            'selector_version' => 'v1',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => $strategy,
            ],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $feedback = collect($blockingTopics)
            ->values()
            ->map(fn (string $topic, int $index) => [
                'question_id' => 'q'.($index + 1),
                'correctness' => 'incorrect',
                'feedback' => '',
                'reasoning_feedback' => '',
                'error_type' => 'concept_gap',
                'weakness_topics' => [$topic],
                'misconceptions' => [],
            ])
            ->all();

        $questions = collect(range(1, $questionCount))
            ->map(fn (int $index) => [
                'id' => 'q'.$index,
                'prompt' => 'test '.$index,
            ])
            ->all();

        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => 'AP Practice',
            'questions' => $questions,
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => $feedback,
                'strengths' => [],
                'weaknesses' => $weaknesses,
                'recommended_task_progress_percent' => 60,
                'evidence_summary' => 'test',
                'next_action' => '次へ',
                'next_step' => [
                    'kind' => 'practice',
                    'label' => 'さらに練習する',
                    'reason' => 'AI suggestion only',
                    'focus_topics' => $focusTopic
                        ? [$focusTopic]
                        : $weaknesses,
                    'question_count' => 5,
                ],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => $weaknesses,
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'test',
            'next_action' => '次へ',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function strategy(
        Plan $plan,
        Task $task,
    ): array {
        $attempts = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->latest('created_at')
            ->latest('id')
            ->get();

        return app(StudyPracticeStrategyService::class)
            ->build($plan, $task, $attempts);
    }

    private function scenario(
        string $deadline = '2026-11-10',
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報 科目A対策',
            'description' => '応用情報技術者試験 科目Aに合格する',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'auto',
            'start_date' => '2026-09-01',
            'deadline' => $deadline,
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'AP科目A 総合演習',
            'description' => 'Network Database 性能・可用性 品質特性を含む科目A対策',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
