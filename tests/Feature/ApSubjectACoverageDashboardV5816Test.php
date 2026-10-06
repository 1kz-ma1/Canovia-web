<?php

namespace Tests\Feature;

use App\Intelligence\Study\ApSubjectACoverageDashboardService;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApSubjectACoverageDashboardV5816Test extends TestCase
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

    public function test_non_ap_plan_does_not_expose_dashboard(): void
    {
        [$user, $plan] = $this->plan(
            '簿記2級',
            '商業簿記の総合演習',
        );

        $result = app(
            ApSubjectACoverageDashboardService::class,
        )->project(
            $plan,
            $user->id,
            null,
        );

        $this->assertFalse($result['available']);
        $this->assertSame(
            'not_ap_subject_a',
            $result['reason'],
        );
    }

    public function test_ap_plan_without_assessed_bank_activity_returns_waiting_dashboard(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        $this->officialPack();

        $result = $this->project(
            $plan,
            $user,
        );

        $this->assertTrue($result['available']);
        $this->assertFalse(
            $result['has_assessed_activity'],
        );
        $this->assertSame(
            0,
            $result['assessed_exposure_count'],
        );
        $this->assertSame(
            0,
            $result['official_unique_question_count'],
        );
        $this->assertSame(
            0,
            $result['official_coverage_percent'],
        );
        $this->assertCount(3, $result['domains']);
    }

    public function test_official_unique_coverage_and_plan_wide_performance_are_separate_across_tasks(): void
    {
        [$user, $plan, $taskOne] = $this->apPlan();
        $taskTwo = $this->task(
            $plan,
            '科目A 弱点補強',
            'ネットワークとDBを再確認する',
            2,
        );

        [$official, $officialQuestions] =
            $this->officialPack();
        [$core, $coreQuestions] =
            $this->corePack();

        $this->assessedSession(
            $plan,
            $taskOne,
            $user,
            [
                $this->selected(
                    $officialQuestions['tech1'],
                    'テクノロジ',
                    'ネットワーク',
                ),
                $this->selected(
                    $officialQuestions['tech2'],
                    'テクノロジ',
                    'データベース',
                ),
            ],
            [
                'bank_'.$officialQuestions['tech1']->id =>
                    'correct',
                'bank_'.$officialQuestions['tech2']->id =>
                    'incorrect',
            ],
        );

        $this->assessedSession(
            $plan,
            $taskTwo,
            $user,
            [
                $this->selected(
                    $officialQuestions['management'],
                    'マネジメント',
                    'プロジェクト管理',
                ),
                $this->selected(
                    $coreQuestions['technology'],
                    'テクノロジ',
                    'ネットワーク',
                ),
            ],
            [
                'bank_'.$officialQuestions['management']->id =>
                    'partial',
                'bank_'.$coreQuestions['technology']->id =>
                    'correct',
            ],
        );

        $result = $this->project(
            $plan,
            $user,
        );

        $this->assertSame(
            $official->id,
            data_get($result, 'reference_pack.id'),
        );
        $this->assertSame(
            3,
            $result['official_unique_question_count'],
        );
        $this->assertSame(
            75,
            $result['official_coverage_percent'],
        );
        $this->assertSame(
            4,
            $result['assessed_exposure_count'],
        );
        $this->assertSame(
            4,
            $result['unique_question_count'],
        );
        $this->assertSame(
            2,
            $result['assessed_session_count'],
        );

        $technology = $this->domain(
            $result,
            'テクノロジ',
        );
        $management = $this->domain(
            $result,
            'マネジメント',
        );
        $strategy = $this->domain(
            $result,
            'ストラテジ',
        );

        $this->assertSame(
            2,
            $technology[
                'official_unique_question_count'
            ],
        );
        $this->assertSame(
            2,
            $technology['official_expected_count'],
        );
        $this->assertSame(
            100,
            $technology['official_coverage_percent'],
        );
        $this->assertSame(
            3,
            $technology['assessed_exposure_count'],
        );
        $this->assertSame(
            67,
            $technology[
                'observed_correct_rate_percent'
            ],
        );
        $this->assertSame(
            'developing',
            $technology['status'],
        );

        $this->assertSame(
            1,
            $management[
                'official_unique_question_count'
            ],
        );
        $this->assertSame(
            1,
            $management['assessed_exposure_count'],
        );
        $this->assertSame(
            0,
            $management[
                'observed_correct_rate_percent'
            ],
        );
        $this->assertSame(
            'observing',
            $management['status'],
        );

        $this->assertSame(
            0,
            $strategy[
                'official_unique_question_count'
            ],
        );
        $this->assertSame(
            'unobserved',
            $strategy['status'],
        );

        $network = collect(
            $technology['parent_topics'],
        )->firstWhere(
            'label',
            'ネットワーク',
        );

        $this->assertSame(
            2,
            $network['assessed_exposure_count'],
        );
        $this->assertSame(
            100,
            $network[
                'observed_correct_rate_percent'
            ],
        );
    }

    public function test_repeated_official_question_increases_exposure_but_not_unique_coverage(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        [, $questions] = $this->officialPack();

        foreach (['correct', 'incorrect'] as $correctness) {
            $this->assessedSession(
                $plan,
                $task,
                $user,
                [
                    $this->selected(
                        $questions['tech1'],
                        'テクノロジ',
                        'ネットワーク',
                    ),
                ],
                [
                    'bank_'.$questions['tech1']->id =>
                        $correctness,
                ],
            );
        }

        $result = $this->project(
            $plan,
            $user,
        );
        $technology = $this->domain(
            $result,
            'テクノロジ',
        );

        $this->assertSame(
            1,
            $result['official_unique_question_count'],
        );
        $this->assertSame(
            25,
            $result['official_coverage_percent'],
        );
        $this->assertSame(
            2,
            $technology['assessed_exposure_count'],
        );
        $this->assertSame(
            1,
            $technology['unique_question_count'],
        );
        $this->assertSame(
            1,
            $technology[
                'official_unique_question_count'
            ],
        );
    }

    public function test_unassessed_and_native_ai_only_sessions_do_not_count(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        [, $questions] = $this->officialPack();

        $this->practiceSession(
            $plan,
            $task,
            $user,
            [
                $this->selected(
                    $questions['tech1'],
                    'テクノロジ',
                    'ネットワーク',
                ),
            ],
        );

        $native = $this->practiceSession(
            $plan,
            $task,
            $user,
            [[
                'question_ref' => 'native_q1',
                'question_id' => null,
                'source_type' => 'native_ai',
                'selection_domain' => 'テクノロジ',
                'selection_parent_topic' => 'ネットワーク',
            ]],
        );
        $this->attempt(
            $native,
            $user,
            ['native_q1' => 'correct'],
        );

        $result = $this->project(
            $plan,
            $user,
        );

        $this->assertFalse(
            $result['has_assessed_activity'],
        );
        $this->assertSame(
            0,
            $result['assessed_exposure_count'],
        );
    }

    public function test_actor_isolation_keeps_other_users_out_of_dashboard(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        $other = User::factory()->create();
        [, $questions] = $this->officialPack();

        $this->assessedSession(
            $plan,
            $task,
            $other,
            [
                $this->selected(
                    $questions['tech1'],
                    'テクノロジ',
                    'ネットワーク',
                ),
            ],
            [
                'bank_'.$questions['tech1']->id =>
                    'incorrect',
            ],
        );

        $result = $this->project(
            $plan,
            $user,
        );

        $this->assertFalse(
            $result['has_assessed_activity'],
        );
        $this->assertSame(
            0,
            $result['assessed_exposure_count'],
        );
    }

    public function test_correctness_mapping_treats_partial_as_not_correct_and_statuses_are_deterministic(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        [, $questions] = $this->officialPack();

        foreach (
            ['correct', 'partial', 'incorrect']
            as $correctness
        ) {
            $this->assessedSession(
                $plan,
                $task,
                $user,
                [
                    $this->selected(
                        $questions['management'],
                        'マネジメント',
                        'プロジェクト管理',
                    ),
                ],
                [
                    'bank_'.$questions['management']->id =>
                        $correctness,
                ],
            );
        }

        $result = $this->project(
            $plan,
            $user,
        );
        $management = $this->domain(
            $result,
            'マネジメント',
        );

        $this->assertSame(
            3,
            $management['assessed_exposure_count'],
        );
        $this->assertSame(
            1,
            $management['correct_count'],
        );
        $this->assertSame(
            1,
            $management['partial_count'],
        );
        $this->assertSame(
            1,
            $management['incorrect_count'],
        );
        $this->assertSame(
            33,
            $management[
                'observed_correct_rate_percent'
            ],
        );
        $this->assertSame(
            'needs_attention',
            $management['status'],
        );
    }

    public function test_dashboard_scans_latest_two_hundred_sessions_only(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        [, $questions] = $this->officialPack();

        for ($i = 0; $i < 201; $i++) {
            $question = $i === 0
                ? $questions['strategy']
                : $questions['tech1'];
            $domain = $i === 0
                ? 'ストラテジ'
                : 'テクノロジ';

            $this->assessedSession(
                $plan,
                $task,
                $user,
                [
                    $this->selected(
                        $question,
                        $domain,
                        $domain,
                    ),
                ],
                [
                    'bank_'.$question->id =>
                        'correct',
                ],
            );
        }

        $result = $this->project(
            $plan,
            $user,
        );

        $this->assertSame(
            200,
            $result['sessions_considered'],
        );
        $this->assertSame(
            200,
            $result['assessed_exposure_count'],
        );
        $this->assertSame(
            0,
            $this->domain(
                $result,
                'ストラテジ',
            )['assessed_exposure_count'],
        );
    }

    public function test_analysis_surface_renders_registered_dashboard_without_provider_call_or_mutation(): void
    {
        [$user, $plan, $task] = $this->apPlan();
        $this->officialPack();

        Http::fake();

        $beforeSessions =
            StudyPracticeSession::query()->count();
        $beforeAttempts =
            StudyPracticeAttempt::query()->count();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-surface="ap_subject_a_coverage"',
                false,
            )
            ->assertSee(
                'data-ap-subject-a-coverage',
                false,
            )
            ->assertSee('科目Aの分野横断Coverage')
            ->assertSee('観測待ち');

        $this->assertSame(
            $beforeSessions,
            StudyPracticeSession::query()->count(),
        );
        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );

        Http::assertNothingSent();
    }

    private function project(
        Plan $plan,
        User $user,
    ): array {
        return app(
            ApSubjectACoverageDashboardService::class,
        )->project(
            $plan,
            $user->id,
            null,
        );
    }

    private function domain(
        array $result,
        string $label,
    ): array {
        return collect($result['domains'])
            ->firstWhere('label', $label);
    }

    private function selected(
        Question $question,
        string $domain,
        string $parent,
    ): array {
        return [
            'question_ref' =>
                'bank_'.$question->id,
            'question_id' => $question->id,
            'source_type' => $question->source_type,
            'source_reference' =>
                $question->source_reference,
            'selection_bucket' => 'diagnostic',
            'selection_domain' => $domain,
            'selection_parent_topic' => $parent,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $selected
     * @param array<string,string> $feedback
     */
    private function assessedSession(
        Plan $plan,
        Task $task,
        User $user,
        array $selected,
        array $feedback,
    ): StudyPracticeSession {
        $session = $this->practiceSession(
            $plan,
            $task,
            $user,
            $selected,
        );
        $this->attempt(
            $session,
            $user,
            $feedback,
        );

        return $session;
    }

    /**
     * @param array<int,array<string,mixed>> $selected
     */
    private function practiceSession(
        Plan $plan,
        Task $task,
        User $user,
        array $selected,
    ): StudyPracticeSession {
        return StudyPracticeSession::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' =>
                StudyPracticeSession::STATUS_COMPLETED,
            'strategy' => 'general_practice',
            'strategy_version' => 'v1',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v4-routing',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'assessment_provider' =>
                'question_bank_grader',
            'assessment_provider_mode' => 'direct',
            'selection_context' => [
                'strategy' => [
                    'exam_profile' => [
                        'key' => 'ap_subject_a_exam',
                    ],
                ],
            ],
            'selected_questions' => $selected,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param array<string,string> $feedback
     */
    private function attempt(
        StudyPracticeSession $session,
        User $user,
        array $feedback,
    ): StudyPracticeAttempt {
        return StudyPracticeAttempt::query()->create([
            'study_practice_session_id' =>
                $session->id,
            'plan_id' => $session->plan_id,
            'task_id' => $session->task_id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                'coverage-'.$session->id,
            ),
            'exercise_title' => 'AP Coverage',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'question_feedback' => collect(
                    $feedback,
                )
                    ->map(
                        fn (
                            string $correctness,
                            string $questionRef,
                        ) => [
                            'question_id' =>
                                $questionRef,
                            'correctness' =>
                                $correctness,
                            'error_type' =>
                                $correctness
                                    === 'correct'
                                        ? 'none'
                                        : 'unknown',
                            'weakness_topics' => [],
                        ],
                    )
                    ->values()
                    ->all(),
            ],
            'score_percent' => 0,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 0,
            'evidence_summary' => 'coverage test',
            'next_action' => 'continue',
        ]);
    }

    /**
     * @return array{0:QuestionPack,1:array<string,Question>}
     */
    private function officialPack(): array
    {
        $pack = QuestionPack::query()->create([
            'slug' => 'ap-official-'.Str::uuid(),
            'title' => 'AP科目A IPA公式Pack',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [
                'selection_priority' => 100,
                'content_kind' =>
                    'official_past_exam_curated',
                'source_domain_counts' => [
                    'technology' => 2,
                    'management' => 1,
                    'strategy' => 1,
                ],
                'question_count' => 4,
            ],
        ]);

        return [
            $pack,
            [
                'tech1' => $this->question(
                    $pack,
                    'official-tech-1',
                    'official',
                ),
                'tech2' => $this->question(
                    $pack,
                    'official-tech-2',
                    'official',
                ),
                'management' => $this->question(
                    $pack,
                    'official-management',
                    'official',
                ),
                'strategy' => $this->question(
                    $pack,
                    'official-strategy',
                    'official',
                ),
            ],
        ];
    }

    /**
     * @return array{0:QuestionPack,1:array<string,Question>}
     */
    private function corePack(): array
    {
        $pack = QuestionPack::query()->create([
            'slug' => 'ap-core-'.Str::uuid(),
            'title' => 'AP Core Pack',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [
                'selection_priority' => 50,
                'content_kind' => 'canovia_core',
            ],
        ]);

        return [
            $pack,
            [
                'technology' => $this->question(
                    $pack,
                    'core-tech',
                    'canovia_original',
                ),
            ],
        ];
    }

    private function question(
        QuestionPack $pack,
        string $key,
        string $sourceType,
    ): Question {
        return Question::query()->create([
            'question_pack_id' => $pack->id,
            'external_key' => $key,
            'source_type' => $sourceType,
            'source_reference' => 'test',
            'prompt' => $key,
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
            'learning_metadata' => [],
            'difficulty' => 3,
            'sort_order' => $pack->questions()->count() + 1,
            'is_active' => true,
        ]);
    }

    private function apPlan(): array
    {
        [$user, $plan] = $this->plan(
            'AP 応用情報技術者試験 科目A',
            '科目Aの分野横断問題演習を進める',
        );

        return [
            $user,
            $plan,
            $plan->tasks()->firstOrFail(),
        ];
    }

    private function plan(
        string $title,
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
            'description' => $title.'の学習',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $this->task(
            $plan,
            $taskTitle,
            '科目A全体を横断して弱点を確認する',
            1,
        );

        return [$user, $plan];
    }

    private function task(
        Plan $plan,
        string $title,
        string $description,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $description,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
