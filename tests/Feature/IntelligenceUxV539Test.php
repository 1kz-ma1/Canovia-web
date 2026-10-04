<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Intelligence\Presentation\PlanIntelligencePresentationService;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\IntelligenceHomeActionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntelligenceUxV539Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow('2026-10-04 12:00:00');

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_study_and_development_share_one_presentation_contract(): void
    {
        [$user, $study] = $this->plan('定期テスト対策', '定期テスト学習');
        [, $development] = $this->plan(
            'Canovia開発',
            '個人開発',
            $user,
        );

        $presentations = app(PlanIntelligencePresentationService::class);

        $studyPresentation = $presentations->forPlan($study);
        $developmentPresentation = $presentations->forPlan($development);

        $this->assertNotNull($studyPresentation);
        $this->assertNotNull($developmentPresentation);

        $this->assertSame('study', $studyPresentation->domain->value);
        $this->assertSame('development', $developmentPresentation->domain->value);

        $this->assertSame('試験に向けた現在地', $studyPresentation->headline);
        $this->assertSame(
            'Releaseに向けた現在地',
            $developmentPresentation->headline,
        );

        $this->assertSame(
            '試験範囲が未確定',
            $studyPresentation->gapLabel,
        );
        $this->assertSame(
            'Release Evidenceがまだない',
            $developmentPresentation->gapLabel,
        );

        $this->assertSame('POST', $studyPresentation->actionMethod);
        $this->assertSame('GET', $developmentPresentation->actionMethod);
        $this->assertCount(4, $studyPresentation->metrics);
        $this->assertCount(4, $developmentPresentation->metrics);
    }

    public function test_development_can_be_the_primary_home_intelligence_action(): void
    {
        [$user, $plan] = $this->plan('Canovia開発', '個人開発');
        $task = $this->task($plan, 'V53.9 Intelligence UX');
        $plan->load('tasks');

        $guidance = collect([[
            'plan' => $plan,
            'task' => $task,
            'adaptive' => null,
            'recommended_tool' => null,
            'priority_evaluation' => [
                'priority' => 1,
                'mode' => 'auto',
            ],
        ]]);

        $presentation = app(IntelligenceHomeActionService::class)->primary(
            collect([$plan]),
            $guidance,
        );

        $this->assertNotNull($presentation);
        $this->assertSame('development', $presentation->domain->value);
        $this->assertSame($plan->id, $presentation->plan->id);
        $this->assertSame(
            'development_connect_evidence',
            $presentation->action->kind,
        );
        $this->assertStringContainsString(
            '#github-workflow-board',
            $presentation->actionUrl,
        );
    }

    public function test_task_completion_does_not_hide_study_intelligence(): void
    {
        [, $plan] = $this->plan('定期テスト対策', '定期テスト学習');
        $task = $this->task(
            $plan,
            '旧計画上は完了',
            status: 'done',
            progress: 100,
        );
        $plan->load('tasks');

        $guidance = collect([[
            'plan' => $plan,
            'task' => $task,
            'adaptive' => null,
            'recommended_tool' => null,
            'priority_evaluation' => [
                'priority' => 1,
                'mode' => 'auto',
            ],
        ]]);

        $presentation = app(IntelligenceHomeActionService::class)->primary(
            collect([$plan]),
            $guidance,
        );

        $this->assertNotNull($presentation);
        $this->assertSame('study', $presentation->domain->value);
        $this->assertSame(
            'confirmed_scope_missing',
            $presentation->decision->reasonCode,
        );
        $this->assertSame(
            '試験範囲が未確定',
            $presentation->gapLabel,
        );
    }

    public function test_study_scope_uses_shared_intelligence_summary_without_creating_task(): void
    {
        [$user, $plan] = $this->plan('定期テスト対策', '定期テスト学習');

        app(StudyAdaptiveActionService::class)->refresh($plan, now());

        $this->actingAs($user)
            ->get(route('plans.study_scope.index', $plan))
            ->assertOk()
            ->assertSee('data-intelligence-summary', false)
            ->assertSee('data-intelligence-domain="study"', false)
            ->assertSee('STUDY INTELLIGENCE')
            ->assertSee('試験に向けた現在地')
            ->assertSee('試験範囲が未確定')
            ->assertSee('なぜ今これ？・判断履歴')
            ->assertSee('試験範囲を確認');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_development_workflow_uses_shared_summary_and_keeps_quality_gates(): void
    {
        [$user, $plan] = $this->plan('Canovia開発', '個人開発');

        $this->actingAs($user)
            ->get(route('github_workflow.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-intelligence-summary', false)
            ->assertSee('data-intelligence-domain="development"', false)
            ->assertSee('DEVELOPMENT INTELLIGENCE')
            ->assertSee('Releaseに向けた現在地')
            ->assertSee('Release Evidenceがまだない')
            ->assertSee('RELEASE QUALITY GATES')
            ->assertSee('実機・本番確認')
            ->assertSee('仕様同期')
            ->assertSee('id="github-workflow-board"', false);
    }

    public function test_home_page_renders_development_intelligence_instead_of_task_first_card(): void
    {
        [$user, $plan] = $this->plan('Canovia開発', '個人開発');
        $this->task($plan, 'V53.9 Intelligence UX');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-intelligence-action', false)
            ->assertSee('data-intelligence-domain="development"', false)
            ->assertSee('Release Evidenceがまだない')
            ->assertSee('Release Readiness')
            ->assertSee('data-current-action-context', false)
            ->assertSee('Development Workspace')
            ->assertDontSee('BIGGEST GAP');
    }

    private function plan(
        string $title,
        string $category,
        ?User $user = null,
    ): array {
        $user ??= User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->grantAllAccess($user);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addWeeks(2),
            'is_public' => false,
        ]);

        return [$user, $plan];
    }

    private function task(
        Plan $plan,
        string $title,
        string $status = 'todo',
        int $progress = 0,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $plan->tasks()->count() + 1,
        ]);
    }

    private function grantAllAccess(User $user): void
    {
        UserProductGrant::query()
            ->firstOrCreate(
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
    }
}
