<?php

namespace Tests\Feature;

use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyScoreObservation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyScoreBaselineV5616Test extends TestCase
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

    public function test_score_capture_page_renders_profile_sources_and_form(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            'TOEIC対策',
        );

        $this->actingAs($user)
            ->get(route('plans.study_scores.index', $plan))
            ->assertOk()
            ->assertSee('SCORE / BASELINE EVIDENCE')
            ->assertSee('TOEIC')
            ->assertSee('0〜990')
            ->assertSee('公式結果')
            ->assertSee('模試・模擬試験')
            ->assertSee('name="request_id"', false)
            ->assertSee('name="components[listening]"', false)
            ->assertSee('name="components[reading]"', false)
            ->assertSee(
                route('plans.study_scores.store', $plan),
                false,
            );
    }

    public function test_toeic_score_is_stored_as_external_evidence_and_rendered_separately_from_practice_accuracy(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            'TOEIC診断問題',
        );

        $this->practiceAttempt($user, $plan, $task, 84);

        $requestId = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => $requestId,
                'score_value' => 480,
                'source_kind' => 'official_result',
                'source_label' => '公式結果票',
                'observed_at' => today()->subDay()->toDateString(),
                'components' => [
                    'listening' => 250,
                    'reading' => 230,
                ],
            ])
            ->assertRedirect(route('plans.study_scores.index', $plan))
            ->assertSessionHasNoErrors();

        $observation = StudyScoreObservation::query()->firstOrFail();

        $this->assertSame('toeic_total', $observation->metric_key);
        $this->assertSame('TOEIC', $observation->metric_label);
        $this->assertSame(480.0, $observation->score_value);
        $this->assertSame(0.0, $observation->scale_min);
        $this->assertSame(990.0, $observation->scale_max);
        $this->assertSame('official_result', $observation->source_kind);
        $this->assertSame('公式結果票', $observation->source_label);
        $this->assertSame(2, count($observation->components));

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertSee('data-study-score-state', false)
            ->assertSee('480点')
            ->assertSee('600点')
            ->assertSee('あと120')
            ->assertSee('PRACTICE ACC.')
            ->assertSee('84%')
            ->assertSee('Canovia内・別尺度')
            ->assertSee(
                route('plans.study_scores.index', $plan),
                false,
            );
    }

    public function test_practice_accuracy_does_not_satisfy_score_exam_external_baseline(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            'TOEIC診断問題',
        );

        $this->practiceAttempt($user, $plan, $task, 84);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertSee('現在スコアがまだ分かりません')
            ->assertSee('現在スコアを記録')
            ->assertSee(
                route('plans.study_scores.index', $plan),
                false,
            );

        // Work intentionally surfaces missing baseline; Analysis shows the
        // independent in-app practice accuracy without turning it into TOEIC.
        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee('84%')
            ->assertSee('外部スコアBaseline待ち')
            ->assertDontSee('CURRENT SCORE</p>'."\n".'                <p class="mt-2 text-2xl font-black text-slate-100">84', false);
    }

    public function test_external_score_alone_makes_score_baseline_known_without_creating_practice_attempt(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            '頻出語彙を確認',
        );

        $this->scoreObservation(
            $user,
            $plan,
            510,
            'toeic_total',
            'TOEIC',
            0,
            990,
        );

        $beforeAttempts = StudyPracticeAttempt::query()->count();

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee('510点')
            ->assertSee('あと90')
            ->assertDontSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            );

        $this->assertSame(
            $beforeAttempts,
            StudyPracticeAttempt::query()->count(),
        );
    }

    public function test_toeic_out_of_range_score_and_component_are_rejected(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            'TOEIC学習',
        );

        $this->actingAs($user)
            ->from(route('plans.study_scores.index', $plan))
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 1000,
                'source_kind' => 'self_reported',
                'observed_at' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('score_value');

        $this->actingAs($user)
            ->from(route('plans.study_scores.index', $plan))
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 480,
                'source_kind' => 'self_reported',
                'observed_at' => today()->toDateString(),
                'components' => [
                    'listening' => 500,
                ],
            ])
            ->assertSessionHasErrors('components.listening');

        $this->assertDatabaseCount('study_score_observations', 0);
    }

    public function test_ielts_requires_half_band_steps_and_preserves_components(): void
    {
        [$user, $plan] = $this->scenario(
            'IELTS 6.5を取る',
            '英語学習',
            'IELTS対策',
        );

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 5.3,
                'source_kind' => 'mock_exam',
                'observed_at' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('score_value');

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 5.5,
                'source_kind' => 'mock_exam',
                'observed_at' => today()->toDateString(),
                'components' => [
                    'listening' => 6.0,
                    'reading' => 5.5,
                    'writing' => 5.0,
                    'speaking' => 5.5,
                ],
            ])
            ->assertRedirect(route('plans.study_scores.index', $plan))
            ->assertSessionHasNoErrors();

        $observation = StudyScoreObservation::query()->firstOrFail();

        $this->assertSame('ielts_overall', $observation->metric_key);
        $this->assertSame(5.5, $observation->score_value);
        $this->assertCount(4, $observation->components);
        $this->assertSame(
            6.0,
            (float) collect($observation->components)
                ->firstWhere('key', 'listening')['value'],
        );
    }

    public function test_score_capture_is_idempotent_by_request_id(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 600点',
            '英語学習',
            'TOEIC対策',
        );

        $requestId = (string) Str::uuid();
        $payload = [
            'request_id' => $requestId,
            'score_value' => 480,
            'source_kind' => 'self_reported',
            'observed_at' => today()->toDateString(),
        ];

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), $payload)
            ->assertRedirect(route('plans.study_scores.index', $plan))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('study_score_observations', 1);
    }

    public function test_future_observed_date_is_rejected(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 600点',
            '英語学習',
            'TOEIC対策',
        );

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 480,
                'source_kind' => 'self_reported',
                'observed_at' => today()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('observed_at');

        $this->assertDatabaseCount('study_score_observations', 0);
    }

    public function test_school_test_target_is_parsed_and_previous_score_uses_school_scale(): void
    {
        [$user, $plan] = $this->scenario(
            '数学II 中間テストで80点',
            '定期テスト学習',
            '数学IIを勉強する',
        );

        $learningType = app(StudyLearningTypeRouter::class)
            ->route($plan);

        $this->assertSame('school_test', $learningType['key']);
        $this->assertSame(80, $learningType['target_score']);

        $this->actingAs($user)
            ->post(route('plans.study_scores.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'score_value' => 62,
                'source_kind' => 'school_result',
                'observed_at' => today()->subWeek()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $observation = StudyScoreObservation::query()->firstOrFail();
        $this->assertSame(
            'school_test_score',
            $observation->metric_key,
        );
        $this->assertSame(62.0, $observation->score_value);
        $this->assertSame(100.0, $observation->scale_max);
    }

    public function test_foreign_actor_score_is_not_used_as_current_score(): void
    {
        [$owner, $plan] = $this->scenario(
            'TOEIC 600点を取る',
            '英語学習',
            'TOEIC対策',
        );
        $other = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->scoreObservation(
            $other,
            $plan,
            700,
            'toeic_total',
            'TOEIC',
            0,
            990,
        );

        $this->actingAs($owner)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertDontSee('700点');

        $this->actingAs($owner)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'analysis',
            ]))
            ->assertOk()
            ->assertSee('外部スコアBaseline待ち')
            ->assertDontSee('700点');
    }

    /**
     * @return array{0:User,1:Plan,2:Task}
     */
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

    private function practiceAttempt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
    ): StudyPracticeAttempt {
        return StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'exercise_title' => 'TOEIC Practice',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => [],
            ],
            'score_percent' => $score,
            'strengths' => [],
            'weaknesses' => [],
            'recommended_task_progress_percent' => 50,
            'evidence_summary' => 'Practice accuracy',
            'next_action' => 'continue',
        ]);
    }

    private function scoreObservation(
        User $user,
        Plan $plan,
        float $score,
        string $metricKey,
        string $metricLabel,
        float $min,
        float $max,
    ): StudyScoreObservation {
        return StudyScoreObservation::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'request_id' => (string) Str::uuid(),
            'metric_key' => $metricKey,
            'metric_label' => $metricLabel,
            'score_value' => $score,
            'scale_min' => $min,
            'scale_max' => $max,
            'unit' => 'score',
            'source_kind' => 'self_reported',
            'source_label' => null,
            'components' => [],
            'observed_at' => now(),
        ]);
    }
}
