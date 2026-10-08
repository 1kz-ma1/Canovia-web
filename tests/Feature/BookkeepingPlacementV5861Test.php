<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyScoreObservation;
use App\Models\Task;
use App\Models\User;
use App\Services\BookkeepingPlacementDiagnosticService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookkeepingPlacementV5861Test extends TestCase
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

    public function test_bookkeeping_plan_shows_optional_placement_on_study_preparation(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '簿記3級の授業と2級の先取り');

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
                'surface' => 'preparation',
            ]))
            ->assertOk()
            ->assertSee('data-study-bookkeeping-starting-point', false)
            ->assertSee('data-study-bookkeeping-diagnostic-link', false)
            ->assertSee(route('plans.bookkeeping_placement.show', $plan), false);

        $this->actingAs($user)
            ->get(route('plans.bookkeeping_placement.show', $plan))
            ->assertOk()
            ->assertSee('data-bookkeeping-placement-form', false)
            ->assertSee('wants_advance')
            ->assertSee('3級の復習に加えて2級も先取りしたい')
            ->assertDontSee('data-bookkeeping-placement-history', false);

        $this->assertDatabaseCount('study_score_observations', 0);
    }

    public function test_good_diagnostic_supports_trial_of_grade_two_without_claiming_mastery(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '簿記3級の復習をしながら2級も試す');
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '授業の復習',
            'status' => 'doing',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
        $service = app(BookkeepingPlacementDiagnosticService::class);
        $answers = collect($service->questions())
            ->mapWithKeys(fn (array $q) => [$q['id'] => $q['correct']])
            ->all();
        $rid = (string) Str::uuid();

        $this->actingAs($user)
            ->post(route('plans.bookkeeping_placement.store', $plan), [
                'request_id' => $rid,
                'wants_advance' => '1',
                'answers' => $answers,
            ])
            ->assertRedirect(route('plans.bookkeeping_placement.show', $plan));

        $observation = StudyScoreObservation::query()->sole();
        $this->assertSame($plan->id, $observation->plan_id);
        $this->assertSame($user->id, $observation->user_id);
        $this->assertSame('mock_exam', $observation->source_kind);
        $this->assertSame(BookkeepingPlacementDiagnosticService::METRIC, $observation->metric_key);
        $this->assertEquals(100.0, $observation->score_value);
        $this->assertSame(1, (int) $observation->components['_advance_interest']);

        $this->actingAs($user)
            ->get(route('plans.bookkeeping_placement.show', $plan))
            ->assertOk()
            ->assertSee('data-bookkeeping-placement-history', false)
            ->assertSee('data-bookkeeping-placement-suggestion', false)
            ->assertSee('2級の導入単元を試す候補')
            ->assertSee('合格や級全体の習熟を判定しません');

        $this->assertSame(25, (int) $task->fresh()->progress_percent);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertDatabaseCount('task_evidences', 0);

        // Browser retries the same submission: no second observation.
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_placement.store', $plan), [
                'request_id' => $rid,
                'wants_advance' => '1',
                'answers' => $answers,
            ])
            ->assertRedirect(route('plans.bookkeeping_placement.show', $plan));

        $this->assertDatabaseCount('study_score_observations', 1);
    }

    public function test_weak_topic_forces_targeted_review_even_with_high_total_and_advance_interest(): void
    {
        $service = app(BookkeepingPlacementDiagnosticService::class);
        $questions = $service->questions();
        $answers = collect($questions)
            ->mapWithKeys(fn (array $q) => [$q['id'] => $q['correct']])
            ->all();

        // Only two mistakes, both in the same prerequisite: overall 83%
        // still must not make a blanket Grade 2 advancement claim.
        $answers['q10'] = 'D';
        $answers['q11'] = 'D';
        $result = $service->assess($answers, wantsAdvance: true);

        $this->assertSame(83, $result['score_percent']);
        $this->assertSame(['adjusting_entries'], $result['weak_topics']);
        $this->assertSame('review_prerequisites', $result['status']);

        $user = User::factory()->create();
        $plan = $this->plan($user, '日商簿記3級・2級の学習');
        $this->actingAs($user)
            ->post(route('plans.bookkeeping_placement.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'wants_advance' => '1',
                'answers' => $answers,
            ])
            ->assertRedirect(route('plans.bookkeeping_placement.show', $plan));

        $this->actingAs($user)
            ->get(route('plans.bookkeeping_placement.show', $plan))
            ->assertSee('決算整理')
            ->assertSee('弱い単元を先に短く復習');
    }

    public function test_unrelated_study_plans_and_unauthorized_actors_cannot_write_diagnostic_results(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner, '日商簿記3級・2級');
        $notBookkeeping = $this->plan($owner, '英語資格');

        $this->actingAs($other)
            ->get(route('plans.bookkeeping_placement.show', $plan))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('plans.bookkeeping_placement.show', $notBookkeeping))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('plans.bookkeeping_placement.store', $plan), [
                'request_id' => (string) Str::uuid(),
                'wants_advance' => '1',
                'answers' => ['q01' => 'A'],
            ])
            ->assertSessionHasErrors('answers');

        $this->assertDatabaseCount('study_score_observations', 0);

        $this->actingAs($owner)
            ->get(route('workspace.study.index', [
                'plan_id' => $notBookkeeping->id,
                'surface' => 'preparation',
            ]))
            ->assertOk()
            ->assertDontSee('data-study-bookkeeping-diagnostic-link', false);
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => '学校の授業を復習し、進める内容は理解度から決めたい。',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonths(3),
            'is_public' => false,
        ]);
    }
}
