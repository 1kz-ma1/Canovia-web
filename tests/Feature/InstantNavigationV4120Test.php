<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserStateSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstantNavigationV4120Test extends TestCase
{
    use RefreshDatabase;

    public function test_core_surfaces_expose_the_same_dynamic_shell_contract(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->createPlan($user);

        foreach (['/', '/inbox', '/roadmap', '/timeline', '/calendar'] as $path) {
            $response = $this->actingAs($user)->get($path);
            $response->assertOk();
            $response->assertSee('data-canovia-page', false);
            $response->assertSee('data-canovia-companion-slot', false);
            $response->assertSee('data-canovia-nav-key="desktop-home"', false);
            $response->assertSee('data-canovia-nav-key="mobile-home"', false);
            $response->assertSee('data-mobile-section-label', false);
        }
    }

    public function test_home_prefetch_does_not_record_a_dashboard_visit_or_daily_state_snapshot(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
            ->get('/');

        $response->assertOk();

        $this->assertDatabaseMissing('behavior_events', [
            'event_type' => BehaviorEventType::DashboardViewed->value,
        ]);
        $this->assertDatabaseMissing('behavior_events', [
            'event_type' => BehaviorEventType::RecommendationShown->value,
        ]);
        $this->assertSame(0, UserStateSnapshot::query()->count());
    }

    public function test_normal_home_request_keeps_existing_observability_and_state_capture(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $this->assertTrue(
            BehaviorEvent::query()
                ->where('event_type', BehaviorEventType::DashboardViewed->value)
                ->exists()
        );
        $this->assertSame(1, UserStateSnapshot::query()->count());
    }

    public function test_instant_navigation_runtime_is_wired_into_the_main_bundle(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $runtime = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString("from './instant-navigation.mjs'", $app);
        $this->assertStringContainsString('mountCanoviaInstantNavigation();', $app);
        $this->assertStringContainsString("document.addEventListener('canovia:page-ready'", $app);
        $this->assertStringContainsString("'X-Canovia-Instant-Navigation': mode", $runtime);
        $this->assertStringContainsString("'/inbox'", $runtime);
        $this->assertStringContainsString("'/roadmap'", $runtime);
        $this->assertStringContainsString("'/timeline'", $runtime);
        $this->assertStringContainsString("'/calendar'", $runtime);
        $this->assertStringContainsString("windowRef.history.pushState", $runtime);
        $this->assertStringContainsString("if (!corePaths.has(initialUrl.pathname)) return null;", $runtime);
        $this->assertStringContainsString("removeAttribute('data-canovia-instant-initialized')", $runtime);
    }

    private function createPlan(User $user): Plan
    {
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Instant Navigation Plan',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::create([
            'plan_id' => $plan->id,
            'title' => 'Instant Task',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return $plan;
    }
}
