<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Intelligence\Presentation\PlanIntelligencePresentationService;
use App\Models\CareerCapture;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualitativeReadinessUxV552Test extends TestCase
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

    public function test_career_presentation_is_explicitly_qualitative_without_creating_a_score(): void
    {
        [$user, $plan] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
        );

        $presentation = app(
            PlanIntelligencePresentationService::class,
        )->forPlan($plan);

        $this->assertNotNull($presentation);
        $this->assertTrue($presentation->qualitativeReadiness);
        $this->assertNull($presentation->readiness->score);
        $this->assertSame('未観測', $presentation->readinessDisplay());
        $this->assertFalse($presentation->hasDistinctStateDisplay());

        CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'captured_at' => now(),
        ]);

        $observed = app(
            PlanIntelligencePresentationService::class,
        )->forPlan($plan);

        $this->assertNotNull($observed);
        $this->assertNull($observed->readiness->score);
        $this->assertSame('観測中', $observed->readinessDisplay());
        $this->assertSame('観測中', $observed->stateLabel);
    }

    public function test_numeric_domains_keep_readiness_and_current_state_distinct(): void
    {
        [, $study] = $this->plan(
            'AP対策',
            '資格学習',
        );

        $presentation = app(
            PlanIntelligencePresentationService::class,
        )->forPlan($study);

        $this->assertNotNull($presentation);
        $this->assertFalse($presentation->qualitativeReadiness);
        $this->assertTrue($presentation->hasDistinctStateDisplay());
        $this->assertSame('Exam Readiness', $presentation->readinessLabel);
    }

    public function test_home_career_action_shows_one_qualitative_readiness_status_without_duplicate_current_state(): void
    {
        [$user, $plan] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-action', false)
            ->assertSee('data-intelligence-domain="career"', false)
            ->assertSee(
                'data-intelligence-readiness-display="qualitative"',
                false,
            )
            ->assertDontSee('data-intelligence-current-state', false)
            ->assertSee('Process Readiness')
            ->assertSee('未観測')
            ->assertDontSee('>未判定<', false);

        CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'manual',
            'status' => 'pending',
            'captured_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(
                'data-intelligence-readiness-display="qualitative"',
                false,
            )
            ->assertSee('観測中')
            ->assertDontSee('data-intelligence-current-state', false)
            ->assertDontSee('>未判定<', false);
    }

    public function test_home_study_action_keeps_numeric_readiness_and_current_state_tile(): void
    {
        [$user] = $this->plan(
            'AP対策',
            '資格学習',
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-domain="study"', false)
            ->assertSee(
                'data-intelligence-readiness-display="numeric"',
                false,
            )
            ->assertSee('data-intelligence-current-state', false)
            ->assertSee('Exam Readiness');
    }

    public function test_overview_and_career_workspace_use_the_same_qualitative_value(): void
    {
        [$user, $plan] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
        );

        CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'captured_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-mode="career"', false)
            ->assertSee('Process Readiness')
            ->assertSee('観測中')
            ->assertDontSee('>未判定<', false);

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-career-workspace-readiness', false)
            ->assertSee('PROCESS READINESS')
            ->assertSee('観測中')
            ->assertDontSee('>未判定<', false);
    }

    public function test_shared_intelligence_summary_collapses_duplicate_state_for_qualitative_domain(): void
    {
        [, $plan] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
        );

        $presentation = app(
            PlanIntelligencePresentationService::class,
        )->forPlan($plan);

        $html = view('intelligence.partials.summary', [
            'intelligencePresentation' => $presentation,
            'intelligenceHistory' => [],
            'canExecuteIntelligence' => true,
        ])->render();

        $this->assertStringContainsString(
            'data-intelligence-readiness-display="qualitative"',
            $html,
        );
        $this->assertStringNotContainsString(
            'data-intelligence-current-state',
            $html,
        );
        $this->assertStringContainsString('未観測', $html);
        $this->assertStringNotContainsString('未判定', $html);
    }

    private function plan(
        string $title,
        string $category,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        UserProductGrant::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'product_key' => ProductKey::AllAccess,
                'source' => 'manual',
            ],
            [
                'starts_at' => now()->subMinute(),
                'metadata' => ['test' => true],
            ],
        );

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
            'deadline' => today()->addMonths(3),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        return [$user, $plan];
    }
}
