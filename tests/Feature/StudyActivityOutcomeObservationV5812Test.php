<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Intelligence\Study\StudyActivityOutcomeObservationService;
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

class StudyActivityOutcomeObservationV5812Test extends TestCase
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

    public function test_adjacent_practice_assessments_without_intervening_activity_map_to_question_practice(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice($task, $user, 60, now()->subDays(2));
        $this->practice($task, $user, 75, now()->subDay());

        $projection = $this->project($plan, $task, $user);
        $practice = $this->method($projection, 'question_practice');

        $this->assertSame(2, $projection['practice_assessment_count']);
        $this->assertSame(1, $projection['valid_observation_pair_count']);
        $this->assertSame(1, $practice['observation_count']);
        $this->assertSame(60, $practice['average_before_score']);
        $this->assertSame(75, $practice['average_after_score']);
        $this->assertSame(15, $practice['average_score_delta']);
    }

    public function test_multiple_recall_reviews_between_practice_assessments_form_one_recall_observation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 50, $base);
        $this->recall($task, $user, 'again', $base->copy()->addHours(2));
        $this->recall($task, $user, 'good', $base->copy()->addHours(4));
        $this->recall($task, $user, 'easy', $base->copy()->addHours(6));
        $this->practice($task, $user, 70, $base->copy()->addDay());

        $projection = $this->project($plan, $task, $user);
        $recall = $this->method($projection, 'recall');

        $this->assertSame(1, $projection['valid_observation_pair_count']);
        $this->assertSame(3, $recall['usage_count']);
        $this->assertSame(1, $recall['observation_count']);
        $this->assertSame(20, $recall['average_score_delta']);
    }

    public function test_multiple_same_language_events_stay_one_score_observation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 40, $base);
        $this->language($task, $user, 'listening', $base->copy()->addHours(1));
        $this->language($task, $user, 'listening', $base->copy()->addHours(3));
        $this->practice($task, $user, 65, $base->copy()->addDay());

        $projection = $this->project($plan, $task, $user);
        $listening = $this->method($projection, 'listening');

        $this->assertSame(2, $listening['usage_count']);
        $this->assertSame(1, $listening['observation_count']);
        $this->assertSame(25, $listening['latest_score_delta']);
    }

    public function test_mixed_activity_interval_is_excluded_from_per_activity_comparison(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 55, $base);
        $this->recall($task, $user, 'good', $base->copy()->addHour());
        $this->language($task, $user, 'listening', $base->copy()->addHours(2));
        $this->practice($task, $user, 80, $base->copy()->addDay());

        $projection = $this->project($plan, $task, $user);

        $this->assertSame(0, $projection['valid_observation_pair_count']);
        $this->assertSame(1, $projection['ambiguous_interval_count']);
        $this->assertSame(0, $this->method($projection, 'recall')['observation_count']);
        $this->assertSame(0, $this->method($projection, 'listening')['observation_count']);
    }

    public function test_unknown_language_activity_is_excluded_instead_of_becoming_question_practice(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 55, $base);
        $this->evidence(
            $task,
            $user,
            'study_language_activity_completed',
            [
                'activity_type' => 'future_activity',
                'rounds' => 2,
                'outcome_rating' => 'partial',
            ],
            $base->copy()->addHours(2),
        );
        $this->practice($task, $user, 70, $base->copy()->addDay());

        $projection = $this->project($plan, $task, $user);

        $this->assertSame(0, $projection['valid_observation_pair_count']);
        $this->assertSame(1, $projection['ambiguous_interval_count']);
        $this->assertSame(
            0,
            $this->method($projection, 'question_practice')['observation_count'],
        );
    }

    public function test_interval_over_fourteen_days_is_excluded(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(20);
        $this->practice($task, $user, 50, $base);
        $this->recall($task, $user, 'good', $base->copy()->addDay());
        $this->practice($task, $user, 90, $base->copy()->addDays(15));

        $projection = $this->project($plan, $task, $user);

        $this->assertSame(0, $projection['valid_observation_pair_count']);
        $this->assertSame(1, $projection['stale_interval_count']);
    }

    public function test_actor_evidence_is_isolated(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $other = User::factory()->create();

        $base = now()->subDays(2);
        $this->practice($task, $user, 60, $base);
        $this->language($task, $other, 'listening', $base->copy()->addHours(3));
        $this->practice($task, $user, 72, $base->copy()->addDay());

        $projection = $this->project($plan, $task, $user);

        $this->assertSame(1, $projection['valid_observation_pair_count']);
        $this->assertSame(
            1,
            $this->method($projection, 'question_practice')['observation_count'],
        );
        $this->assertSame(
            0,
            $this->method($projection, 'listening')['usage_count'],
        );
    }

    public function test_invalid_practice_score_is_ignored_as_assessment_boundary(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(3);
        $this->evidence(
            $task,
            $user,
            'study_practice_assessed',
            ['score_percent' => 140],
            $base,
        );
        $this->practice($task, $user, 50, $base->copy()->addDay());
        $this->practice($task, $user, 55, $base->copy()->addDays(2));

        $projection = $this->project($plan, $task, $user);

        $this->assertSame(2, $projection['practice_assessment_count']);
        $this->assertSame(1, $projection['valid_observation_pair_count']);
        $this->assertSame(
            5,
            $this->method($projection, 'question_practice')['average_score_delta'],
        );
    }

    public function test_average_and_latest_deltas_are_deterministic_and_resource_study_waits_without_explicit_evidence(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(4);
        $this->practice($task, $user, 50, $base);
        $this->recall($task, $user, 'good', $base->copy()->addHours(2));
        $this->practice($task, $user, 60, $base->copy()->addDay());
        $this->recall($task, $user, 'good', $base->copy()->addDay()->addHours(2));
        $this->practice($task, $user, 80, $base->copy()->addDays(2));

        $projection = $this->project($plan, $task, $user);
        $recall = $this->method($projection, 'recall');
        $resource = $this->method($projection, 'resource_study');

        $this->assertSame(2, $recall['observation_count']);
        $this->assertSame(55, $recall['average_before_score']);
        $this->assertSame(70, $recall['average_after_score']);
        $this->assertSame(15, $recall['average_score_delta']);
        $this->assertSame(60, $recall['latest_before_score']);
        $this->assertSame(80, $recall['latest_after_score']);
        $this->assertSame(20, $recall['latest_score_delta']);

        $this->assertSame('waiting', $resource['measurement_status']);
        $this->assertSame(0, $resource['observation_count']);
    }

    public function test_study_activity_page_shows_waiting_observation_panel_without_provider_call(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->practice($task, $user, 70, now()->subHour());

        Http::fake();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_activity.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee(
                'data-study-activity-outcome-observation',
                false,
            )
            ->assertSee('実利用でのActivity観測')
            ->assertSee('観測待ち')
            ->assertSee('因果効果の証明ではなく');

        Http::assertNothingSent();
    }

    public function test_study_activity_page_shows_observed_recall_before_after_delta(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $base = now()->subDays(2);
        $this->practice($task, $user, 58, $base);
        $this->recall($task, $user, 'good', $base->copy()->addHours(2));
        $this->practice($task, $user, 73, $base->copy()->addDay());

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_activity.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee(
                'data-study-activity-outcome-method="recall"',
                false,
            )
            ->assertSee('平均前後差 +15pt')
            ->assertSee('58%')
            ->assertSee('73%');
    }

    public function test_single_activity_observation_is_visible_but_does_not_adjust_or_switch_method(): void
    {
        [$user, $plan, $task] = $this->scenario(
            'TOEIC語彙暗記',
            '英単語を暗記する',
        );

        $service = app(StudyMethodRecommendationService::class);

        $before = $service->recommend(
            $plan,
            $task,
            ['key' => 'memorization'],
            ['has_confirmed_scope' => false],
            null,
            $user->id,
            null,
            null,
        );

        $base = now()->subDays(2);
        $this->practice($task, $user, 40, $base);
        $this->language($task, $user, 'listening', $base->copy()->addHours(2));
        $this->practice($task, $user, 90, $base->copy()->addDay());

        $after = $service->recommend(
            $plan,
            $task,
            ['key' => 'memorization'],
            ['has_confirmed_scope' => false],
            null,
            $user->id,
            null,
            null,
        );

        $this->assertSame(
            data_get($before, 'primary.key'),
            data_get($after, 'primary.key'),
        );
        $this->assertSame(
            data_get($before, 'primary.fit_score'),
            data_get($after, 'primary.fit_score'),
        );
        $this->assertSame(
            'recall',
            data_get($after, 'primary.key'),
        );

        $listening = collect([
            $after['primary'],
            ...$after['alternatives'],
        ])->firstWhere('key', 'listening');

        $this->assertSame(
            1,
            $listening['outcome_observation_count'],
        );
        $this->assertSame(
            'observing',
            $listening['outcome_signal_status'],
        );
        $this->assertSame(
            0,
            $listening['outcome_adjustment'],
        );
        $this->assertFalse(
            data_get(
                $after,
                'outcome_calibration.primary_switch_allowed',
            ),
        );
    }

    private function project(
        Plan $plan,
        Task $task,
        User $user,
    ): array {
        return app(
            StudyActivityOutcomeObservationService::class,
        )->project(
            $plan,
            $task,
            $user->id,
            null,
        );
    }

    private function method(
        array $projection,
        string $key,
    ): array {
        return collect($projection['methods'])
            ->firstWhere('key', $key);
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
            [
                'score_percent' => $score,
                'evidence_summary' => 'Practice '.$score.'%',
            ],
            $occurredAt,
        );
    }

    private function recall(
        Task $task,
        User $user,
        string $rating,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_recall_reviewed',
            [
                'rating' => $rating,
                'interval_days' => 1,
            ],
            $occurredAt,
            0.75,
        );
    }

    private function language(
        Task $task,
        User $user,
        string $activity,
        Carbon $occurredAt,
    ): TaskEvidence {
        return $this->evidence(
            $task,
            $user,
            'study_language_activity_completed',
            [
                'activity_type' => $activity,
                'rounds' => 3,
                'outcome_rating' => 'partial',
            ],
            $occurredAt,
            0.6,
        );
    }

    private function evidence(
        Task $task,
        User $user,
        string $type,
        array $metadata,
        Carbon $occurredAt,
        float $confidence = 1.0,
    ): TaskEvidence {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'source' => EvidenceSource::Native->value,
            'type' => $type,
            'external_key' => $type.':'.Str::uuid(),
            'confidence' => $confidence,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata,
        ]);
    }

    private function scenario(
        string $planTitle = '応用情報技術者試験',
        string $taskTitle = 'ネットワーク分野を学習する',
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $planTitle,
            'description' => $planTitle.'の学習',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => '学習結果を確認する',
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
