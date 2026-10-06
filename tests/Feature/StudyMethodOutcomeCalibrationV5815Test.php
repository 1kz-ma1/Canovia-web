<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Study\StudyMethodOutcomeCalibrationService;
use App\Intelligence\Study\StudyMethodRecommendationService;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyMethodOutcomeCalibrationV5815Test extends TestCase
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

    public function test_no_observations_produces_zero_adjustment(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'recall',
        );

        $this->assertSame('observing', $signal['status']);
        $this->assertSame(0, $signal['observation_count']);
        $this->assertSame(0, $signal['applied_adjustment']);
    }

    public function test_one_or_two_observations_remain_observation_only(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'recall',
            [15, 20],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'recall',
        );

        $this->assertSame('observing', $signal['status']);
        $this->assertSame(2, $signal['observation_count']);
        $this->assertSame(0, $signal['applied_adjustment']);
    }

    public function test_three_consistent_positive_observations_create_bounded_positive_adjustment(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'resource_study',
            [10, 15, 20],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'resource_study',
        );

        $this->assertSame('active', $signal['status']);
        $this->assertSame(3, $signal['observation_count']);
        $this->assertSame(3, $signal['sample_count']);
        $this->assertSame(15, $signal['median_delta']);
        $this->assertSame(100, $signal['direction_ratio_percent']);
        $this->assertSame(3, $signal['raw_adjustment']);
        $this->assertSame(60, $signal['sample_strength_percent']);
        $this->assertSame(2, $signal['applied_adjustment']);
    }

    public function test_three_consistent_negative_observations_create_bounded_negative_adjustment(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'listening',
            [-10, -15, -20],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'listening',
        );

        $this->assertSame('active', $signal['status']);
        $this->assertSame(-15, $signal['median_delta']);
        $this->assertSame(-2, $signal['applied_adjustment']);
    }

    public function test_mixed_direction_observations_do_not_adjust_fit(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'dictation',
            [15, -10, 1],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'dictation',
        );

        $this->assertSame('mixed', $signal['status']);
        $this->assertSame(0, $signal['applied_adjustment']);
    }

    public function test_small_median_delta_is_neutral(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'shadowing',
            [1, 3, 4],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'shadowing',
        );

        $this->assertSame('neutral', $signal['status']);
        $this->assertSame(3, $signal['median_delta']);
        $this->assertSame(0, $signal['applied_adjustment']);
    }

    public function test_only_latest_five_observations_are_sampled(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'recall',
            [-50, 10, 15, 20, 25, 30],
        );

        $signal = $this->signal(
            $plan,
            $task,
            $user,
            'recall',
        );

        $this->assertSame(6, $signal['observation_count']);
        $this->assertSame(5, $signal['sample_count']);
        $this->assertSame(20, $signal['median_delta']);
        $this->assertSame(4, $signal['applied_adjustment']);
    }

    public function test_adjustment_is_capped_at_plus_or_minus_five(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->activityObservations(
            $task,
            $user,
            'recall',
            [50, 50, 50, 50, 50],
        );

        $positive = $this->signal(
            $plan,
            $task,
            $user,
            'recall',
        );

        $this->assertSame(5, $positive['raw_adjustment']);
        $this->assertSame(5, $positive['applied_adjustment']);

        [$otherUser, $otherPlan, $otherTask] =
            $this->scenario();

        $this->activityObservations(
            $otherTask,
            $otherUser,
            'resource_study',
            [-50, -50, -50, -50, -50],
        );

        $negative = $this->signal(
            $otherPlan,
            $otherTask,
            $otherUser,
            'resource_study',
        );

        $this->assertSame(-5, $negative['raw_adjustment']);
        $this->assertSame(-5, $negative['applied_adjustment']);
    }

    public function test_question_practice_is_never_calibrated(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practiceOnlyObservations(
            $task,
            $user,
            [10, 15, 20, 25],
        );

        $projection = app(
            StudyMethodOutcomeCalibrationService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );

        $this->assertFalse(
            $projection['question_practice_eligible'],
        );
        $this->assertArrayNotHasKey(
            'question_practice',
            $projection['methods'],
        );

        $recommendation = $this->recommend(
            $plan,
            $task,
            $user,
        );
        $question = $this->method(
            $recommendation,
            'question_practice',
        );

        $this->assertSame(
            'excluded',
            $question['outcome_signal_status'],
        );
        $this->assertSame(
            0,
            $question['outcome_adjustment'],
        );
    }

    public function test_primary_method_never_switches_from_outcome_calibration(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '簿記2級',
            '参考書の第3章を読む',
            '教材を読んで新しい論点を理解する',
        );

        $before = $this->recommend(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            'resource_study',
            data_get($before, 'primary.key'),
        );

        $this->activityObservations(
            $task,
            $user,
            'resource_study',
            [-20, -20, -20, -20, -20],
        );
        $this->activityObservations(
            $task,
            $user,
            'recall',
            [20, 20, 20, 20, 20],
            start: now()->subDays(6),
        );

        $after = $this->recommend(
            $plan,
            $task,
            $user,
        );

        $this->assertSame(
            'resource_study',
            data_get($after, 'primary.key'),
        );
        $this->assertSame(
            -4,
            data_get(
                $after,
                'primary.outcome_adjustment',
            ),
        );
        $this->assertFalse(
            data_get(
                $after,
                'outcome_calibration.primary_switch_allowed',
            ),
        );
    }

    public function test_calibration_can_reorder_alternatives_without_changing_primary(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'AP科目A',
            '分野横断問題演習',
            '過去問を解いて理解を確認する',
        );

        $before = $this->recommend(
            $plan,
            $task,
            $user,
        );

        $beforeKeys = collect(
            $before['alternatives'],
        )->pluck('key')->all();

        $this->assertSame(
            'question_practice',
            data_get($before, 'primary.key'),
        );
        $this->assertLessThan(
            array_search(
                'resource_study',
                $beforeKeys,
                true,
            ),
            array_search(
                'recall',
                $beforeKeys,
                true,
            ),
        );

        $this->activityObservations(
            $task,
            $user,
            'resource_study',
            [15, 15, 15, 15, 15],
        );

        $after = $this->recommend(
            $plan,
            $task,
            $user,
        );
        $afterKeys = collect(
            $after['alternatives'],
        )->pluck('key')->all();

        $this->assertSame(
            'question_practice',
            data_get($after, 'primary.key'),
        );
        $this->assertLessThan(
            array_search(
                'recall',
                $afterKeys,
                true,
            ),
            array_search(
                'resource_study',
                $afterKeys,
                true,
            ),
        );

        $resource = $this->method(
            $after,
            'resource_study',
        );

        $this->assertSame(
            3,
            $resource['outcome_adjustment'],
        );
    }

    public function test_calibration_is_actor_scoped_and_provider_free_read_only(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $other = User::factory()->create();

        $this->activityObservations(
            $task,
            $other,
            'recall',
            [20, 20, 20, 20, 20],
        );

        Http::fake();

        $beforeEvidence = TaskEvidence::query()->count();

        $recommendation = $this->recommend(
            $plan,
            $task,
            $user,
        );

        $recall = $this->method(
            $recommendation,
            'recall',
        );

        $this->assertSame(
            0,
            $recall['outcome_observation_count'],
        );
        $this->assertSame(
            0,
            $recall['outcome_adjustment'],
        );
        $this->assertSame(
            $beforeEvidence,
            TaskEvidence::query()->count(),
        );

        Http::assertNothingSent();
    }

    public function test_ui_explains_observational_calibration_and_no_primary_auto_switch(): void
    {
        $primaryView = file_get_contents(
            resource_path(
                'views/workspace/study/surfaces/study-method-recommendation.blade.php',
            ),
        );
        $methodsView = file_get_contents(
            resource_path(
                'views/workspace/study/surfaces/study-methods.blade.php',
            ),
        );
        $activityView = file_get_contents(
            resource_path(
                'views/study_activity/show.blade.php',
            ),
        );

        $this->assertStringContainsString(
            'data-study-method-outcome-adjustment',
            $primaryView,
        );
        $this->assertStringContainsString(
            'このSignalだけでPrimary Methodは自動変更しません。',
            $primaryView,
        );
        $this->assertStringContainsString(
            'data-study-method-alternative-outcome-adjustment',
            $methodsView,
        );
        $this->assertStringContainsString(
            '直近最大5件の中央値',
            $activityView,
        );
        $this->assertStringContainsString(
            '最大±5点',
            $activityView,
        );
    }

    private function signal(
        Plan $plan,
        Task $task,
        User $user,
        string $method,
    ): array {
        $projection = app(
            StudyMethodOutcomeCalibrationService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );

        return $projection['methods'][$method];
    }

    private function recommend(
        Plan $plan,
        Task $task,
        User $user,
    ): array {
        return app(
            StudyMethodRecommendationService::class,
        )->recommend(
            $plan,
            $task,
            ['key' => 'certification_exam'],
            [
                'has_confirmed_scope' => true,
                'current_position_known' => true,
            ],
            null,
            $user->id,
            null,
            null,
        );
    }

    private function method(
        array $recommendation,
        string $key,
    ): array {
        $methods = collect([
            $recommendation['primary'],
            ...$recommendation['alternatives'],
        ]);

        return $methods->firstWhere(
            'key',
            $key,
        );
    }

    /**
     * @param array<int,int> $deltas
     */
    private function activityObservations(
        Task $task,
        User $user,
        string $activity,
        array $deltas,
        ?Carbon $start = null,
    ): void {
        $time = ($start ?? now()->subDays(10))
            ->copy();
        $score = 40;

        $this->practice(
            $task,
            $user,
            $score,
            $time,
        );

        foreach ($deltas as $index => $delta) {
            $time = $time->copy()->addHours(2);

            $this->activity(
                $task,
                $user,
                $activity,
                $time,
            );

            $score = max(
                0,
                min(
                    100,
                    $score + $delta,
                ),
            );

            $time = $time->copy()->addHours(2);

            $this->practice(
                $task,
                $user,
                $score,
                $time,
            );

            if ($index < count($deltas) - 1) {
                $score = 40;
                $time = $time->copy()->addHours(2);

                $this->practice(
                    $task,
                    $user,
                    $score,
                    $time,
                );
            }
        }
    }

    /**
     * @param array<int,int> $deltas
     */
    private function practiceOnlyObservations(
        Task $task,
        User $user,
        array $deltas,
    ): void {
        $time = now()->subDays(5);
        $score = 40;

        $this->practice(
            $task,
            $user,
            $score,
            $time,
        );

        foreach ($deltas as $delta) {
            $score = max(
                0,
                min(
                    100,
                    $score + $delta,
                ),
            );
            $time = $time->copy()->addHours(4);

            $this->practice(
                $task,
                $user,
                $score,
                $time,
            );
        }
    }

    private function activity(
        Task $task,
        User $user,
        string $activity,
        Carbon $occurredAt,
    ): TaskEvidence {
        return match ($activity) {
            'recall' => $this->evidence(
                $task,
                $user,
                'study_recall_reviewed',
                ['rating' => 'good'],
                $occurredAt,
            ),
            'resource_study' => $this->evidence(
                $task,
                $user,
                'study_resource_study_completed',
                ['outcome_rating' => 'partial'],
                $occurredAt,
            ),
            'listening',
            'dictation',
            'shadowing' => $this->evidence(
                $task,
                $user,
                'study_language_activity_completed',
                [
                    'activity_type' => $activity,
                    'outcome_rating' => 'partial',
                ],
                $occurredAt,
            ),
            default => throw new \InvalidArgumentException(
                'Unsupported activity '.$activity,
            ),
        };
    }

    private function practice(
        Task $task,
        User $user,
        int $score,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_practice_assessed',
            ['score_percent' => $score],
            $occurredAt,
        );
    }

    private function evidence(
        Task $task,
        User $user,
        string $type,
        array $metadata,
        Carbon $occurredAt,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => 0.8,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }

    private function scenario(
        string $planTitle = '応用情報技術者試験',
        string $taskTitle = '科目Aの分野横断問題演習',
        string $taskDescription = '過去問を解いて理解を確認する',
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
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskDescription,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
