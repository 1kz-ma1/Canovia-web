<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\ApSubjectAPlanWideWeaknessHandoffService;
use App\Services\StudyPracticeOrchestrator;
use App\Services\StudyPracticePromptService;
use App\Services\StudyPracticeStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanWideWeaknessHandoffV5818Test extends TestCase
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

    public function test_handoff_is_not_available_before_one_hundred_graded_questions(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario();
        $pack = $this->pack();
        $questions = $this->databaseQuestions($pack);

        $this->gradedVolume($user, $plan, $task, 99);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[1]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);

        $result = $this->handoff($plan, $task, $user);

        $this->assertFalse($result['available']);
        $this->assertFalse($result['eligible']);
        $this->assertSame('checkpoint_not_reached', $result['reason']);
        $this->assertSame(99, data_get($result, 'checkpoint.assessed_question_count'));
    }

    public function test_three_exposures_of_only_one_unique_question_do_not_create_plan_wide_candidate(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario();
        $pack = $this->pack();
        $question = $this->databaseQuestions($pack)[0];

        $this->gradedVolume($user, $plan, $task, 100);

        for ($i = 0; $i < 3; $i++) {
            $this->wrongObservation($user, $plan, $otherTask, $question);
        }

        $result = $this->handoff($plan, $task, $user);

        $this->assertTrue($result['available']);
        $this->assertFalse($result['eligible']);
        $this->assertSame(
            'no_confirmed_plan_wide_parent_weakness',
            $result['reason'],
        );
        $this->assertSame([], $result['candidate_topics']);
    }

    public function test_three_exposures_across_two_unique_questions_below_sixty_percent_create_candidate_across_tasks(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario();
        $pack = $this->pack();
        $questions = $this->databaseQuestions($pack);

        $this->gradedVolume($user, $plan, $task, 100);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[1]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);

        $result = $this->handoff($plan, $task, $user);
        $database = collect($result['candidate_topics'])
            ->firstWhere('topic', 'データベース');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['eligible']);
        $this->assertNotNull($database);
        $this->assertSame(3, $database['assessed_exposure_count']);
        $this->assertSame(2, $database['unique_question_count']);
        $this->assertSame(0, $database['observed_correct_rate_percent']);
        $this->assertSame(
            2,
            data_get($result, 'policy.minimum_unique_questions'),
        );
    }

    public function test_sixty_percent_or_more_is_not_promoted_to_candidate(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario();
        $pack = $this->pack();
        $questions = $this->databaseQuestions($pack);

        $this->gradedVolume($user, $plan, $task, 100);

        $this->bankObservation($user, $plan, $otherTask, $questions[0], 'correct');
        $this->bankObservation($user, $plan, $otherTask, $questions[1], 'correct');
        $this->bankObservation($user, $plan, $otherTask, $questions[0], 'incorrect');

        $result = $this->handoff($plan, $task, $user);

        $this->assertFalse($result['eligible']);
        $this->assertSame([], $result['candidate_topics']);
    }

    public function test_plan_wide_projection_is_actor_isolated(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario();
        $other = User::factory()->create();
        $pack = $this->pack();
        $questions = $this->databaseQuestions($pack);

        $this->gradedVolume($user, $plan, $task, 100);
        $this->wrongObservation($other, $plan, $otherTask, $questions[0]);
        $this->wrongObservation($other, $plan, $otherTask, $questions[1]);
        $this->wrongObservation($other, $plan, $otherTask, $questions[0]);

        $result = $this->handoff($plan, $task, $user);

        $this->assertTrue($result['available']);
        $this->assertFalse($result['eligible']);
        $this->assertSame([], $result['candidate_topics']);
    }

    public function test_broad_general_practice_applies_at_most_two_plan_wide_topics_and_keeps_focus_empty(): void
    {
        [, $plan, $task] = $this->scenario(deadlineDays: 20);

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            collect(),
            $this->eligibleHandoff([
                'データベース',
                '性能・可用性',
                'ネットワーク',
            ]),
        );

        $this->assertSame('general_practice', $strategy['key']);
        $this->assertSame('broad_assessment', data_get($strategy, 'routing_policy.task_mode'));
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertSame(
            ['データベース', '性能・可用性'],
            data_get($strategy, 'weakness_priority.primary_topics'),
        );
        $this->assertSame(2, (int) data_get($strategy, 'question_mix.primary'));
        $this->assertGreaterThanOrEqual(
            8,
            (int) data_get($strategy, 'question_mix.diagnostic'),
        );
        $this->assertTrue(
            (bool) data_get(
                $strategy,
                'routing_policy.plan_wide_weakness_handoff.applied',
            ),
        );

        $prompt = app(StudyPracticePromptService::class)
            ->generationPrompt($plan, $task, collect(), $strategy);

        $this->assertStringContainsString(
            '重点弱点: 2問 / topics: データベース / 性能・可用性',
            $prompt,
        );
    }

    public function test_local_broad_recheck_keeps_precedence_over_plan_wide_candidate(): void
    {
        [$user, $plan, $task] = $this->scenario(deadlineDays: 20);
        $pack = $this->pack();
        $dns = $this->question($pack, 'dns-local', 'DNS', 'ネットワーク');

        $this->localAttempt(
            $user,
            $plan,
            $task,
            $dns,
            'incorrect',
            'concept_gap',
            'DNS',
            now()->subHour(),
        );
        $this->localAttempt(
            $user,
            $plan,
            $task,
            $dns,
            'incorrect',
            'concept_gap',
            'DNS',
            now(),
        );

        $recent = $this->recentAttempts($plan, $task);
        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $recent,
            $this->eligibleHandoff([
                'データベース',
                '性能・可用性',
            ]),
        );

        $this->assertSame(
            ['DNS', 'データベース'],
            data_get($strategy, 'routing_policy.broad_recheck_topics'),
        );
        $this->assertSame(
            ['DNS', 'データベース'],
            data_get($strategy, 'weakness_priority.primary_topics'),
        );
        $this->assertSame(
            ['データベース'],
            data_get(
                $strategy,
                'routing_policy.plan_wide_weakness_handoff.applied_topics',
            ),
        );
        $this->assertSame(2, (int) data_get($strategy, 'question_mix.primary'));
        $this->assertGreaterThanOrEqual(8, (int) data_get($strategy, 'question_mix.diagnostic'));
    }

    public function test_exam_mode_and_focused_task_do_not_apply_plan_wide_handoff(): void
    {
        [, $examPlan, $examTask] = $this->scenario(
            deadlineDays: 5,
            mode: 'broad',
        );
        $exam = app(StudyPracticeStrategyService::class)->build(
            $examPlan,
            $examTask,
            collect(),
            $this->eligibleHandoff(['データベース']),
        );

        $this->assertSame('exam_mode', $exam['key']);
        $this->assertFalse(
            (bool) data_get(
                $exam,
                'routing_policy.plan_wide_weakness_handoff.applied',
            ),
        );
        $this->assertSame(
            'policy_phase_not_general_practice',
            data_get(
                $exam,
                'routing_policy.plan_wide_weakness_handoff.reason',
            ),
        );

        [, $focusedPlan, $focusedTask] = $this->scenario(
            deadlineDays: 20,
            mode: 'focused',
        );
        $focused = app(StudyPracticeStrategyService::class)->build(
            $focusedPlan,
            $focusedTask,
            collect(),
            $this->eligibleHandoff(['データベース']),
        );

        $this->assertFalse(
            (bool) data_get(
                $focused,
                'routing_policy.plan_wide_weakness_handoff.applied',
            ),
        );
        $this->assertSame(
            'task_mode_not_broad_assessment',
            data_get(
                $focused,
                'routing_policy.plan_wide_weakness_handoff.reason',
            ),
        );
    }

    public function test_current_task_cooldown_blocks_matching_plan_wide_topic(): void
    {
        [$user, $plan, $task] = $this->scenario(deadlineDays: 20);
        $pack = $this->pack();
        $database = $this->question(
            $pack,
            'database-cooldown',
            'データベース',
            'データベース',
        );

        $this->localAttempt(
            $user,
            $plan,
            $task,
            $database,
            'correct',
            'none',
            'データベース',
            now()->subHour(),
        );
        $this->localAttempt(
            $user,
            $plan,
            $task,
            $database,
            'correct',
            'none',
            'データベース',
            now(),
        );

        $strategy = app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->recentAttempts($plan, $task),
            $this->eligibleHandoff(['データベース']),
        );

        $this->assertContains(
            'データベース',
            array_merge(
                data_get($strategy, 'routing_policy.cooldown_topics', []),
                data_get($strategy, 'routing_policy.mastered_topics', []),
            ),
        );
        $this->assertFalse(
            (bool) data_get(
                $strategy,
                'routing_policy.plan_wide_weakness_handoff.applied',
            ),
        );
        $this->assertSame(
            'candidates_blocked_by_local_mastery',
            data_get(
                $strategy,
                'routing_policy.plan_wide_weakness_handoff.reason',
            ),
        );
        $this->assertSame([], data_get($strategy, 'weakness_priority.primary_topics'));
    }

    public function test_orchestrator_persists_handoff_snapshot_and_external_prompt_without_extra_ai_call(): void
    {
        [$user, $plan, $task] = $this->scenario(deadlineDays: 20);

        Http::fake();

        $session = app(StudyPracticeOrchestrator::class)->prepare(
            $plan,
            $task,
            collect(),
            $user->id,
            null,
            (string) Str::uuid(),
            'external_ai',
            $this->eligibleHandoff(['データベース']),
        );

        $this->assertTrue(
            (bool) data_get(
                $session->selection_context,
                'strategy.routing_policy.plan_wide_weakness_handoff.applied',
            ),
        );
        $this->assertSame(
            ['データベース'],
            data_get(
                $session->selection_context,
                'strategy.routing_policy.plan_wide_weakness_handoff.applied_topics',
            ),
        );
        $this->assertStringContainsString(
            '重点弱点: 2問 / topics: データベース',
            (string) data_get(
                $session->provider_payload,
                'generation_prompt',
                '',
            ),
        );

        Http::assertNothingSent();
    }

    public function test_practice_ui_explains_plan_wide_recheck_without_mutating_history(): void
    {
        [$user, $plan, $task, $otherTask] = $this->scenario(deadlineDays: 20);
        $pack = $this->pack(withFillers: true);
        $questions = $this->databaseQuestions($pack);

        $this->gradedVolume($user, $plan, $task, 100);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[1]);
        $this->wrongObservation($user, $plan, $otherTask, $questions[0]);

        $beforeAttempts = StudyPracticeAttempt::query()->count();
        $beforeSessions = StudyPracticeSession::query()->count();

        Http::fake();

        $this->actingAs($user)
            ->get(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-plan-wide-weakness-handoff', false)
            ->assertSee('100問後のPlan全体再確認')
            ->assertSee('データベース')
            ->assertSee('残りは分野横断の探索を維持します');

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

    private function handoff(
        Plan $plan,
        Task $task,
        User $user,
    ): array {
        return app(
            ApSubjectAPlanWideWeaknessHandoffService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );
    }

    /**
     * @param array<int,string> $topics
     * @return array<string,mixed>
     */
    private function eligibleHandoff(array $topics): array
    {
        return [
            'version' => 'v1',
            'available' => true,
            'eligible' => true,
            'reason' => 'eligible',
            'checkpoint' => [
                'assessed_question_count' => 100,
                'hundred_reached' => true,
            ],
            'candidate_topics' => collect($topics)
                ->values()
                ->map(fn (string $topic, int $index) => [
                    'topic' => $topic,
                    'domain' => 'テクノロジ',
                    'assessed_exposure_count' => 5 - min($index, 2),
                    'unique_question_count' => 2,
                    'observed_correct_rate_percent' => 20 + ($index * 10),
                    'source' => 'ap_subject_a_plan_wide_coverage',
                ])
                ->all(),
            'policy' => [
                'minimum_parent_exposures' => 3,
                'minimum_unique_questions' => 2,
                'maximum_correct_rate_percent' => 59,
                'maximum_candidates' => 5,
                'maximum_recheck_questions' => 2,
            ],
        ];
    }

    private function gradedVolume(
        User $user,
        Plan $plan,
        Task $task,
        int $count,
    ): void {
        $remaining = $count;
        $offset = 0;

        while ($remaining > 0) {
            $take = min(10, $remaining);
            $feedback = [];

            for ($i = 0; $i < $take; $i++) {
                $feedback[] = [
                    'question_id' => 'ai_'.($offset + $i),
                    'correctness' => 'correct',
                    'error_type' => 'none',
                    'weakness_topics' => [],
                ];
            }

            StudyPracticeAttempt::query()->create([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'request_hash' => hash('sha256', (string) Str::uuid()),
                'exercise_title' => 'AP cumulative volume',
                'questions' => [],
                'answers' => [],
                'assessment' => [
                    'question_feedback' => $feedback,
                    'next_step' => [
                        'kind' => 'continue_task',
                        'focus_topics' => [],
                    ],
                ],
                'score_percent' => 100,
                'strengths' => [],
                'weaknesses' => [],
                'recommended_task_progress_percent' => 50,
                'evidence_summary' => 'volume',
                'next_action' => 'continue',
                'created_at' => now()->addSeconds($offset),
                'updated_at' => now()->addSeconds($offset),
            ]);

            $remaining -= $take;
            $offset += $take;
        }
    }

    private function wrongObservation(
        User $user,
        Plan $plan,
        Task $task,
        Question $question,
    ): void {
        $this->bankObservation(
            $user,
            $plan,
            $task,
            $question,
            'incorrect',
        );
    }

    private function bankObservation(
        User $user,
        Plan $plan,
        Task $task,
        Question $question,
        string $correctness,
    ): void {
        $session = StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => 'general_practice',
            'strategy_version' => 'v4',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => [
                    'key' => 'general_practice',
                    'learning_phase' => ['phase' => 'general_practice'],
                    'focus_topics' => [],
                ],
            ],
            'selected_questions' => [[
                'question_ref' => 'bank_'.$question->id,
                'question_id' => $question->id,
                'source_type' => $question->source_type,
                'source_reference' => $question->source_reference,
                'selection_bucket' => 'diagnostic',
                'selection_domain' => 'テクノロジ',
                'selection_parent_topic' => 'データベース',
            ]],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $correct = $correctness === 'correct';

        StudyPracticeAttempt::query()->create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => 'AP Plan-wide observation',
            'questions' => [[
                'id' => 'bank_'.$question->id,
                'source_question_id' => $question->id,
                'prompt' => $question->prompt,
            ]],
            'answers' => [],
            'assessment' => [
                'question_feedback' => [[
                    'question_id' => 'bank_'.$question->id,
                    'correctness' => $correctness,
                    'error_type' => $correct ? 'none' : 'concept_gap',
                    'weakness_topics' => $correct ? [] : ['データベース'],
                ]],
                'next_step' => [
                    'kind' => 'continue_task',
                    'focus_topics' => [],
                ],
            ],
            'score_percent' => $correct ? 100 : 0,
            'strengths' => $correct ? ['データベース'] : [],
            'weaknesses' => $correct ? [] : ['データベース'],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'plan-wide observation',
            'next_action' => 'continue',
        ]);
    }

    private function localAttempt(
        User $user,
        Plan $plan,
        Task $task,
        Question $question,
        string $correctness,
        string $errorType,
        string $topic,
        $createdAt,
    ): StudyPracticeAttempt {
        $strategy = [
            'key' => 'general_practice',
            'version' => 'v4',
            'learning_phase' => ['phase' => 'general_practice'],
            'focus_topics' => [],
            'target_question_count' => 10,
            'weakness_priority' => [
                'primary_topics' => [],
                'secondary_topics' => [],
            ],
            'question_mix' => [
                'primary' => 0,
                'secondary' => 0,
                'diagnostic' => 10,
            ],
        ];

        $session = StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => 'general_practice',
            'strategy_version' => 'v4',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selection_context' => ['strategy' => $strategy],
            'selected_questions' => [[
                'question_ref' => 'bank_'.$question->id,
                'question_id' => $question->id,
                'source_type' => $question->source_type,
                'selection_parent_topic' => $this->parentFor($question),
            ]],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $correct = $correctness === 'correct';

        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => 'Local routing observation',
            'questions' => [[
                'id' => 'bank_'.$question->id,
                'source_question_id' => $question->id,
                'prompt' => $question->prompt,
                'topic' => $topic,
                'parent_topic' => $this->parentFor($question),
            ]],
            'answers' => [],
            'assessment' => [
                'question_feedback' => [[
                    'question_id' => 'bank_'.$question->id,
                    'correctness' => $correctness,
                    'error_type' => $correct ? 'none' : $errorType,
                    'weakness_topics' => $correct ? [] : [$topic],
                ]],
                'next_step' => [
                    'kind' => $correct ? 'continue_task' : 'practice',
                    'focus_topics' => $correct ? [] : [$topic],
                    'question_count' => $correct ? 0 : 5,
                ],
            ],
            'score_percent' => $correct ? 100 : 60,
            'strengths' => $correct ? [$topic] : [],
            'weaknesses' => $correct ? [] : [$topic],
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'local routing',
            'next_action' => 'continue',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function recentAttempts(
        Plan $plan,
        Task $task,
    ) {
        return StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return array{0:User,1:Plan,2:Task,3:Task}
     */
    private function scenario(
        int $deadlineDays = 20,
        string $mode = 'broad',
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験 科目A',
            'description' => '科目Aを100問演習して弱点を補強する',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addDays($deadlineDays),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $mode === 'broad'
                ? '科目Aの分野横断問題演習を進める'
                : '科目Aの弱点補強を進める',
            'description' => $mode === 'broad'
                ? '科目A全体を分野横断で確認して未知弱点を探索する'
                : '確認済みの弱点だけを重点補強する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 90,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        $otherTask = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '過去の科目A演習',
            'description' => '別Taskでの採点履歴',
            'estimated_minutes' => 60,
            'remaining_minutes' => 30,
            'progress_percent' => 50,
            'status' => 'doing',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        return [$user, $plan, $task, $otherTask];
    }

    private function pack(bool $withFillers = false): QuestionPack
    {
        $pack = QuestionPack::query()->create([
            'slug' => 'ap-v5818-'.Str::lower(Str::random(8)),
            'title' => 'AP科目A V58.18',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [
                'match_terms' => ['AP', '応用情報', '応用情報技術者試験'],
            ],
        ]);

        if ($withFillers) {
            $parents = [
                ['ネットワーク', 'DNS'],
                ['セキュリティ', '認証'],
                ['OS', 'スケジューリング'],
                ['アルゴリズム', '二分探索'],
                ['性能・可用性', 'MTBF'],
            ];

            $index = 1;
            foreach ($parents as [$parent, $topic]) {
                for ($i = 0; $i < 2; $i++) {
                    $this->question(
                        $pack,
                        'filler-'.$index++,
                        $topic.'-'.$i,
                        $parent,
                    );
                }
            }
        }

        return $pack;
    }

    /**
     * @return array<int,Question>
     */
    private function databaseQuestions(
        QuestionPack $pack,
    ): array {
        return [
            $this->question(
                $pack,
                'database-1-'.Str::lower(Str::random(4)),
                'SQL',
                'データベース',
            ),
            $this->question(
                $pack,
                'database-2-'.Str::lower(Str::random(4)),
                '正規化',
                'データベース',
            ),
        ];
    }

    private function question(
        QuestionPack $pack,
        string $key,
        string $topic,
        string $parent,
    ): Question {
        return Question::query()->create([
            'question_pack_id' => $pack->id,
            'external_key' => $key,
            'source_type' => 'canovia_original',
            'source_reference' => 'v58.18-test',
            'prompt' => $parent.' / '.$topic,
            'response_schema' => [[
                'id' => 'answer',
                'type' => 'single_choice',
                'label' => '回答',
                'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'A'],
                    ['id' => 'B', 'label' => 'B'],
                    ['id' => 'C', 'label' => 'C'],
                    ['id' => 'D', 'label' => 'D'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice',
                'field_id' => 'answer',
                'answer' => 'A',
            ],
            'learning_metadata' => [
                'concepts' => ['科目A', $parent, $topic],
                'weakness_targets' => [$topic],
                'tags' => ['科目A'],
                'keywords' => [],
            ],
            'difficulty' => 3,
            'sort_order' => $pack->questions()->count() + 1,
            'is_active' => true,
        ]);
    }

    private function parentFor(Question $question): ?string
    {
        return app(\App\Services\StudyTopicTaxonomyService::class)
            ->parentForQuestion($question);
    }
}
