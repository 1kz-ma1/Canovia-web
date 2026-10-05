<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\QuestionBankStudyPracticeQuestionProvider;
use App\Services\StudyPracticeExamProfileService;
use App\Services\StudyPracticePromptService;
use App\Services\StudyPracticeStrategyService;
use App\Services\StudyWeaknessPrioritizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeCalibrationV414Test extends TestCase
{
    use RefreshDatabase;

    public function test_ap_subject_a_profile_prefers_exam_like_four_choice_questions(): void
    {
        [, $plan, $task] = $this->studyPlan();

        $profile = app(StudyPracticeExamProfileService::class)->forPlanTask($plan, $task);

        $this->assertSame('ap_subject_a_exam', $profile['key']);
        $this->assertSame('single_choice', $profile['preferred_response_type']);
        $this->assertSame(4, $profile['preferred_choice_count']);
        $this->assertTrue($profile['calculation_policy']['prefer_neat_values']);
        $this->assertTrue($profile['difficulty_policy']['do_not_raise_with_awkward_arithmetic']);

        $strategy = app(StudyPracticeStrategyService::class)->build($plan, $task, collect());
        $prompt = app(StudyPracticePromptService::class)->generationPrompt($plan, $task, collect(), $strategy);

        $this->assertStringContainsString('AP科目A 本番準拠', $prompt);
        $this->assertStringContainsString('原則はsingle_choiceの4択', $prompt);
        $this->assertStringContainsString('work_inputを必ず付け', $prompt);
        $this->assertStringContainsString('work_input=reasoning / calculation', $prompt);
        $this->assertStringContainsString('計算過程', $prompt);
        $this->assertStringContainsString('考え方・判断理由', $prompt);
        $this->assertStringContainsString('面倒な算術だけで上げない', $prompt);
        $this->assertStringContainsString('不要に桁数の多い値や割り切れない値', $prompt);
    }

    public function test_single_calculation_slip_is_not_promoted_to_primary_weakness(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $attempt = $this->attempt($user, $plan, $task, 'single-slip', [
            'score_percent' => 80,
            'weaknesses' => ['可用性'],
            'strengths' => ['稼働率の式'],
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'incorrect',
                'error_type' => 'calculation_slip',
                'weakness_topics' => ['可用性'],
                'misconceptions' => [],
            ]],
        ]);

        $result = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$attempt]),
            [],
            10,
        );

        $this->assertSame([], $result['primary_topics']);
        $this->assertSame(['可用性'], $result['secondary_topics']);
        $this->assertSame(0, $result['question_mix']['primary']);
        $this->assertSame(4, $result['question_mix']['secondary']);
        $this->assertSame(6, $result['question_mix']['diagnostic']);

        $item = collect($result['ranked'])->firstWhere('topic', '可用性');
        $this->assertSame('suspected', $item['state']);
        $this->assertSame('calculation_slip', $item['dominant_error_type']);
    }

    public function test_repeated_concept_gap_becomes_primary_but_keeps_diagnostic_questions(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $latest = $this->attempt($user, $plan, $task, 'concept-latest', [
            'score_percent' => 65,
            'weaknesses' => ['DNSレコード'],
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'incorrect',
                'error_type' => 'concept_gap',
                'weakness_topics' => ['DNSレコード'],
                'misconceptions' => ['MXとCNAMEの役割を混同'],
            ]],
        ]);

        $older = $this->attempt($user, $plan, $task, 'concept-older', [
            'score_percent' => 70,
            'weaknesses' => ['DNSレコード'],
            'question_feedback' => [[
                'question_id' => 'q1',
                'correctness' => 'partial',
                'error_type' => 'concept_gap',
                'weakness_topics' => ['DNSレコード'],
                'misconceptions' => ['AとCNAMEの使い分け'],
            ]],
        ], now()->subDay());

        $result = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$latest, $older]),
            [],
            10,
        );

        $this->assertSame(['DNSレコード'], $result['primary_topics']);
        $this->assertSame(5, $result['question_mix']['primary']);
        $this->assertSame(0, $result['question_mix']['secondary']);
        $this->assertSame(5, $result['question_mix']['diagnostic']);

        $item = collect($result['ranked'])->firstWhere('topic', 'DNSレコード');
        $this->assertSame('confirmed', $item['state']);
        $this->assertGreaterThanOrEqual(0.70, $item['confidence']);
    }

    public function test_priority_exposes_learning_value_per_recovery_cost(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $latest = $this->attempt($user, $plan, $task, 'value-latest', [
            'score_percent' => 60,
            'weaknesses' => [],
            'question_feedback' => [
                [
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                    'error_type' => 'concept_gap',
                    'weakness_topics' => ['DNSレコード'],
                    'misconceptions' => ['MXとCNAMEの役割を混同'],
                ],
                [
                    'question_id' => 'q2',
                    'correctness' => 'incorrect',
                    'error_type' => 'calculation_slip',
                    'weakness_topics' => ['可用性計算'],
                    'misconceptions' => [],
                ],
            ],
        ]);

        $older = $this->attempt($user, $plan, $task, 'value-older', [
            'score_percent' => 65,
            'weaknesses' => [],
            'question_feedback' => [
                [
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                    'error_type' => 'concept_gap',
                    'weakness_topics' => ['DNSレコード'],
                    'misconceptions' => ['AとCNAMEの使い分け'],
                ],
                [
                    'question_id' => 'q2',
                    'correctness' => 'incorrect',
                    'error_type' => 'calculation_slip',
                    'weakness_topics' => ['可用性計算'],
                    'misconceptions' => [],
                ],
            ],
        ], now()->subDay());

        $result = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$latest, $older]),
            [],
            10,
        );

        $ranked = collect($result['ranked']);
        $concept = $ranked->firstWhere('topic', 'DNSレコード');
        $calculation = $ranked->firstWhere('topic', '可用性計算');

        $this->assertArrayHasKey('value_per_cost', $concept);
        $this->assertArrayHasKey('value_per_cost', $calculation);
        $this->assertSame('concept_gap', $concept['dominant_error_type']);
        $this->assertSame('calculation_slip', $calculation['dominant_error_type']);
        $this->assertGreaterThan($calculation['priority_score'], $concept['priority_score']);
    }

    public function test_heavily_repeated_topic_gets_saturation_penalty_and_less_than_half_when_needed(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $attempts = collect();
        for ($i = 0; $i < 3; $i++) {
            $attempts->push($this->attempt($user, $plan, $task, 'saturated-'.$i, [
                'score_percent' => 60,
                'weaknesses' => ['MTU計算'],
                'question_feedback' => [[
                    'question_id' => 'q1',
                    'correctness' => 'incorrect',
                    'error_type' => 'concept_gap',
                    'weakness_topics' => ['MTU計算'],
                    'misconceptions' => ['MTUとMSSの混同'],
                ]],
            ], now()->subHours($i)));
        }

        $result = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            $attempts,
            [],
            10,
        );

        $item = collect($result['ranked'])->firstWhere('topic', 'MTU計算');
        $this->assertSame(1.0, $item['saturation']);
        $this->assertSame(4, $result['question_mix']['primary']);
        $this->assertSame(6, $result['question_mix']['diagnostic']);
    }

    public function test_latest_strength_resolves_an_old_one_off_weakness(): void
    {
        [$user, $plan, $task] = $this->studyPlan();

        $latest = $this->attempt($user, $plan, $task, 'resolved-latest', [
            'score_percent' => 95,
            'strengths' => ['MTU計算'],
            'weaknesses' => [],
            'question_feedback' => [],
        ]);

        $older = $this->attempt($user, $plan, $task, 'resolved-old', [
            'score_percent' => 60,
            'strengths' => [],
            'weaknesses' => ['MTU計算'],
            'question_feedback' => [],
        ], now()->subDay());

        $result = app(StudyWeaknessPrioritizationService::class)->analyze(
            $plan,
            $task,
            collect([$latest, $older]),
            [],
            10,
        );

        $item = collect($result['ranked'])->firstWhere('topic', 'MTU計算');
        $this->assertSame('resolved', $item['state']);
        $this->assertSame([], $result['primary_topics']);
        $this->assertSame([], $result['secondary_topics']);
        $this->assertSame(10, $result['question_mix']['diagnostic']);
    }

    public function test_question_bank_selector_reserves_diagnostic_questions_instead_of_only_drilling_focus(): void
    {
        $this->importAndPublish();
        [, $plan, $task] = $this->studyPlan();

        $prepared = app(QuestionBankStudyPracticeQuestionProvider::class)->prepare(
            $plan,
            $task,
            collect(),
            [
                'key' => 'weakness_reinforcement',
                'label' => '弱点補強（偏り防止）',
                'target_question_count' => 10,
                'focus_topics' => ['MTU計算', 'DNSレコード'],
                'weakness_priority' => [
                    'primary_topics' => ['MTU計算'],
                    'secondary_topics' => ['DNSレコード'],
                ],
                'question_mix' => [
                    'primary' => 5,
                    'secondary' => 3,
                    'diagnostic' => 2,
                ],
            ],
        );

        $selected = collect($prepared['selected_questions']);

        $this->assertSame('bank-v3-exposure', $prepared['selector_version']);
        $this->assertCount(10, $selected);
        $this->assertSame(10, $selected->pluck('question_id')->unique()->count());
        $this->assertGreaterThanOrEqual(2, $selected->where('selection_bucket', 'diagnostic')->count());
        $this->assertLessThanOrEqual(5, $selected->where('selection_bucket', 'primary')->count());
        $this->assertGreaterThanOrEqual(3, $selected->pluck('selection_domain')->unique()->count());
    }

    public function test_evaluation_prompt_requests_error_classification_without_guessing(): void
    {
        [, $plan, $task] = $this->studyPlan();

        $prompt = app(StudyPracticePromptService::class)->evaluationPrompt(
            $plan,
            $task,
            [[
                'id' => 'q1',
                'prompt' => '確認',
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
            ]],
            [[
                'question_id' => 'q1',
                'fields' => [[
                    'field_id' => 'answer',
                    'type' => 'single_choice',
                    'label' => '回答',
                    'value' => 'A',
                ]],
            ]],
        );

        $this->assertStringContainsString('calculation_slip', $prompt);
        $this->assertStringContainsString('weakness_topics', $prompt);
        $this->assertStringContainsString('原因を回答内容から判断できない場合はunknown', $prompt);
        $this->assertStringContainsString('単発の計算ミスやcarelessだけを', $prompt);
    }

    public function test_assessment_normalization_keeps_error_type_and_weakness_topics(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $key = "study_practice.{$plan->id}.{$task->id}";

        $this->actingAs($user)
            ->withSession([
                $key => [
                    'title' => '計算確認',
                    'questions' => [[
                        'id' => 'q1',
                        'prompt' => '可用性を選んでください。',
                        'response_fields' => [[
                            'id' => 'answer',
                            'type' => 'single_choice',
                            'label' => '回答',
                            'required' => true,
                            'choices' => [
                                ['id' => 'A', 'label' => '0.99'],
                                ['id' => 'B', 'label' => '0.98'],
                            ],
                        ]],
                    ]],
                    'answers' => [[
                        'question_id' => 'q1',
                        'fields' => [[
                            'field_id' => 'answer',
                            'type' => 'single_choice',
                            'label' => '回答',
                            'value' => 'B',
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
                    'score_percent' => 80,
                    'question_feedback' => [[
                        'question_id' => 'q1',
                        'correctness' => 'incorrect',
                        'feedback' => '式は正しいです。',
                        'reasoning_feedback' => '最後の加減算だけ誤っています。',
                        'error_type' => 'calculation_slip',
                        'weakness_topics' => ['可用性'],
                        'misconceptions' => [],
                    ]],
                    'strengths' => ['稼働率の式'],
                    'weaknesses' => [],
                    'recommended_task_progress_percent' => 60,
                    'evidence_summary' => '考え方は正しいが計算ミス。',
                    'next_action' => '別分野も含めて確認する',
                    'next_step' => [
                        'kind' => 'practice',
                        'label' => '弱点を再確認する',
                        'reason' => '理解度を確認するため',
                        'focus_topics' => ['可用性'],
                        'question_count' => 5,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ])
            ->assertRedirect(route('plans.tasks.study_practice.show', [$plan, $task]))
            ->assertSessionHasNoErrors();

        $attempt = StudyPracticeAttempt::latest('id')->firstOrFail();

        $this->assertSame(
            'calculation_slip',
            data_get($attempt->assessment, 'question_feedback.0.error_type'),
        );
        $this->assertSame(
            ['可用性'],
            data_get($attempt->assessment, 'question_feedback.0.weakness_topics'),
        );
    }

    public function test_strategy_ui_explains_balanced_question_mix(): void
    {
        $view = file_get_contents(resource_path('views/study_practice/show.blade.php'));

        $this->assertStringContainsString('重点弱点', $view);
        $this->assertStringContainsString('他の弱点・再確認', $view);
        $this->assertStringContainsString('横断診断', $view);
        $this->assertStringContainsString('単発ミスは重点固定しない', $view);
    }

    private function importAndPublish(): QuestionPack
    {
        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->post(route('admin.question_packs.import_bundled'), [
                'catalog_key' => 'ap/ap-a-canovia-core-v1',
            ])
            ->assertSessionHasNoErrors();

        $pack = QuestionPack::where('slug', 'ap-a-canovia-core-v1')->firstOrFail();

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->patch(route('admin.question_packs.status', $pack), [
                'status' => 'published',
            ])
            ->assertSessionHasNoErrors();

        return $pack->fresh();
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        string $key,
        array $data,
        $createdAt = null,
    ): StudyPracticeAttempt {
        $createdAt ??= now();

        return StudyPracticeAttempt::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', $key),
            'exercise_title' => $key,
            'questions' => [['id' => 'q1']],
            'answers' => [['question_id' => 'q1', 'fields' => []]],
            'assessment' => [
                'question_feedback' => $data['question_feedback'] ?? [],
            ],
            'score_percent' => $data['score_percent'] ?? 70,
            'strengths' => $data['strengths'] ?? [],
            'weaknesses' => $data['weaknesses'] ?? [],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'test',
            'next_action' => '次へ',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP対策',
            'description' => '応用情報技術者試験の科目Aに合格する',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => '科目Aの初期弱点補強を完了する',
            'description' => 'ネットワーク・DB・計算・品質特性を含めて弱点を補強する',
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
