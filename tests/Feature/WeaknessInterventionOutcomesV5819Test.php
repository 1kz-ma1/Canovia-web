<?php

namespace Tests\Feature;

use App\Intelligence\Study\StudyWeaknessInterventionOutcomeService;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyExamConvergencePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeaknessInterventionOutcomesV5819Test extends TestCase
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

    public function test_no_reinforcement_cycle_returns_unavailable(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'correct'],
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertFalse($result['available']);
        $this->assertSame(
            'no_reinforcement_cycle',
            $result['reason'],
        );
        $this->assertSame([], $result['topics']);
    }

    public function test_completed_cycle_without_usable_baseline_waits_for_baseline(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['correct', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'データベース',
        );

        $this->assertSame(
            'waiting_baseline',
            $topic['status'],
        );
        $this->assertSame(
            1,
            data_get($topic, 'baseline.question_count'),
        );
    }

    public function test_baseline_requires_two_unique_question_references(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect'],
            refs: ['same_q'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect'],
            refs: ['same_q'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'ネットワーク',
        );

        $this->assertSame(
            'waiting_baseline',
            $topic['status'],
        );
        $this->assertSame(
            2,
            data_get($topic, 'baseline.question_count'),
        );
        $this->assertSame(
            1,
            data_get($topic, 'baseline.unique_question_count'),
        );
    }

    public function test_in_progress_reinforcement_is_not_treated_as_improvement(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            '性能・可用性',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            '性能・可用性',
            ['correct', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            '性能・可用性',
        );

        $this->assertSame(
            'reinforcing',
            $topic['status'],
        );
        $this->assertSame(
            100,
            data_get(
                $topic,
                'intervention.observed_correct_rate_percent',
            ),
        );
        $this->assertNull(
            $topic['observed_delta_points'],
        );
        $this->assertSame(
            0,
            data_get($topic, 'after.question_count'),
        );
    }

    public function test_completed_cycle_waits_until_two_unique_broad_recheck_questions_exist(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'データベース',
        );

        $this->assertSame(
            'waiting_recheck',
            $topic['status'],
        );
        $this->assertSame(
            1,
            data_get($topic, 'after.question_count'),
        );
        $this->assertNull(
            $topic['observed_delta_points'],
        );
    }

    public function test_positive_fifteen_or_more_is_improved_observation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'correct'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'ネットワーク',
        );

        $this->assertSame(
            'improved_observation',
            $topic['status'],
        );
        $this->assertSame(
            50,
            data_get(
                $topic,
                'baseline.observed_correct_rate_percent',
            ),
        );
        $this->assertSame(
            100,
            data_get(
                $topic,
                'after.observed_correct_rate_percent',
            ),
        );
        $this->assertSame(
            50,
            $topic['observed_delta_points'],
        );
        $this->assertSame('low', $topic['confidence']);
    }

    public function test_negative_fifteen_or_less_is_regressed_observation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['correct', 'correct'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['incorrect', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'データベース',
        );

        $this->assertSame(
            'regressed_observation',
            $topic['status'],
        );
        $this->assertSame(
            -50,
            $topic['observed_delta_points'],
        );
    }

    public function test_change_inside_fourteen_points_is_stable_observation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct', 'correct', 'incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_EXAM_MODE,
            'ネットワーク',
            ['correct', 'correct', 'correct', 'incorrect', 'incorrect'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'ネットワーク',
        );

        $this->assertSame(
            'stable_observation',
            $topic['status'],
        );
        $this->assertSame(
            0,
            $topic['observed_delta_points'],
        );
        $this->assertSame(
            'moderate',
            $topic['confidence'],
        );
    }

    public function test_partial_is_not_counted_as_correct(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['partial', 'correct'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['partial', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'データベース',
        );

        $this->assertSame(
            50,
            data_get(
                $topic,
                'baseline.observed_correct_rate_percent',
            ),
        );
        $this->assertSame(
            50,
            data_get(
                $topic,
                'after.observed_correct_rate_percent',
            ),
        );
    }

    public function test_actor_and_task_isolation(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $otherUser = User::factory()->create();
        $otherTask = $this->task(
            $plan,
            '別Task',
            2,
        );

        $this->practice(
            $otherUser,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $otherUser,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $otherUser,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct'],
        );

        $this->practice(
            $user,
            $plan,
            $otherTask,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $otherTask,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $otherTask,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct'],
        );

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['topics']);
    }

    public function test_latest_cycle_for_topic_is_surfaced(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'データベース',
            ['correct', 'correct'],
        );

        $this->reinforcement(
            $user,
            $plan,
            $task,
            'データベース',
            ['correct', 'correct'],
        );

        $topic = $this->topic(
            $this->project($plan, $task, $user),
            'データベース',
        );

        $this->assertSame(2, $topic['cycle_count']);
        $this->assertSame(
            'reinforcing',
            $topic['status'],
        );
    }

    public function test_history_is_bounded_to_latest_one_hundred_twenty_attempts(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'incorrect'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );

        for ($i = 0; $i < 121; $i++) {
            $this->practice(
                $user,
                $plan,
                $task,
                StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
                'データベース',
                ['correct'],
            );
        }

        $result = $this->project(
            $plan,
            $task,
            $user,
        );

        $this->assertTrue(
            $result['history_truncated'],
        );
        $this->assertSame(
            120,
            $result['attempts_considered'],
        );
        $this->assertFalse(
            $result['available'],
        );
    }

    public function test_analysis_surface_renders_without_provider_call_or_mutation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['incorrect', 'correct'],
        );
        $this->reinforcement(
            $user,
            $plan,
            $task,
            'ネットワーク',
            ['correct', 'correct'],
        );
        $this->practice(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_GENERAL_PRACTICE,
            'ネットワーク',
            ['correct', 'correct'],
        );

        $beforeAttempts =
            StudyPracticeAttempt::query()->count();
        $beforeSessions =
            StudyPracticeSession::query()->count();

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-surface="weakness_intervention_outcomes"',
                false,
            )
            ->assertSee(
                'data-weakness-intervention-outcomes',
                false,
            )
            ->assertSee(
                '弱点補強の前後をBroad Practiceで比較',
            )
            ->assertSee('ネットワーク')
            ->assertSee('+50pt');

        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );
        $this->assertSame(
            $beforeSessions,
            StudyPracticeSession::query()->count(),
        );

        Http::assertNothingSent();
    }

    private function project(
        Plan $plan,
        Task $task,
        User $user,
    ): array {
        return app(
            StudyWeaknessInterventionOutcomeService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );
    }

    private function topic(
        array $result,
        string $topic,
    ): array {
        return collect($result['topics'])
            ->firstWhere('topic', $topic);
    }

    private function reinforcement(
        User $user,
        Plan $plan,
        Task $task,
        string $topic,
        array $correctness,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT,
            $topic,
            $correctness,
            null,
            true,
        );
    }

    private function practice(
        User $user,
        Plan $plan,
        Task $task,
        string $phase,
        string $topic,
        array $correctness,
        ?array $refs = null,
    ): StudyPracticeAttempt {
        return $this->attempt(
            $user,
            $plan,
            $task,
            $phase,
            $topic,
            $correctness,
            $refs,
            false,
        );
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        string $phase,
        string $topic,
        array $correctness,
        ?array $refs,
        bool $targeted,
    ): StudyPracticeAttempt {
        $refs ??= collect($correctness)
            ->keys()
            ->map(
                fn ($index) =>
                    Str::lower(Str::random(6))
                    .'_'.$index,
            )
            ->all();

        $questions = [];
        $feedback = [];
        $selected = [];

        foreach ($correctness as $index => $value) {
            $ref = (string) (
                $refs[$index]
                ?? Str::lower(Str::random(8))
            );

            $questions[] = [
                'id' => $ref,
                'topic' => $topic,
                'parent_topic' => $topic,
                'prompt' => $topic.' '.$ref,
            ];
            $selected[] = [
                'question_ref' => $ref,
                'question_id' => null,
                'selection_parent_topic' => $topic,
            ];
            $feedback[] = [
                'question_id' => $ref,
                'correctness' => $value,
                'error_type' =>
                    $value === 'correct'
                        ? 'none'
                        : 'concept_gap',
                'weakness_topics' =>
                    $value === 'correct'
                        ? []
                        : [$topic],
            ];
        }

        $strategy = [
            'key' => match ($phase) {
                StudyExamConvergencePolicyService::PHASE_WEAKNESS_REINFORCEMENT =>
                    'weakness_reinforcement',
                StudyExamConvergencePolicyService::PHASE_EXAM_MODE =>
                    'exam_mode',
                StudyExamConvergencePolicyService::PHASE_DIAGNOSIS =>
                    'baseline_assessment',
                default => 'general_practice',
            },
            'learning_phase' => [
                'phase' => $phase,
            ],
            'focus_topics' =>
                $targeted ? [$topic] : [],
            'weakness_control' => [
                'active_topics' =>
                    $targeted ? [$topic] : [],
            ],
            'weakness_priority' => [
                'primary_topics' =>
                    $targeted ? [$topic] : [],
                'secondary_topics' => [],
            ],
            'question_mix' => [
                'primary' =>
                    $targeted
                        ? count($correctness)
                        : 0,
                'secondary' => 0,
                'diagnostic' =>
                    $targeted
                        ? 0
                        : count($correctness),
            ],
        ];

        $createdAt = now()->addSeconds(
            StudyPracticeAttempt::query()->count()
            + 1,
        );

        $session = StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' =>
                StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => $strategy['key'],
            'strategy_version' => 'v4',
            'selector_type' => 'test',
            'selector_version' => 'v58.19',
            'question_provider' => 'test',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'test',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => $strategy,
            ],
            'selected_questions' => $selected,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' =>
                $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => 'V58.19',
            'questions' => $questions,
            'answers' => [],
            'assessment' => [
                'question_feedback' => $feedback,
            ],
            'score_percent' =>
                count($correctness) > 0
                    ? (int) round(
                        collect($correctness)
                            ->filter(
                                fn ($item) =>
                                    $item === 'correct',
                            )
                            ->count()
                        / count($correctness)
                        * 100,
                    )
                    : 0,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' =>
                (int) $task->progress_percent,
            'evidence_summary' => 'V58.19',
            'next_action' => 'continue',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @return array{0:User,1:Plan,2:Task}
     */
    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報技術者試験 合格',
            'description' => '科目Aの弱点を補強する',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addDays(35),
            'is_public' => false,
        ]);

        $task = $this->task(
            $plan,
            '科目Aの分野横断問題演習を進める',
            1,
        );

        return [$user, $plan, $task];
    }

    private function task(
        Plan $plan,
        string $title,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => '弱点補強と総合演習を繰り返す',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
