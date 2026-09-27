<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserStateSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedCoreContextV4122Test extends TestCase
{
    use RefreshDatabase;

    public function test_core_bundle_requires_internal_prefetch_header(): void
    {
        $this->withoutVite();

        $this->get('/instant/core-bundle?surfaces=roadmap,timeline,calendar')
            ->assertNotFound();
    }

    public function test_core_bundle_returns_multiple_fragment_payloads_from_one_request(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
            ->get('/instant/core-bundle?surfaces=roadmap,timeline,calendar');

        $response->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonStructure([
                'fragments' => [
                    '/roadmap',
                    '/timeline',
                    '/calendar',
                ],
            ]);

        foreach (['/roadmap', '/timeline', '/calendar'] as $path) {
            $html = (string) $response->json('fragments.'.$path);
            $this->assertStringContainsString('id="canovia-instant-meta"', $html);
            $this->assertStringContainsString('data-canovia-page', $html);
            $this->assertStringNotContainsString('desktop-app-header', $html);
        }
    }


    public function test_home_inside_bundle_remains_a_side_effect_free_prefetch(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
            ->get('/instant/core-bundle?surfaces=home');

        $response->assertOk()
            ->assertJsonStructure(['fragments' => ['/']]);

        $this->assertFalse(
            BehaviorEvent::query()
                ->where('event_type', BehaviorEventType::DashboardViewed->value)
                ->exists()
        );
        $this->assertFalse(
            BehaviorEvent::query()
                ->where('event_type', BehaviorEventType::RecommendationShown->value)
                ->exists()
        );
        $this->assertSame(0, UserStateSnapshot::query()->count());
    }

    public function test_bundle_reuses_plan_and_work_log_queries_across_planning_surfaces(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $separate = ['plans' => 0, 'work_logs' => 0];

        foreach (['/roadmap', '/timeline', '/calendar'] as $path) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($user)
                ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
                ->get($path)
                ->assertOk();

            $groups = $this->queryGroups(DB::getQueryLog());
            $separate['plans'] += $groups['plans'] ?? 0;
            $separate['work_logs'] += $groups['work_logs'] ?? 0;
        }

        DB::flushQueryLog();

        $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
            ->get('/instant/core-bundle?surfaces=roadmap,timeline,calendar')
            ->assertOk();

        $bundle = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(
            $bundle['plans'] ?? 0,
            $separate['plans'],
            'Shared Core Context should avoid reloading owned plans for every surface.',
        );
        $this->assertGreaterThan(
            $bundle['work_logs'] ?? 0,
            $separate['work_logs'],
            'Shared Core Context should reuse one loaded WorkLog relation across surfaces.',
        );
        $this->assertSame(1, $bundle['work_logs'] ?? 0);
    }

    public function test_existing_direct_fragment_routes_remain_available(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        foreach (['/roadmap', '/timeline', '/calendar'] as $path) {
            $this->actingAs($user)
                ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
                ->get($path)
                ->assertOk()
                ->assertSee('id="canovia-instant-meta"', false);
        }
    }

    public function test_instant_navigation_runtime_batches_plan_surfaces_but_keeps_inbox_independent(): void
    {
        $source = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString('CORE_BUNDLE_SURFACES', $source);
        $this->assertStringContainsString("new URL('/instant/core-bundle'", $source);
        $this->assertStringContainsString('bundleInflight', $source);
        $this->assertStringContainsString('prefetchBundle', $source);

        preg_match('/CORE_BUNDLE_SURFACES = new Map\(\[(.*?)\]\);/s', $source, $matches);
        $bundleBlock = $matches[1] ?? '';

        $this->assertStringContainsString("['/', 'home']", $bundleBlock);
        $this->assertStringContainsString("['/roadmap', 'roadmap']", $bundleBlock);
        $this->assertStringContainsString("['/timeline', 'timeline']", $bundleBlock);
        $this->assertStringContainsString("['/calendar', 'calendar']", $bundleBlock);
        $this->assertStringNotContainsString('/inbox', $bundleBlock);
    }

    public function test_performance_observability_includes_core_bundle_path(): void
    {
        $this->assertContains('/instant/core-bundle', config('performance.paths'));
    }

    private function createPlan(User $user): Plan
    {
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Shared Core Plan',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::create([
            'plan_id' => $plan->id,
            'title' => 'Shared Core Task',
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

    /** @param array<int,array{query:string}> $queries */
    private function queryGroups(array $queries): array
    {
        $groups = [];

        foreach ($queries as $query) {
            preg_match('/(?:from|into|update) ["\`]?([a-z_]+)/i', $query['query'], $matches);
            if (! isset($matches[1])) {
                continue;
            }

            $table = $matches[1];
            $groups[$table] = ($groups[$table] ?? 0) + 1;
        }

        return $groups;
    }
}
