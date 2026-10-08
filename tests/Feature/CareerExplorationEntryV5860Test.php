<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\CareerExplorationAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareerExplorationEntryV5860Test extends TestCase
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

    public function test_new_career_user_has_three_real_start_paths_without_a_forced_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.career.index'))
            ->assertOk()
            ->assertSee('data-career-first-use-choices', false)
            ->assertSee('data-career-first-use-known', false)
            ->assertSee('data-career-first-use-canovia', false)
            ->assertSee('data-career-first-use-match-plus', false)
            ->assertSee(route('plans.create.manual', ['workspace_mode' => 'career']), false)
            ->assertSee(route('career.explore.show'), false)
            ->assertSee('https://job.mynavi.jp/conts/2028/cs/matchplus_consent_2/', false)
            ->assertDontSee('data-workspace-mode-onboarding="career"', false);

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_canovia_exploration_is_optional_and_hands_off_to_editable_career_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('career.explore.show'))
            ->assertOk()
            ->assertSee('data-career-exploration-form', false)
            ->assertSee('どんな活動に興味がありますか？')
            ->assertDontSee('data-career-exploration-result', false);

        $this->actingAs($user)
            ->post(route('career.explore.store'), ['answers' => [
                'interest_activity' => 'analyze',
                'work_value' => 'learning',
                'work_style' => 'solo',
                'location_preference' => 'unsure',
            ]])
            ->assertRedirect(route('career.explore.show'));

        $this->actingAs($user)
            ->get(route('career.explore.show'))
            ->assertOk()
            ->assertSee('data-career-exploration-result', false)
            ->assertSee('調査・分析')
            ->assertSee('仕事内容')
            ->assertSee('適性スコアではありません')
            ->assertSee('data-career-exploration-create-plan', false);

        $this->assertSame(0, Plan::query()->count());

        $this->actingAs($user)
            ->post(route('career.explore.plan'))
            ->assertRedirect(route('plans.create.manual', ['workspace_mode' => 'career']));

        $this->actingAs($user)
            ->get(route('plans.create.manual', ['workspace_mode' => 'career']))
            ->assertOk()
            ->assertSee('自分に合う仕事を探し、就職活動を進める')
            ->assertSee('情報を集め、比較・分析する')
            ->assertSee('value="就活・キャリア" selected', false);

        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('user_personalization_contexts', 0);
    }

    public function test_unconfirmed_external_indicators_never_suppress_canovia_questions(): void
    {
        $service = app(CareerExplorationAssessmentService::class);
        $external = [
            'interest_activity' => ['value' => 'support', 'confirmed' => true],
            'work_value' => ['value' => 'stability', 'confirmed' => false],
            'work_style' => ['value' => 'NOT_A_CANOVIA_CHOICE', 'confirmed' => true],
            'unknown_field' => ['value' => 'any', 'confirmed' => true],
        ];

        $this->assertSame(['interest_activity' => 'support'], $service->confirmedCoverage($external));
        $this->assertSame(
            ['work_value', 'work_style', 'location_preference'],
            array_keys($service->remainingQuestions($external)),
        );

        $user = User::factory()->create();
        $this->actingAs($user)
            ->withSession(['canovia.career.import.confirmed.v1' => $external])
            ->get(route('career.explore.show'))
            ->assertOk()
            ->assertSee('data-career-exploration-coverage', false)
            ->assertDontSee('どんな活動に興味がありますか？')
            ->assertSee('仕事を選ぶうえで、まず大切にしたいことは？');

        $this->actingAs($user)
            ->post(route('career.explore.store'), ['answers' => [
                'work_value' => 'learning',
                'work_style' => 'team',
                'location_preference' => 'local',
            ]])
            ->assertRedirect(route('career.explore.show'))
            ->assertSessionHas(CareerExplorationAssessmentService::SESSION_KEY, function ($value) {
                return ($value['answers']['interest_activity'] ?? null) === 'support'
                    && ($value['answers']['work_value'] ?? null) === 'learning';
            });
    }

    public function test_incomplete_or_invalid_diagnostic_answers_are_not_treated_as_results(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('career.explore.store'), ['answers' => [
                'interest_activity' => 'analyze',
                'work_value' => 'not-an-answer',
            ]])
            ->assertSessionHasErrors(['answers.work_value', 'answers.work_style', 'answers.location_preference']);

        $this->actingAs($user)
            ->get(route('career.explore.show'))
            ->assertDontSee('data-career-exploration-result', false);

        $this->actingAs($user)
            ->post(route('career.explore.plan'))
            ->assertNotFound();
    }
}
