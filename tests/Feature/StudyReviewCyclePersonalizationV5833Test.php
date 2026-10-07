<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use App\Services\StudyBehaviorPersonalizationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyReviewCyclePersonalizationV5833Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'native_ai.driver' => 'disabled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_day_volume_does_not_imply_review_cycle(): void
    {
        [$user, $plan, $task] = $this->scenario();

        foreach ([60, 70, 75, 80, 85] as $score) {
            $this->attemptAt(
                $user,
                $plan,
                $task,
                $score,
                '2026-10-01 09:00:00',
            );
        }

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $context = $this->context($user);

        $this->assertNull(data_get(
            $context->observed_context,
            'study_behavior.review_cycle',
        ));
        $this->assertNull(data_get(
            $context->inferred_context,
            'update_candidates.study_review_cycle',
        ));
        $this->assertNull(data_get(
            $context->feature_readiness,
            'study.review_cycle.enabled',
        ));
    }

    public function test_spaced_assessed_practice_marks_review_cycle_without_claiming_retention(): void
    {
        [$user, $plan, $task] = $this->scenario();

        foreach ([65, 70, 75] as $score) {
            $this->attemptAt(
                $user,
                $plan,
                $task,
                $score,
                '2026-10-01 09:00:00',
            );
        }
        foreach ([80, 85] as $score) {
            $this->attemptAt(
                $user,
                $plan,
                $task,
                $score,
                '2026-10-04 09:00:00',
            );
        }

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $context = $this->context($user);
        $reviewCycle = data_get(
            $context->observed_context,
            'study_behavior.review_cycle',
        );

        $this->assertIsArray($reviewCycle);
        $this->assertSame(
            'spaced_review_observed',
            data_get($reviewCycle, 'stage'),
        );
        $this->assertSame(
            5,
            data_get($reviewCycle, 'assessed_attempt_count'),
        );
        $this->assertSame(
            2,
            data_get($reviewCycle, 'distinct_practice_days'),
        );
        $this->assertSame(3, data_get($reviewCycle, 'span_days'));

        $this->assertSame(
            'auto_applied',
            data_get(
                $context->inferred_context,
                'update_candidates.study_review_cycle.status',
            ),
        );
        $this->assertTrue((bool) data_get(
            $context->feature_readiness,
            'study.review_cycle.enabled',
        ));

        $this->assertSame(
            'started',
            data_get(
                $context->self_reported_context,
                'domain_context.study.stage',
            ),
        );
        $this->assertSame('standard', $context->guidance_level);

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertOk()
            ->assertSee(
                'data-growth-experience="study_review_cycle"',
                false,
            )
            ->assertSee('復習サイクルに入っています')
            ->assertSee('定着や習熟を自動判定したものではありません');

        $this->assertSame(1, $this->candidateEventCount());
        $this->assertSame(1, $this->autoAppliedEventCount());
    }

    public function test_later_spaced_attempt_refreshes_observation_without_duplicate_candidate_or_auto_apply(): void
    {
        [$user, $plan, $task] = $this->scenario();

        foreach ([60, 70, 80] as $score) {
            $this->attemptAt(
                $user,
                $plan,
                $task,
                $score,
                '2026-10-01 09:00:00',
            );
        }
        foreach ([80, 90] as $score) {
            $this->attemptAt(
                $user,
                $plan,
                $task,
                $score,
                '2026-10-04 09:00:00',
            );
        }

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $this->attemptAt(
            $user,
            $plan,
            $task,
            95,
            '2026-10-08 09:00:00',
        );

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $reviewCycle = data_get(
            $this->context($user)->observed_context,
            'study_behavior.review_cycle',
        );

        $this->assertSame(
            6,
            data_get($reviewCycle, 'assessed_attempt_count'),
        );
        $this->assertSame(
            3,
            data_get($reviewCycle, 'distinct_practice_days'),
        );
        $this->assertSame(7, data_get($reviewCycle, 'span_days'));

        $this->assertSame(1, $this->candidateEventCount());
        $this->assertSame(1, $this->autoAppliedEventCount());
    }

    /**
     * @return array{0:User,1:Plan,2:Task}
     */
    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study'],
                'weekly_capacity' => '5_10',
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
            ])
            ->assertRedirect(route('personalization.result'));

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報 科目A復習',
            'description' => '時間を空けて再演習する',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '科目A 復習演習',
            'description' => '分野横断問題を再演習する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 30,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function attemptAt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
        string $at,
    ): StudyPracticeAttempt {
        Carbon::setTestNow(Carbon::parse($at));

        try {
            return StudyPracticeAttempt::query()->create([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'request_hash' => hash(
                    'sha256',
                    (string) Str::uuid(),
                ),
                'exercise_title' => 'AP Review Practice',
                'questions' => [],
                'answers' => [],
                'assessment' => [
                    'score_percent' => $score,
                    'strengths' => [],
                    'weaknesses' => [],
                ],
                'score_percent' => $score,
                'strengths' => [],
                'weaknesses' => [],
                'recommended_task_progress_percent' => 50,
                'evidence_summary' => 'review-cycle-test',
                'next_action' => '次へ',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function context(
        User $user,
    ): UserPersonalizationContext {
        return UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function candidateEventCount(): int
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateCandidateCreated->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'study_review_cycle',
            )
            ->count();
    }

    private function autoAppliedEventCount(): int
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateAutoApplied->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'study_review_cycle',
            )
            ->count();
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create(
            '/personalization/study-review-cycle',
            'POST',
        );
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
