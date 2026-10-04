<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Intelligence\Presentation\PlanIntelligencePresentationService;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurrentActionHomeUxV553Test extends TestCase
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

    public function test_supported_domains_expose_canonical_workspace_handoff(): void
    {
        [$user, $study] = $this->plan('AP対策', '資格学習');
        [, $development] = $this->plan(
            'Canovia開発',
            '個人開発',
            $user,
        );
        [, $career] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
            $user,
        );

        $service = app(PlanIntelligencePresentationService::class);

        $studyPresentation = $service->forPlan($study);
        $developmentPresentation = $service->forPlan($development);
        $careerPresentation = $service->forPlan($career);

        $this->assertNotNull($studyPresentation);
        $this->assertNotNull($developmentPresentation);
        $this->assertNotNull($careerPresentation);

        $this->assertSame(
            route('workspace.study.index', ['plan_id' => $study->id]),
            $studyPresentation->workspaceUrl,
        );
        $this->assertSame(
            'Study Workspace',
            $studyPresentation->workspaceLabel,
        );
        $this->assertTrue($studyPresentation->hasWorkspaceHandoff());

        $this->assertSame(
            route('workspace.development.index', [
                'plan_id' => $development->id,
            ]),
            $developmentPresentation->workspaceUrl,
        );
        $this->assertSame(
            'Development Workspace',
            $developmentPresentation->workspaceLabel,
        );
        $this->assertTrue(
            $developmentPresentation->hasWorkspaceHandoff(),
        );

        $this->assertSame(
            route('workspace.career.index', [
                'plan_id' => $career->id,
            ]),
            $careerPresentation->workspaceUrl,
        );
        $this->assertSame(
            'Career Workspace',
            $careerPresentation->workspaceLabel,
        );
        $this->assertTrue($careerPresentation->hasWorkspaceHandoff());
    }

    public function test_career_home_is_action_first_and_hands_detail_to_career_workspace(): void
    {
        [$user, $plan] = $this->plan(
            'エンジニア就活',
            '就活・キャリア',
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-domain="career"', false)
            ->assertSee('data-current-action-intent', false)
            ->assertSee('data-current-action-ctas', false)
            ->assertSee('data-current-action-primary', false)
            ->assertSee('data-current-action-context', false)
            ->assertSee('data-current-action-workspace', false)
            ->assertSee('求人・応募情報を1つ残す')
            ->assertSee('Career情報を追加')
            ->assertSee('Career Workspace')
            ->assertSee(
                route('workspace.career.index', [
                    'plan_id' => $plan->id,
                ]),
                false,
            )
            ->assertSee('ほかの候補を見る')
            ->assertDontSee('BIGGEST GAP');
    }

    public function test_study_home_keeps_explicit_action_and_routes_secondary_cta_to_study_workspace(): void
    {
        [$user, $plan] = $this->plan(
            'AP対策',
            '資格学習',
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-domain="study"', false)
            ->assertSee('data-current-action-primary', false)
            ->assertSee('data-current-action-workspace', false)
            ->assertSee(
                route('plans.study_action.execute', $plan),
                false,
            )
            ->assertSee(
                route('workspace.study.index', [
                    'plan_id' => $plan->id,
                ]),
                false,
            )
            ->assertSee('Study Workspace')
            ->assertSee('ほかの候補を見る')
            ->assertDontSee('BIGGEST GAP');
    }

    public function test_development_home_routes_secondary_cta_to_development_workspace(): void
    {
        [$user, $plan] = $this->plan(
            'Canovia開発',
            '個人開発',
        );
        $this->task($plan, 'V55.3を実装');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-domain="development"', false)
            ->assertSee('data-current-action-workspace', false)
            ->assertSee(
                route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ]),
                false,
            )
            ->assertSee('Development Workspace')
            ->assertSee('ほかの候補を見る');
    }

    public function test_generic_task_home_keeps_execution_wording_without_workspace_handoff(): void
    {
        [$user, $plan] = $this->plan(
            '生活改善',
            'その他',
        );
        $this->task($plan, '部屋を片付ける');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-intelligence-action', false)
            ->assertDontSee('data-current-action-workspace', false)
            ->assertSee('実行を開く')
            ->assertDontSee('ほかの候補を見る');
    }

    private function plan(
        string $title,
        string $category,
        ?User $user = null,
    ): array {
        $user ??= User::factory()->create([
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

    private function task(
        Plan $plan,
        string $title,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
