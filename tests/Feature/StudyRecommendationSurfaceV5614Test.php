<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyPracticeStrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class StudyRecommendationSurfaceV5614Test extends TestCase
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

    public function test_workspace_recommendation_uses_exact_strategy_mix_without_creating_session(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            '科目Aの分野横断問題を解く',
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            84,
            [],
        );

        $attempts = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->get();

        $strategy = app(StudyPracticeStrategyService::class)
            ->build($plan, $task, $attempts);

        $beforeAttempts = StudyPracticeAttempt::query()->count();
        $beforeSessions = StudyPracticeSession::query()->count();

        $response = $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-workspace-recommendation', false)
            ->assertSee('data-study-recommendation-source="strategy"', false)
            ->assertSee(
                'data-study-recommendation-strategy="'.
                    e((string) $strategy['key']).
                '"',
                false,
            )
            ->assertSee(
                'data-study-recommendation-question-count="'.
                    (int) $strategy['target_question_count'].
                '"',
                false,
            )
            ->assertSee((string) $strategy['label'])
            ->assertSee(
                route(
                    'plans.tasks.study_practice.show',
                    [$plan, $task],
                ),
                false,
            )
            ->assertDontSee(
                'data-study-surface="current_action"',
                false,
            );

        foreach (
            ['primary', 'secondary', 'diagnostic']
            as $key
        ) {
            $count = (int) data_get(
                $strategy,
                "question_mix.{$key}",
                0,
            );

            if ($count <= 0) {
                continue;
            }

            $response
                ->assertSee(
                    'data-study-recommendation-mix-key="'.
                        $key.
                    '"',
                    false,
                )
                ->assertSee(
                    'data-study-recommendation-mix-count="'.
                        $count.
                    '"',
                    false,
                );
        }

        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );
        $this->assertSame(
            $beforeSessions,
            StudyPracticeSession::query()->count(),
        );
    }

    public function test_resumable_session_uses_stored_strategy_and_does_not_build_new_strategy(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            'DNSの弱点補強',
        );

        $session = $this->practiceSession(
            $user,
            $plan,
            $task,
            [
                'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
                'selection_context' => [
                    'strategy' => $this->storedStrategy(
                        phase: 'weakness_reinforcement',
                        phaseLabel: '弱点補強',
                        strategyKey: 'weakness_reinforcement',
                        strategyLabel: '弱点補完',
                        mix: [
                            'primary' => 3,
                            'secondary' => 1,
                            'diagnostic' => 1,
                        ],
                        extra: [
                            'weakness_priority' => [
                                'primary_topics' => ['DNS'],
                                'secondary_topics' => ['MTU'],
                            ],
                            'weakness_control' => [
                                'retention_due_topics' => ['NAPT'],
                                'cooldown_topics' => ['SQL'],
                                'mastered_topics' => [],
                            ],
                            'routing_policy' => [
                                'task_mode' => 'focused_remediation',
                                'suppressed_parent_topics' => [
                                    'データベース',
                                ],
                                'preferred_parent_topics' => [
                                    'ネットワーク',
                                ],
                            ],
                        ],
                    ),
                ],
                'draft_answers' => [
                    'q1' => ['answer' => 'A'],
                ],
            ],
        );

        $this->mock(
            StudyPracticeStrategyService::class,
            function (MockInterface $mock): void {
                $mock->shouldNotReceive('build');
            },
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-workspace-recommendation', false)
            ->assertSee('data-study-recommendation-source="session"', false)
            ->assertSee('CONTINUE PRACTICE')
            ->assertSee('途中の演習を続ける')
            ->assertSee('5問')
            ->assertSee('1/5問 回答済み')
            ->assertSee('3問')
            ->assertSee('重点弱点')
            ->assertSee('関連弱点')
            ->assertSee('確認問題')
            ->assertSee('DNS')
            ->assertSee('NAPT')
            ->assertSee('SQL')
            ->assertSee('データベース')
            ->assertSee('ネットワーク')
            ->assertSee('続きから再開')
            ->assertSee(
                route(
                    'plans.tasks.study_practice.show',
                    [$plan, $task],
                ),
                false,
            );

        $this->assertSame(
            StudyPracticeSession::STATUS_IN_PROGRESS,
            $session->fresh()->status,
        );
        $this->assertDatabaseCount(
            'study_practice_sessions',
            1,
        );
    }

    public function test_exam_mode_stored_strategy_uses_exam_mix_label(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            '科目Aの本番演習',
        );

        $this->practiceSession(
            $user,
            $plan,
            $task,
            [
                'selection_context' => [
                    'strategy' => $this->storedStrategy(
                        phase: 'exam_mode',
                        phaseLabel: 'Exam Mode',
                        strategyKey: 'exam_mode',
                        strategyLabel: 'Exam Mode',
                        mix: [
                            'primary' => 0,
                            'secondary' => 0,
                            'diagnostic' => 10,
                        ],
                        questionCount: 10,
                    ),
                ],
                'questions_snapshot' => $this->questions(10),
            ],
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('Exam Mode')
            ->assertSee('10問')
            ->assertSee('本番横断')
            ->assertSee(
                'data-study-recommendation-mix-count="10"',
                false,
            );
    }

    public function test_school_test_without_scope_keeps_scope_gate_instead_of_practice_recommendation(): void
    {
        [$user, $plan] = $this->scenario(
            '数学II 中間テストで80点',
            '定期テスト学習',
            '数学IIを勉強する',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-workspace-missing-context="study_scope"',
                false,
            )
            ->assertSee('試験範囲を追加')
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            )
            ->assertSee(
                'data-study-surface="current_action"',
                false,
            );
    }

    public function test_memorization_keeps_recall_current_action_without_practice_recommendation(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '英単語を暗記する',
            '英語学習',
            '英単語100語',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            )
            ->assertSee('Recallを開く')
            ->assertSee(
                route(
                    'plans.tasks.study_recall.show',
                    [$plan, $task],
                ),
                false,
            );
    }

    private function scenario(
        string $title,
        string $category,
        string $taskTitle,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(35),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskTitle,
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
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => '分野横断演習',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => [],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => $weaknesses,
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => '演習結果',
            'next_action' => '次の演習へ',
        ]);
    }

    private function practiceSession(
        User $user,
        Plan $plan,
        Task $task,
        array $overrides = [],
    ): StudyPracticeSession {
        return StudyPracticeSession::query()->create(
            array_replace([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'session_token' => (string) Str::uuid(),
                'prepare_request_id' => (string) Str::uuid(),
                'status' => StudyPracticeSession::STATUS_READY,
                'exercise_title' => 'Study Recommendation Test',
                'strategy' => 'general_practice',
                'strategy_version' => 'v4',
                'selector_type' => 'question_bank',
                'selector_version' => 'bank-v4-routing',
                'question_provider' => 'question_bank',
                'question_provider_mode' => 'direct',
                'assessment_provider' => 'question_bank_grader',
                'assessment_provider_mode' => 'direct',
                'selection_context' => [
                    'strategy' => $this->storedStrategy(
                        phase: 'general_practice',
                        phaseLabel: '総合演習',
                        strategyKey: 'general_practice',
                        strategyLabel: '分野横断演習',
                        mix: [
                            'primary' => 0,
                            'secondary' => 0,
                            'diagnostic' => 5,
                        ],
                    ),
                ],
                'provider_payload' => [],
                'questions_snapshot' => $this->questions(5),
                'selected_questions' => [],
                'started_at' => now()->subMinutes(10),
            ], $overrides),
        );
    }

    /**
     * @param array{primary:int,secondary:int,diagnostic:int} $mix
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function storedStrategy(
        string $phase,
        string $phaseLabel,
        string $strategyKey,
        string $strategyLabel,
        array $mix,
        array $extra = [],
        int $questionCount = 5,
    ): array {
        return array_replace_recursive([
            'version' => 'v4',
            'key' => $strategyKey,
            'label' => $strategyLabel,
            'reason' => '保存済みStrategyの理由',
            'target_question_count' => $questionCount,
            'learning_phase' => [
                'phase' => $phase,
                'label' => $phaseLabel,
            ],
            'routing_policy' => [
                'task_mode' => 'adaptive',
                'suppressed_parent_topics' => [],
                'preferred_parent_topics' => [],
            ],
            'question_mix' => $mix,
            'weakness_priority' => [
                'primary_topics' => [],
                'secondary_topics' => [],
            ],
            'weakness_control' => [
                'retention_due_topics' => [],
                'cooldown_topics' => [],
                'mastered_topics' => [],
            ],
        ], $extra);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function questions(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index) => [
                'id' => 'q'.$index,
                'prompt' => 'Question '.$index,
                'response_fields' => [[
                    'id' => 'answer',
                    'type' => 'textarea',
                    'label' => '回答',
                    'required' => true,
                    'placeholder' => '',
                    'choices' => [],
                ]],
                'type' => 'text',
                'choices' => [],
            ])
            ->all();
    }
}
