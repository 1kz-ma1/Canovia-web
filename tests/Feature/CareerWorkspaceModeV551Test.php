<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\BehaviorEvent;
use App\Models\CareerApplication;
use App\Models\CareerCapture;
use App\Models\CareerSelectionEvent;
use App\Models\IntelligenceDecisionTrace;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CareerWorkspaceModeV551Test extends TestCase
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

    public function test_career_workspace_is_canonical_strong_mode_and_starts_with_plan_onboarding(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'study',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.career.index'))
            ->assertOk()
            ->assertSee('data-career-workspace', false)
            ->assertSee('data-career-workspace-no-plan', false)
            ->assertSee('data-career-first-use-choices', false)
            ->assertSee('data-career-first-use-known', false)
            ->assertSee('data-career-first-use-canovia', false)
            ->assertSee('data-career-first-use-match-plus', false)
            ->assertSee(route('career.explore.show'), false)
            ->assertSee('https://job.mynavi.jp/conts/2028/cs/matchplus_consent_2/', false)
            ->assertDontSee('data-workspace-mode-onboarding="career"', false)
            ->assertSee(
                route('plans.create.manual', [
                    'workspace_mode' => 'career',
                ]),
                false,
            )
            ->assertSee(
                'data-workspace-mode="career"',
                false,
            )
            ->assertSee(
                'data-workspace-mode-source="route_hint"',
                false,
            );

        $this->assertSame(
            'study',
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_career_plan_without_signal_requests_one_real_fact_then_onboarding_disappears(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-career-workspace-signal-first', false)
            ->assertSee(
                'data-workspace-mode-onboarding-step="capture_career_signal"',
                false,
            )
            ->assertSee('現実のCareer情報を1つ追加する')
            ->assertSee(route('plans.career.index', $plan), false)
            ->assertDontSee('data-career-workspace-readiness', false);

        CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'source_url' => 'https://example.com/jobs/1',
            'raw_text' => 'Private career note',
            'captured_at' => now(),
        ]);

        $before = IntelligenceDecisionTrace::count();

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertDontSee(
                'data-workspace-mode-onboarding="career"',
                false,
            )
            ->assertSee('data-career-workspace-readiness', false)
            ->assertSee('PROCESS READINESS')
            ->assertSee('観測中')
            ->assertSee('未整理Captureを応募先へつなぐ')
            ->assertDontSee('Private career note');

        $this->assertSame(
            $before,
            IntelligenceDecisionTrace::count(),
        );
    }

    public function test_career_plan_creation_prefills_category_and_returns_to_career_workspace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.create.manual', [
                'workspace_mode' => 'career',
            ]))
            ->assertOk()
            ->assertSee(
                'data-plan-create-workspace-mode="career"',
                false,
            )
            ->assertSee(
                'name="workspace_mode" value="career"',
                false,
            )
            ->assertSee('value="就活・キャリア" selected', false)
            ->assertSee(
                'Career Workspaceへ戻って求人・応募・選考の事実を1件追加',
            );

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => 'エンジニア就活',
                'category' => '就活・キャリア',
                'priority_mode' => 'auto',
                'workspace_mode' => 'career',
                'create_request_id' => (string) Str::uuid(),
            ]);

        $plan = Plan::query()
            ->where('title', 'エンジニア就活')
            ->firstOrFail();

        $response
            ->assertRedirect(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertSessionHas(
                'status',
                'Career Planを作成しました。次は現実のCareer情報を1件追加します。',
            );
    }

    public function test_changed_category_does_not_force_false_career_workspace_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('plans.store'), [
                'title' => '開発転向',
                'category' => '個人開発',
                'priority_mode' => 'auto',
                'workspace_mode' => 'career',
                'create_request_id' => (string) Str::uuid(),
            ]);

        $plan = Plan::query()->where('title', '開発転向')->firstOrFail();

        $response->assertRedirect(
            route('plans.show', $plan),
        );
    }

    public function test_interview_review_action_routes_to_existing_operational_review_surface(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $application = CareerApplication::query()->create([
            'plan_id' => $plan->id,
            'company_name' => 'Private Company',
            'role_title' => 'Private Role',
            'stage' => 'interview',
            'status' => 'waiting',
        ]);
        $event = CareerSelectionEvent::query()->create([
            'career_application_id' => $application->id,
            'type' => 'interview',
            'stage' => 'interview',
            'status' => 'result_waiting',
            'scheduled_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('面接の振り返りを残す')
            ->assertSee(
                route(
                    'plans.career.interview_reviews.show',
                    [$plan, $event],
                ),
                false,
            )
            ->assertDontSee('Private Company')
            ->assertDontSee('Private Role');
    }

    public function test_existing_career_operational_routes_resolve_to_career_mode(): void
    {
        $user = User::factory()->create([
            'workspace_mode_preference' => 'development',
        ]);
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->get(route('plans.career.index', $plan))
            ->assertOk()
            ->assertSee(
                'data-workspace-mode="career"',
                false,
            )
            ->assertSee(
                'data-workspace-mode-source="route_hint"',
                false,
            );

        $this->assertSame(
            'development',
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_career_mode_selection_persists_and_telemetry_accepts_safe_career_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workspace_modes.select', [
                'workspaceMode' => WorkspaceMode::Career->value,
            ]))
            ->assertRedirect(route('workspace.career.index'));

        $this->assertSame(
            'career',
            $user->fresh()->workspace_mode_preference,
        );

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => 'workspace_mode_selected',
                'metadata' => [
                    'selected_mode' => 'career',
                    'from_mode' => 'overview',
                    'from_source' => 'default',
                    'surface' => 'web',
                    'device' => 'desktop',
                    'platform' => 'other',
                    'company_name' => 'must not persist',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()->latest('id')->firstOrFail();

        $this->assertSame('career', data_get(
            $event->metadata,
            'selected_mode',
        ));
        $this->assertArrayNotHasKey(
            'company_name',
            $event->metadata ?? [],
        );
    }

    public function test_overview_includes_career_summary_and_career_first_use_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee(
                'data-overview-first-use-workspace="career"',
                false,
            )
            ->assertDontSee('data-overview-mode="career"', false);

        $plan = $this->plan($user);

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertDontSee('data-overview-first-use-workspaces', false)
            ->assertSee($plan->title)
            ->assertSee('data-overview-mode="career"', false)
            ->assertSee('Careerの活動状況を確認中')
            ->assertDontSee('Career判断に使える現実情報がまだありません。');

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
            ->assertSee('Process Readiness')
            ->assertSee('観測中')
            ->assertSee('未整理Captureがある');
    }

    public function test_career_state_change_uses_safe_generic_feedback(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $capture = CareerCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'raw_text' => 'Secret Company and answer',
            'captured_at' => now(),
        ]);

        $service = app(CareerAdaptiveActionService::class);
        $service->refresh($plan);

        $application = CareerApplication::query()->create([
            'plan_id' => $plan->id,
            'company_name' => 'Secret Company',
            'role_title' => 'Secret Role',
            'stage' => 'preparing',
            'status' => 'active',
        ]);
        $capture->update([
            'career_application_id' => $application->id,
            'status' => 'linked',
        ]);

        $service->refresh($plan);

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee('data-intelligence-state-change', false)
            ->assertSee('STATE CHANGE')
            ->assertSee('Current Actionを更新')
            ->assertDontSee('Secret Company')
            ->assertDontSee('Secret Role');
    }

    public function test_explicit_non_career_plan_selection_is_rejected(): void
    {
        $user = User::factory()->create();
        $career = $this->plan($user);
        $study = $this->plan(
            $user,
            'AP対策',
            '資格学習',
        );

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $study->id,
            ]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('workspace.career.index', [
                'plan_id' => $career->id,
            ]))
            ->assertOk();
    }

    private function plan(
        User $user,
        string $title = 'エンジニア就活',
        string $category = '就活・キャリア',
    ): Plan {
        return Plan::query()->create([
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
    }
}
