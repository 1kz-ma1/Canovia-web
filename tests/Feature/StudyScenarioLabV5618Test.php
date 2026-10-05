<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyRecallItem;
use App\Models\StudyScenarioFixture;
use App\Models\StudyScopeCapture;
use App\Models\StudyScoreObservation;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\AdminAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyScenarioLabV5618Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.study_scenario_lab_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_lab_is_404_when_flag_is_disabled_even_for_admin(): void
    {
        $user = $this->user();
        config([
            'canovia.study_scenario_lab_enabled' => false,
        ]);

        $this->asAdmin($user)
            ->get(route('admin.study_scenarios.index'))
            ->assertNotFound();
    }

    public function test_lab_is_forbidden_for_non_admin_when_enabled(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('admin.study_scenarios.index'))
            ->assertForbidden();
    }

    public function test_lab_get_lists_presets_without_creating_data(): void
    {
        $user = $this->user();

        $this->asAdmin($user)
            ->get(route('admin.study_scenarios.index'))
            ->assertOk()
            ->assertSee('Study Scenario Lab')
            ->assertSee('data-study-scenario="ap-current"', false)
            ->assertSee('data-study-scenario="ap-knowledge-gap"', false)
            ->assertSee('data-study-scenario="toeic-known"', false)
            ->assertSee('data-study-scenario="toeic-missing"', false)
            ->assertSee('data-study-scenario="school-scope-missing"', false)
            ->assertSee('data-study-scenario="memorization-due"', false)
            ->assertSee('data-study-scenario="skill-practical"', false)
            ->assertSee('data-study-scenario="ambiguous-type"', false);

        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('study_scenario_fixtures', 0);
    }

    public function test_ap_current_scenario_creates_real_practice_history_and_opens_state_first_workspace(): void
    {
        $user = $this->user();

        $this->createScenario($user, 'ap-current')
            ->assertRedirect();

        $fixture = $this->fixture($user, 'ap-current');
        $plan = $fixture->plan()->firstOrFail();

        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame(
            3,
            StudyPracticeAttempt::query()
                ->where('plan_id', $plan->id)
                ->count(),
        );
        $this->assertSame(
            0,
            StudyScopeCapture::query()
                ->where('plan_id', $plan->id)
                ->count(),
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="certification_exam"',
                false,
            )
            ->assertSee('演習履歴から現在地を把握済み')
            ->assertSee('90%')
            ->assertDontSee(
                'data-study-workspace-missing-context="study_scope"',
                false,
            )
            ->assertSee(
                'data-study-method-key="question_practice"',
                false,
            );
    }

    public function test_ap_repeated_gap_scenario_renders_resource_study(): void
    {
        $user = $this->user();
        $this->createScenario($user, 'ap-knowledge-gap');

        $plan = $this->fixture(
            $user,
            'ap-knowledge-gap',
        )->plan()->firstOrFail();

        $this->assertSame(
            2,
            StudyPracticeAttempt::query()
                ->where('plan_id', $plan->id)
                ->count(),
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
                'data-study-method-rule="repeated_knowledge_gap"',
                false,
            )
            ->assertSee('DNS');
    }

    public function test_toeic_known_scenario_separates_external_score_from_practice_accuracy(): void
    {
        $user = $this->user();
        $this->createScenario($user, 'toeic-known');

        $plan = $this->fixture(
            $user,
            'toeic-known',
        )->plan()->firstOrFail();

        $observation = StudyScoreObservation::query()
            ->where('plan_id', $plan->id)
            ->firstOrFail();

        $this->assertSame(480.0, $observation->score_value);
        $this->assertCount(2, $observation->components);

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('480点')
            ->assertSee('600点')
            ->assertSee('あと120')
            ->assertSee('76%')
            ->assertSee('Canovia内・別尺度');
    }

    public function test_toeic_missing_scenario_keeps_external_score_prompt_despite_practice_history(): void
    {
        $user = $this->user();
        $this->createScenario($user, 'toeic-missing');

        $plan = $this->fixture(
            $user,
            'toeic-missing',
        )->plan()->firstOrFail();

        $this->assertSame(
            1,
            StudyPracticeAttempt::query()
                ->where('plan_id', $plan->id)
                ->count(),
        );
        $this->assertSame(
            0,
            StudyScoreObservation::query()
                ->where('plan_id', $plan->id)
                ->count(),
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('84%')
            ->assertSee(
                'data-study-workspace-missing-context="current_score"',
                false,
            )
            ->assertSee('現在スコアを記録');
    }

    public function test_school_test_scenario_has_previous_score_without_scope_and_requires_scope_organization(): void
    {
        $user = $this->user();
        $this->createScenario(
            $user,
            'school-scope-missing',
        );

        $plan = $this->fixture(
            $user,
            'school-scope-missing',
        )->plan()->firstOrFail();

        $this->assertSame(
            62.0,
            StudyScoreObservation::query()
                ->where('plan_id', $plan->id)
                ->value('score_value'),
        );
        $this->assertSame(
            0,
            StudyScopeCapture::query()
                ->where('plan_id', $plan->id)
                ->count(),
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
                'data-study-workspace-missing-context="study_scope"',
                false,
            );
    }

    public function test_memorization_scenario_creates_due_recall_items_and_recommends_recall(): void
    {
        $user = $this->user();
        $this->createScenario(
            $user,
            'memorization-due',
        );

        $plan = $this->fixture(
            $user,
            'memorization-due',
        )->plan()->firstOrFail();

        $items = StudyRecallItem::query()
            ->where('plan_id', $plan->id)
            ->get();

        $this->assertCount(6, $items);
        $this->assertSame(
            4,
            $items->filter(
                fn (StudyRecallItem $item) => $item->isDue(),
            )->count(),
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-method-key="recall"',
                false,
            );
    }

    public function test_skill_scenario_starts_without_evidence_and_recommends_practical_execution(): void
    {
        $user = $this->user();
        $this->createScenario(
            $user,
            'skill-practical',
        );

        $plan = $this->fixture(
            $user,
            'skill-practical',
        )->plan()->firstOrFail();

        $this->assertSame(
            0,
            TaskEvidence::query()
                ->where('plan_id', $plan->id)
                ->count(),
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type="skill_learning"',
                false,
            )
            ->assertSee(
                'data-study-method-key="practical_evidence"',
                false,
            )
            ->assertSee(
                'data-study-workspace-missing-context="practical_baseline"',
                false,
            );
    }

    public function test_ambiguous_scenario_renders_learning_type_confirmation(): void
    {
        $user = $this->user();
        $this->createScenario(
            $user,
            'ambiguous-type',
        );

        $plan = $this->fixture(
            $user,
            'ambiguous-type',
        )->plan()->firstOrFail();

        $this->assertNull(
            $plan->study_learning_type_override,
        );

        $this->actingAs($user)
            ->get(route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-study-learning-type-confirmation',
                false,
            )
            ->assertSee('この学習はどれに近い？');
    }

    public function test_recreate_replaces_only_the_previous_fixture_plan(): void
    {
        $user = $this->user();

        $realPlan = $this->realPlan($user);

        $this->createScenario($user, 'ap-current');
        $firstFixture = $this->fixture(
            $user,
            'ap-current',
        );
        $firstPlanId = $firstFixture->plan_id;

        $this->createScenario($user, 'ap-current');
        $secondFixture = $this->fixture(
            $user,
            'ap-current',
        );

        $this->assertNotSame(
            $firstPlanId,
            $secondFixture->plan_id,
        );
        $this->assertDatabaseMissing('plans', [
            'id' => $firstPlanId,
        ]);
        $this->assertDatabaseHas('plans', [
            'id' => $realPlan->id,
        ]);
        $this->assertSame(
            1,
            StudyScenarioFixture::query()
                ->where('user_id', $user->id)
                ->where('scenario_key', 'ap-current')
                ->count(),
        );
    }

    public function test_delete_all_removes_only_lab_fixture_plans(): void
    {
        $user = $this->user();

        $realPlan = $this->realPlan($user);

        $this->createScenario($user, 'ap-current');
        $this->createScenario($user, 'toeic-known');

        $this->asAdmin($user)
            ->delete(route(
                'admin.study_scenarios.destroy_all',
            ))
            ->assertRedirect(
                route('admin.study_scenarios.index'),
            );

        $this->assertDatabaseHas('plans', [
            'id' => $realPlan->id,
        ]);
        $this->assertDatabaseCount(
            'study_scenario_fixtures',
            0,
        );
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_unknown_scenario_key_is_404(): void
    {
        $user = $this->user();

        $this->asAdmin($user)
            ->post(route(
                'admin.study_scenarios.store',
                ['scenarioKey' => 'not-real'],
            ))
            ->assertNotFound();
    }

    private function user(): User
    {
        return User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
    }

    private function asAdmin(User $user): static
    {
        return $this
            ->actingAs($user)
            ->withSession([
                AdminAccessService::SESSION_KEY => true,
            ]);
    }

    private function createScenario(
        User $user,
        string $key,
    ) {
        return $this->asAdmin($user)
            ->post(route(
                'admin.study_scenarios.store',
                ['scenarioKey' => $key],
            ));
    }

    private function fixture(
        User $user,
        string $key,
    ): StudyScenarioFixture {
        return StudyScenarioFixture::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->where('scenario_key', $key)
            ->firstOrFail();
    }

    private function realPlan(User $user): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'creation_request_id' => (string) Str::uuid(),
            'public_slug' => (string) Str::uuid(),
            'title' => '本物の学習Plan',
            'description' => 'Scenario Labとは無関係',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }
}
