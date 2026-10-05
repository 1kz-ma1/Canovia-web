<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\QuestionBankStudyPracticeQuestionProvider;
use App\Services\StudyPracticeRoutingPolicyService;
use App\Services\StudyPracticeStrategyService;
use App\Services\StudyWeaknessPrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeRoutingV564Test extends TestCase
{
    use RefreshDatabase;

    public function test_correct_reasoning_improvement_is_not_persisted_as_routing_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $key = "study_practice.{$plan->id}.{$task->id}";

        $question = [
            'id' => 'q1',
            'prompt' => 'GROUP BYとHAVINGについて正しいものを選べ。',
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
        ];

        $this->actingAs($user)
            ->withSession([
                $key => [
                    'title' => 'SQL確認',
                    'questions' => [$question],
                    'answers' => [[
                        'question_id' => 'q1',
                        'fields' => [[
                            'field_id' => 'answer',
                            'type' => 'single_choice',
                            'label' => '回答',
                            'value' => 'A',
                        ]],
                    ]],
                    'evaluation_prompt' => 'evaluation prompt',
                    'assessment' => null,
                    'attempt_id' => null,
                    'attempt_token' => (string) Str::uuid(),
                    'practice_session_id' => null,
                ],
            ])
            ->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), [
                'assessment_json' => json_encode([
                    'schema_version' => '1.0',
                    'flow' => 'study_assessment',
                    'target_plan' => ['id' => $plan->id],
                    'target_task' => ['id' => $task->id],
                    'score_percent' => 100,
                    'question_feedback' => [[
                        'question_id' => 'q1',
                        'correctness' => 'correct',
                        'feedback' => '正解です。',
                        'reasoning_feedback' => 'GROUP BYとHAVINGの役割をより具体的に言語化できるとよいです。',
                        'error_type' => 'none',
                        'weakness_topics' => ['SQL', 'HAVING'],
                        'misconceptions' => ['説明が抽象的'],
                    ]],
                    'strengths' => ['SQL'],
                    'weaknesses' => ['SQLの説明をより具体化する'],
                    'recommended_task_progress_percent' => 70,
                    'evidence_summary' => '正答。説明は簡潔。',
                    'next_action' => 'SQLをもう少し確認する',
                    'next_step' => [
                        'kind' => 'practice',
                        'label' => 'SQLの別パターンを確認する',
                        'reason' => '説明を具体化するため',
                        'focus_topics' => ['SQL', 'HAVING'],
                        'question_count' => 5,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->assertSessionHasNoErrors();

        $attempt = StudyPracticeAttempt::latest('id')->firstOrFail();

        $this->assertSame([], $attempt->weaknesses);
        $this->assertSame([], data_get(
            $attempt->assessment,
            'question_feedback.0.weakness_topics',
        ));
        $this->assertSame([], data_get(
            $attempt->assessment,
            'question_feedback.0.misconceptions',
        ));
        $this->assertStringContainsString(
            'より具体的',
            (string) data_get(
                $attempt->assessment,
                'question_feedback.0.reasoning_feedback',
            ),
        );

        $priority = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$attempt]),
            ['SQL', 'HAVING'],
            10,
        );

        $this->assertSame([], $priority['primary_topics']);
        $this->assertSame([], $priority['secondary_topics']);
    }

    public function test_two_correct_observations_put_subtopic_in_cooldown(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $pack = $this->pack();
        $having = $this->question($pack, 1, 'HAVING', 'データベース');

        $this->attempt($user, $plan, $task, $having, 'correct', 'none', 'HAVING', now()->subHour());
        $this->attempt($user, $plan, $task, $having, 'correct', 'none', 'HAVING', now());

        $routing = $this->routing($plan, $task);
        $state = collect($routing['subtopic_states'])->firstWhere('topic', 'HAVING');

        $this->assertNotNull($state);
        $this->assertSame('cooldown', $state['status']);
        $this->assertGreaterThanOrEqual(2, $state['consecutive_correct']);
        $this->assertGreaterThan(0, $state['cooldown_remaining_sets']);
    }

    public function test_cooldown_subtopic_is_suppressed_from_next_broad_bank_selection(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $pack = $this->diversePack();
        $having = $pack->questions()
            ->get()
            ->first(fn (Question $question) => in_array(
                'HAVING',
                $question->learning_metadata['weakness_targets'] ?? [],
                true,
            ));
        $this->assertInstanceOf(Question::class, $having);

        $this->attempt($user, $plan, $task, $having, 'correct', 'none', 'HAVING', now()->subHour());
        $this->attempt($user, $plan, $task, $having, 'correct', 'none', 'HAVING', now());

        $strategy = $this->strategy($plan, $task);
        $prepared = app(QuestionBankStudyPracticeQuestionProvider::class)->preparePartial(
            $plan,
            $task,
            $this->attempts($plan, $task),
            $strategy,
        );

        $selected = collect($prepared['selected_questions']);

        $this->assertContains('HAVING', data_get($strategy, 'routing_policy.cooldown_topics', []));
        $this->assertFalse($selected->contains(
            fn (array $item) => (int) $item['question_id'] === (int) $having->id,
        ));
        $this->assertFalse($selected->contains(
            fn (array $item) => (bool) ($item['selection_cooldown_match'] ?? false),
        ));
    }

    public function test_high_parent_recent_exposure_suppresses_other_subtopics_in_same_parent(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $pack = $this->diversePack(3);

        $database = $pack->questions()
            ->get()
            ->filter(fn (Question $q) => in_array(
                'データベース',
                $q->learning_metadata['concepts'] ?? [],
                true,
            ))
            ->take(5)
            ->pluck('id');

        $others = $pack->questions()
            ->whereNotIn('id', $database->all())
            ->take(5)
            ->pluck('id');

        $this->selectionSession(
            $user,
            $plan,
            $task,
            $database->concat($others)->values(),
            now()->subMinute(),
        );

        $strategy = $this->strategy($plan, $task);
        $databaseState = collect(data_get($strategy, 'routing_policy.parent_topics', []))
            ->firstWhere('parent_topic', 'データベース');

        $this->assertNotNull($databaseState);
        $this->assertGreaterThanOrEqual(0.50, $databaseState['recent_exposure']);
        $this->assertTrue($databaseState['suppressed']);
        $this->assertSame(1, $databaseState['broad_question_cap']);

        $prepared = app(QuestionBankStudyPracticeQuestionProvider::class)->preparePartial(
            $plan,
            $task,
            collect(),
            $strategy,
        );
        $selected = collect($prepared['selected_questions']);

        $this->assertLessThanOrEqual(
            1,
            $selected->where('selection_parent_topic', 'データベース')->count(),
        );
    }

    public function test_broad_assessment_caps_each_parent_and_preserves_cross_domain_coverage(): void
    {
        [, $plan, $task] = $this->scenario('broad');
        $this->balancedFiveParentPack();

        $strategy = $this->strategy($plan, $task);
        $prepared = app(QuestionBankStudyPracticeQuestionProvider::class)->prepare(
            $plan,
            $task,
            collect(),
            $strategy,
        );
        $selected = collect($prepared['selected_questions']);
        $byParent = $selected->countBy('selection_parent_topic');

        $this->assertSame('broad_assessment', data_get($strategy, 'routing_policy.task_mode'));
        $this->assertCount(10, $selected);
        $this->assertGreaterThanOrEqual(5, $byParent->count());
        $this->assertTrue($byParent->every(fn ($count) => (int) $count <= 2));
    }

    public function test_broad_assessment_limits_confirmed_weakness_to_recheck_budget(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $pack = $this->pack();
        $binary = $this->question($pack, 1, '二分探索', 'アルゴリズム');

        $this->attempt(
            $user,
            $plan,
            $task,
            $binary,
            'incorrect',
            'concept_gap',
            '二分探索',
            now()->subHour(),
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            $binary,
            'incorrect',
            'concept_gap',
            '二分探索',
            now(),
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('broad_assessment', $strategy['key']);
        $this->assertSame(
            'general_practice',
            data_get($strategy, 'learning_phase.phase'),
        );
        $this->assertSame(
            'broad_assessment',
            data_get($strategy, 'learning_phase.routing_override'),
        );
        $this->assertContains(
            '二分探索',
            data_get($strategy, 'weakness_priority.primary_topics', []),
        );
        $this->assertLessThanOrEqual(
            2,
            (int) data_get($strategy, 'question_mix.primary'),
        );
        $this->assertGreaterThanOrEqual(
            6,
            (int) data_get($strategy, 'question_mix.diagnostic'),
        );
        $this->assertSame([], $strategy['focus_topics']);
    }

    public function test_focused_remediation_prioritizes_repeated_confirmed_weakness(): void
    {
        [$user, $plan, $task] = $this->scenario('focused');
        $pack = $this->pack();
        $dns = $this->question($pack, 1, 'DNS', 'ネットワーク');

        $this->attempt($user, $plan, $task, $dns, 'incorrect', 'concept_gap', 'DNS', now()->subHour());
        $this->attempt($user, $plan, $task, $dns, 'incorrect', 'concept_gap', 'DNS', now());

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('focused_remediation', data_get($strategy, 'routing_policy.task_mode'));
        $this->assertSame('weakness_reinforcement', $strategy['key']);
        $this->assertContains('DNS', $strategy['focus_topics']);
        $this->assertContains('DNS', data_get($strategy, 'weakness_priority.primary_topics', []));
        $this->assertGreaterThan(
            (int) data_get($strategy, 'question_mix.diagnostic', 0),
            (int) data_get($strategy, 'question_mix.primary', 0),
        );
    }

    public function test_single_calculation_slip_or_careless_is_not_confirmed(): void
    {
        [$user, $plan, $task] = $this->scenario('focused');
        $pack = $this->pack();
        $availability = $this->question($pack, 1, '可用性', '性能・可用性');

        $attempt = $this->attempt(
            $user,
            $plan,
            $task,
            $availability,
            'incorrect',
            'calculation_slip',
            '可用性',
            now(),
        );

        $priority = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$attempt]),
            ['可用性'],
            10,
        );
        $state = collect($priority['ranked'])->firstWhere('topic', '可用性');

        $this->assertSame('suspected', $state['state']);
        $this->assertSame([], $priority['primary_topics']);
        $this->assertContains('可用性', $priority['secondary_topics']);
    }

    public function test_repeated_same_concept_error_promotes_suspected_to_confirmed(): void
    {
        [$user, $plan, $task] = $this->scenario('focused');
        $pack = $this->pack();
        $dns = $this->question($pack, 1, 'DNS', 'ネットワーク');

        $older = $this->attempt($user, $plan, $task, $dns, 'incorrect', 'concept_gap', 'DNS', now()->subHour());
        $latest = $this->attempt($user, $plan, $task, $dns, 'incorrect', 'concept_gap', 'DNS', now());

        $priority = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$latest, $older]),
            ['DNS'],
            10,
        );
        $state = collect($priority['ranked'])->firstWhere('topic', 'DNS');

        $this->assertSame('confirmed', $state['state']);
        $this->assertContains('DNS', $priority['primary_topics']);
    }

    public function test_sufficient_remediation_returns_to_exploration(): void
    {
        [$user, $plan, $task] = $this->scenario('focused');
        $pack = $this->pack();
        $dns = $this->question($pack, 1, 'DNS', 'ネットワーク');

        $this->attempt(
            $user, $plan, $task, $dns,
            'incorrect', 'concept_gap', 'DNS',
            now()->subHours(4),
            'general_practice',
            4,
        );
        $this->attempt(
            $user, $plan, $task, $dns,
            'incorrect', 'concept_gap', 'DNS',
            now()->subHours(3),
            'general_practice',
            4,
        );
        $this->attempt(
            $user, $plan, $task, $dns,
            'correct', 'none', 'DNS',
            now()->subHours(2),
            'weakness_reinforcement',
            4,
        );
        $this->attempt(
            $user, $plan, $task, $dns,
            'correct', 'none', 'DNS',
            now()->subHour(),
            'weakness_reinforcement',
            4,
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('general_practice', $strategy['key']);
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertContains(
            'DNS',
            array_merge(
                data_get($strategy, 'routing_policy.cooldown_topics', []),
                data_get($strategy, 'routing_policy.mastered_topics', []),
            ),
        );
        $this->assertSame(
            'general_practice',
            data_get($strategy, 'learning_phase.phase'),
        );
    }

    public function test_ai_next_step_cannot_override_mastery_cooldown_in_broad_task(): void
    {
        [$user, $plan, $task] = $this->scenario('broad');
        $pack = $this->pack();
        $sql = $this->question($pack, 1, 'SQL', 'データベース');

        $this->attempt(
            $user,
            $plan,
            $task,
            $sql,
            'correct',
            'none',
            'SQL',
            now()->subHour(),
            'general_practice',
            10,
            true,
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            $sql,
            'correct',
            'none',
            'SQL',
            now(),
            'general_practice',
            10,
            true,
        );

        $strategy = $this->strategy($plan, $task);

        $this->assertSame('broad_assessment', $strategy['key']);
        $this->assertSame([], $strategy['focus_topics']);
        $this->assertContains('SQL', data_get($strategy, 'routing_policy.cooldown_topics', []));
        $this->assertSame(0, (int) data_get($strategy, 'question_mix.primary'));
        $this->assertGreaterThanOrEqual(6, (int) data_get($strategy, 'question_mix.diagnostic'));
    }

    private function routing(Plan $plan, Task $task): array
    {
        $attempts = $this->attempts($plan, $task);
        $weakness = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            $attempts->take(8)->values(),
            [],
            10,
        );

        return app(StudyPracticeRoutingPolicyService::class)->analyze(
            $plan,
            $task,
            $attempts,
            $weakness,
            [
                'phase' => 'general_practice',
                'active_topics' => [],
            ],
        );
    }

    private function strategy(Plan $plan, Task $task): array
    {
        return app(StudyPracticeStrategyService::class)->build(
            $plan,
            $task,
            $this->attempts($plan, $task),
        );
    }

    private function attempts(Plan $plan, Task $task): Collection
    {
        return StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        Question $question,
        string $correctness,
        string $errorType,
        string $topic,
        $createdAt,
        string $phase = 'general_practice',
        int $questionCount = 10,
        bool $forceAiContinue = false,
    ): StudyPracticeAttempt {
        $focused = $phase === 'weakness_reinforcement';
        $strategy = [
            'key' => $focused ? 'weakness_reinforcement' : 'general_practice',
            'version' => 'v4',
            'learning_phase' => ['phase' => $phase],
            'focus_topics' => $focused ? [$topic] : [],
            'target_question_count' => $questionCount,
            'weakness_priority' => [
                'primary_topics' => $focused ? [$topic] : [],
                'secondary_topics' => [],
            ],
            'question_mix' => [
                'primary' => $focused ? $questionCount : 0,
                'secondary' => 0,
                'diagnostic' => $focused ? 0 : $questionCount,
            ],
        ];

        $session = StudyPracticeSession::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_ASSESSED,
            'strategy' => $strategy['key'],
            'strategy_version' => 'v4',
            'selector_type' => 'test',
            'selector_version' => 'v1',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' => 'question_bank',
            'assessment_provider_mode' => 'direct',
            'selection_context' => ['strategy' => $strategy],
            'selected_questions' => [[
                'question_ref' => 'bank_'.$question->id,
                'question_id' => $question->id,
                'source_type' => $question->source_type,
                'selection_parent_topic' => app(\App\Services\StudyTopicTaxonomyService::class)
                    ->parentForQuestion($question),
            ]],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $correct = $correctness === 'correct';
        $feedback = [[
            'question_id' => 'bank_'.$question->id,
            'correctness' => $correctness,
            'feedback' => $correct ? '正解です。' : '再確認が必要です。',
            'reasoning_feedback' => $correct
                ? 'より詳しい説明もできます。'
                : '概念を再確認してください。',
            'error_type' => $correct ? 'none' : $errorType,
            'weakness_topics' => $correct ? [] : [$topic],
            'misconceptions' => [],
        ]];

        $assessment = [
            'score_percent' => $correct ? 100 : 60,
            'question_feedback' => $feedback,
            'strengths' => $correct ? [$topic] : [],
            'weaknesses' => $correct ? [] : [$topic],
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'test',
            'next_action' => $forceAiContinue
                ? $topic.'をさらに詳しく確認する'
                : '次へ',
            'next_step' => [
                'kind' => $forceAiContinue || ! $correct
                    ? 'practice'
                    : 'continue_task',
                'label' => $forceAiContinue
                    ? $topic.'の別パターンを確認する'
                    : '次へ進む',
                'reason' => $forceAiContinue
                    ? '説明をさらに具体化するため'
                    : '現在の結果に基づく',
                'focus_topics' => $forceAiContinue || ! $correct
                    ? [$topic]
                    : [],
                'question_count' => $forceAiContinue || ! $correct
                    ? min(5, $questionCount)
                    : 0,
            ],
        ];

        return StudyPracticeAttempt::create([
            'study_practice_session_id' => $session->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => 'AP Practice',
            'questions' => [[
                'id' => 'bank_'.$question->id,
                'source_question_id' => $question->id,
                'prompt' => $question->prompt,
            ]],
            'answers' => [],
            'assessment' => $assessment,
            'score_percent' => $assessment['score_percent'],
            'strengths' => $assessment['strengths'],
            'weaknesses' => $assessment['weaknesses'],
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'test',
            'next_action' => $assessment['next_action'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function selectionSession(
        User $user,
        Plan $plan,
        Task $task,
        Collection $questionIds,
        $createdAt,
    ): StudyPracticeSession {
        return StudyPracticeSession::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_READY,
            'strategy' => 'broad_assessment',
            'strategy_version' => 'v4',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => [
                    'key' => 'broad_assessment',
                    'focus_topics' => [],
                ],
            ],
            'selected_questions' => $questionIds
                ->map(fn ($id) => [
                    'question_ref' => 'bank_'.$id,
                    'question_id' => (int) $id,
                    'source_type' => 'canovia_original',
                ])
                ->values()
                ->all(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function diversePack(int $perParent = 2): QuestionPack
    {
        $pack = $this->pack();

        $definitions = [
            ['データベース', 'HAVING'],
            ['データベース', 'JOIN'],
            ['データベース', 'NoSQL'],
            ['ネットワーク', 'DNS'],
            ['セキュリティ', '認証'],
            ['OS', 'スケジューリング'],
            ['アルゴリズム', '二分探索'],
            ['性能・可用性', 'MTBF'],
            ['開発', 'テスト'],
        ];

        $index = 1;
        foreach ($definitions as [$parent, $topic]) {
            for ($i = 1; $i <= $perParent; $i++) {
                $this->question(
                    $pack,
                    $index++,
                    $topic,
                    $parent,
                    Str::slug($parent).'-'.Str::slug($topic).'-'.$i,
                );
            }
        }

        return $pack->fresh();
    }

    private function balancedFiveParentPack(): QuestionPack
    {
        $pack = $this->pack();
        $definitions = [
            ['データベース', 'SQL'],
            ['ネットワーク', 'DNS'],
            ['セキュリティ', '認証'],
            ['OS', 'スケジューリング'],
            ['アルゴリズム', '二分探索'],
        ];

        $index = 1;
        foreach ($definitions as [$parent, $topic]) {
            for ($i = 1; $i <= 4; $i++) {
                $this->question(
                    $pack,
                    $index++,
                    $topic.'-'.$i,
                    $parent,
                    'balanced-'.$index,
                );
            }
        }

        return $pack->fresh();
    }

    private function pack(): QuestionPack
    {
        return QuestionPack::create([
            'slug' => 'ap-v564-'.Str::lower(Str::random(8)),
            'title' => 'AP科目A V56.4',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [
                'match_terms' => ['AP', '応用情報', '応用情報技術者試験'],
            ],
        ]);
    }

    private function question(
        QuestionPack $pack,
        int $index,
        string $topic,
        string $parent,
        ?string $key = null,
    ): Question {
        return Question::create([
            'question_pack_id' => $pack->id,
            'external_key' => $key ?: 'q-'.$index.'-'.Str::lower(Str::random(5)),
            'source_type' => 'canovia_original',
            'prompt' => "{$parent} / {$topic} {$index}",
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
            'sort_order' => $index,
            'is_active' => true,
        ]);
    }

    private function scenario(string $mode): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験 科目A',
            'description' => '科目Aに合格する',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addDays(60),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => $mode === 'broad'
                ? '科目Aの分野横断問題演習を進める'
                : '科目Aの初期弱点補強を完了する',
            'description' => $mode === 'broad'
                ? '科目A全体から未知弱点を探索し、複数分野を横断する'
                : '確認済みの弱点を重点補強して再現性を確認する',
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
