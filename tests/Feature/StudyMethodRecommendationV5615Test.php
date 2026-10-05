<?php

namespace Tests\Feature;

use App\Intelligence\Study\StudyMethodRecommendationService;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\StudyRecallItem;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyActivityPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyMethodRecommendationV5615Test extends TestCase
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

    public function test_ap_broad_practice_keeps_question_practice_and_exact_practice_surface(): void
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
            correctness: 'correct',
            errorType: 'none',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-method-recommendation', false)
            ->assertSee(
                'data-study-method-key="question_practice"',
                false,
            )
            ->assertSee('Question Practice')
            ->assertSee('data-study-workspace-recommendation', false)
            ->assertSee('data-study-method-alternatives', false);
    }

    public function test_repeated_knowledge_gap_switches_from_more_questions_to_resource_study(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            'DNS問題演習',
        );

        $this->attempt(
            $user,
            $plan,
            $task,
            55,
            correctness: 'incorrect',
            errorType: 'knowledge_gap',
            topics: ['DNS'],
            createdAt: now()->subHours(2),
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            60,
            correctness: 'partial',
            errorType: 'concept_gap',
            topics: ['DNS'],
            createdAt: now()->subHour(),
        );

        $beforeAttempts = StudyPracticeAttempt::query()->count();
        $beforeSessions = StudyPracticeSession::query()->count();
        $beforeRecall = StudyRecallItem::query()->count();
        $beforeResources = PlanResource::query()->count();
        $beforeTasks = Task::query()->count();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-method-key="resource_study"',
                false,
            )
            ->assertSee(
                'data-study-method-rule="repeated_knowledge_gap"',
                false,
            )
            ->assertSee('教材学習')
            ->assertSee('knowledge / concept gap')
            ->assertSee('直近3回中 2回')
            ->assertSee('DNS')
            ->assertSee(route('plans.resources.index', $plan), false)
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            )
            ->assertDontSee('data-execution-setup', false);

        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );
        $this->assertSame(
            $beforeSessions,
            StudyPracticeSession::query()->count(),
        );
        $this->assertSame(
            $beforeRecall,
            StudyRecallItem::query()->count(),
        );
        $this->assertSame(
            $beforeResources,
            PlanResource::query()->count(),
        );
        $this->assertSame(
            $beforeTasks,
            Task::query()->count(),
        );
    }

    public function test_single_knowledge_gap_does_not_force_resource_study(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            'DNS問題演習',
        );

        $this->attempt(
            $user,
            $plan,
            $task,
            60,
            correctness: 'incorrect',
            errorType: 'knowledge_gap',
            topics: ['DNS'],
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-study-method-rule="repeated_knowledge_gap"',
                false,
            );
    }

    public function test_retention_due_recommends_recall_without_replacing_practice_policy(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            '総合演習',
        );

        $method = app(StudyMethodRecommendationService::class)
            ->recommend(
                $plan,
                $task,
                ['key' => 'certification_exam'],
                [
                    'has_confirmed_scope' => false,
                    'current_position_known' => true,
                ],
                $this->practiceRecommendation([
                    'phase' => 'general_practice',
                    'retention_due_topics' => ['DNS'],
                ]),
                $user->id,
                null,
                null,
            );

        $this->assertSame(
            StudyActivityPolicyService::RECALL,
            data_get($method, 'primary.key'),
        );
        $this->assertSame(
            'retention_due',
            data_get($method, 'primary.rule'),
        );
        $this->assertSame(
            ['DNS'],
            data_get(
                $method,
                'signals.retention_due_topics',
            ),
        );
    }

    public function test_exam_mode_outranks_ordinary_retention_due(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            '科目A本番演習',
        );

        $method = app(StudyMethodRecommendationService::class)
            ->recommend(
                $plan,
                $task,
                ['key' => 'certification_exam'],
                [
                    'has_confirmed_scope' => false,
                    'current_position_known' => true,
                ],
                $this->practiceRecommendation([
                    'phase' => 'exam_mode',
                    'phase_label' => 'Exam Mode',
                    'retention_due_topics' => ['DNS'],
                ]),
                $user->id,
                null,
                null,
            );

        $this->assertSame(
            StudyActivityPolicyService::QUESTION_PRACTICE,
            data_get($method, 'primary.key'),
        );
        $this->assertSame(
            'exam_mode',
            data_get($method, 'primary.variant'),
        );
        $this->assertSame(
            'exam_mode',
            data_get($method, 'primary.rule'),
        );
    }

    public function test_resumable_session_outranks_later_method_switches(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            'DNS弱点補強',
        );

        $method = app(StudyMethodRecommendationService::class)
            ->recommend(
                $plan,
                $task,
                ['key' => 'certification_exam'],
                [
                    'has_confirmed_scope' => false,
                    'current_position_known' => true,
                ],
                $this->practiceRecommendation([
                    'resume' => true,
                    'phase' => 'general_practice',
                    'retention_due_topics' => ['DNS'],
                ]),
                $user->id,
                null,
                null,
            );

        $this->assertSame(
            StudyActivityPolicyService::QUESTION_PRACTICE,
            data_get($method, 'primary.key'),
        );
        $this->assertSame(
            'resume',
            data_get($method, 'primary.variant'),
        );
        $this->assertSame(
            'resume_continuity',
            data_get($method, 'primary.rule'),
        );
    }

    public function test_memorization_workspace_recommends_recall(): void
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
            ->assertSee(
                'data-study-method-key="recall"',
                false,
            )
            ->assertSee(
                'data-study-method-rule="memorization_recall"',
                false,
            )
            ->assertSee('Recall')
            ->assertSee(
                route(
                    'plans.tasks.study_recall.show',
                    [$plan, $task],
                ),
                false,
            )
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            );
    }

    public function test_school_test_without_scope_recommends_scope_organization(): void
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
                'data-study-method-key="scope_organization"',
                false,
            )
            ->assertSee(
                'data-study-method-rule="school_test_scope"',
                false,
            )
            ->assertSee('範囲整理')
            ->assertSee(route('plans.study_scope.index', $plan), false)
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            );
    }

    public function test_skill_learning_recommends_practical_evidence_and_practical_baseline(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'Pythonを習得する',
            'プログラミング学習',
            'Pythonで小さなCLIを作る',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-workspace-missing-context="practical_baseline"',
                false,
            )
            ->assertSee(
                'data-study-method-key="practical_evidence"',
                false,
            )
            ->assertSee('実践・成果物')
            ->assertSee(
                route(
                    'plans.tasks.guided_execution.show',
                    [$plan, $task],
                ),
                false,
            )
            ->assertDontSee(
                'data-study-workspace-recommendation',
                false,
            );
    }

    public function test_reference_book_task_preserves_v4110_resource_semantic_fit(): void
    {
        [$user, $plan] = $this->scenario(
            '簿記2級',
            '資格学習',
            '参考書の第3章を読む',
            '解説を読んで新しい論点を理解する',
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-method-key="resource_study"',
                false,
            )
            ->assertSee(
                'data-study-method-rule="task_semantic_fit"',
                false,
            )
            ->assertSee('教材学習')
            ->assertSee(route('plans.resources.index', $plan), false);
    }

    public function test_method_alternatives_are_ranked_and_linked(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP対策',
            '資格学習',
            '科目Aの分野横断問題を解く',
        );

        $response = $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-study-method-alternatives', false)
            ->assertSee(
                'data-study-method-alternative="recall"',
                false,
            )
            ->assertSee(
                'data-study-method-alternative="resource_study"',
                false,
            )
            ->assertSee(
                route(
                    'plans.tasks.study_recall.show',
                    [$plan, $task],
                ),
                false,
            )
            ->assertSee(route('plans.resources.index', $plan), false);

        $html = $response->getContent();
        $recallPosition = strpos(
            $html,
            'data-study-method-alternative="recall"',
        );
        $resourcePosition = strpos(
            $html,
            'data-study-method-alternative="resource_study"',
        );

        $this->assertNotFalse($recallPosition);
        $this->assertNotFalse($resourcePosition);
    }

    /**
     * @return array{0:User,1:Plan,2:Task}
     */
    private function scenario(
        string $planTitle,
        string $category,
        string $taskTitle,
        ?string $taskDescription = null,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $planTitle,
            'description' => $planTitle,
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
            'description' => $taskDescription ?? $taskTitle,
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
        string $correctness,
        string $errorType,
        array $topics = [],
        ?\DateTimeInterface $createdAt = null,
    ): StudyPracticeAttempt {
        $attempt = StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => '学習方法判定用演習',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => [[
                    'question_id' => 'q1',
                    'correctness' => $correctness,
                    'error_type' => $errorType,
                    'weakness_topics' => $topics,
                ]],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => $topics,
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'Study method test',
            'next_action' => '次へ',
        ]);

        if ($createdAt) {
            $attempt->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $attempt;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function practiceRecommendation(
        array $overrides = [],
    ): array {
        return array_replace_recursive([
            'resume' => false,
            'phase' => 'general_practice',
            'phase_label' => '総合演習',
            'question_count' => 10,
            'retention_due_topics' => [],
            'cooldown_topics' => [],
            'mastered_topics' => [],
            'primary_topics' => [],
            'secondary_topics' => [],
        ], $overrides);
    }
}
