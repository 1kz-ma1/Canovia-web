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
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyBehaviorPersonalizationV5828Test extends TestCase
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

    public function test_three_assessed_attempts_mark_practice_focused_without_rewriting_initial_diagnosis(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->attempt($user, $plan, $task, 70);
        $this->attempt($user, $plan, $task, 80);

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $before = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertNull(data_get(
            $before->observed_context,
            'study_behavior.stage',
        ));
        $this->assertNull(data_get(
            $before->feature_readiness,
            'study.practice_focused.enabled',
        ));

        $this->attempt($user, $plan, $task, 90);

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $after = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            'practice_focused',
            data_get(
                $after->observed_context,
                'study_behavior.stage',
            ),
        );
        $this->assertSame(
            3,
            data_get(
                $after->observed_context,
                'study_behavior.assessed_attempt_count',
            ),
        );
        $this->assertSame(
            80,
            data_get(
                $after->observed_context,
                'study_behavior.recent_average_score_percent',
            ),
        );
        $this->assertTrue((bool) data_get(
            $after->feature_readiness,
            'study.practice_focused.enabled',
        ));
        $this->assertSame(
            'auto_applied',
            data_get(
                $after->inferred_context,
                'update_candidates.study_practice_focused.status',
            ),
        );
        $this->assertSame(
            'started',
            data_get(
                $after->self_reported_context,
                'domain_context.study.stage',
            ),
        );
        $this->assertSame(
            'standard',
            $after->guidance_level,
        );

        $this->actingAs($user)
            ->get(route('personalization.updates.index'))
            ->assertOk()
            ->assertSee(
                'data-growth-experience="study_practice_focused"',
                false,
            )
            ->assertSee('演習中心の学習段階に入っています')
            ->assertSee('能力レベルの再分類ではなく')
            ->assertSee('初回に回答した学習段階、Guidance Level、Plan内容は変更していません。');
    }

    public function test_later_attempts_refresh_observed_metrics_without_duplicate_candidate_or_auto_apply(): void
    {
        [$user, $plan, $task] = $this->scenario();

        foreach ([60, 70, 80] as $score) {
            $this->attempt($user, $plan, $task, $score);
        }

        $request = $this->requestFor($user);
        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice($request, $plan);

        $candidateEventsBefore = $this->candidateEventCount();
        $appliedEventsBefore = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateAutoApplied->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'study_practice_focused',
            )
            ->count();

        $this->attempt($user, $plan, $task, 100);

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertSame(
            4,
            data_get(
                $context->observed_context,
                'study_behavior.assessed_attempt_count',
            ),
        );
        $this->assertSame(
            78,
            data_get(
                $context->observed_context,
                'study_behavior.recent_average_score_percent',
            ),
        );
        $this->assertSame(
            $candidateEventsBefore,
            $this->candidateEventCount(),
        );

        $appliedEventsAfter = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextUpdateAutoApplied->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'candidate_key')
                        === 'study_practice_focused',
            )
            ->count();

        $this->assertSame(
            $appliedEventsBefore,
            $appliedEventsAfter,
        );
        $this->assertSame(
            'started',
            data_get(
                $context->self_reported_context,
                'domain_context.study.stage',
            ),
        );
    }

    public function test_development_only_personalization_is_not_profiled_from_study_attempts(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['development'],
                'weekly_capacity' => '5_10',
                'development_goal' => 'Canovia',
                'development_experience' => 'standard',
                'development_stage' => 'existing',
                'github_usage' => 'yes',
                'repository_ready' => 'yes',
            ])
            ->assertRedirect(route('personalization.result'));

        [$plan, $task] = $this->studyPlan($user);

        foreach ([70, 80, 90] as $score) {
            $this->attempt($user, $plan, $task, $score);
        }

        app(StudyBehaviorPersonalizationAdapter::class)
            ->observeAssessedPractice(
                $this->requestFor($user),
                $plan,
            );

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $this->assertNull(data_get(
            $context->observed_context,
            'study_behavior.stage',
        ));
        $this->assertNull(data_get(
            $context->inferred_context,
            'update_candidates.study_practice_focused',
        ));
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
                        === 'study_practice_focused',
            )
            ->count();
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

        [$plan, $task] = $this->studyPlan($user);

        return [$user, $plan, $task];
    }

    /**
     * @return array{0:Plan,1:Task}
     */
    private function studyPlan(User $user): array
    {
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報 科目A対策',
            'description' => '演習中心の学習へ移行する',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '科目A 演習',
            'description' => '分野横断問題を解く',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$plan, $task];
    }

    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
    ): StudyPracticeAttempt {
        return StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => 'AP Practice',
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
            'evidence_summary' => 'test',
            'next_action' => '次へ',
        ]);
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create(
            '/personalization/study-behavior',
            'POST',
        );
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
