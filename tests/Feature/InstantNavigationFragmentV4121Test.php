<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstantNavigationFragmentV4121Test extends TestCase
{
    use RefreshDatabase;

    public function test_core_pages_use_minimal_fragment_layout_for_instant_navigation(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        foreach (['/', '/map', '/inbox', '/roadmap', '/timeline', '/calendar'] as $path) {
            $response = $this->actingAs($user)
                ->withHeader('X-Canovia-Instant-Navigation', 'prefetch')
                ->get($path);

            $response->assertOk();
            $response->assertSee('id="canovia-instant-meta"', false);
            $response->assertSee('data-canovia-page', false);
            $response->assertSee('data-canovia-companion-slot', false);

            $response->assertDontSee('desktop-app-header', false);
            $response->assertDontSee('data-ui-settings-dialog', false);
            $response->assertDontSee('data-release-notes-dialog', false);
            $response->assertDontSee('canovia-guide-catalog', false);
        }
    }

    public function test_normal_core_request_still_renders_full_application_shell(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)->get('/timeline');

        $response->assertOk();
        $response->assertSee('desktop-app-header', false);
        $response->assertSee('data-ui-settings-dialog', false);
        $response->assertSee('data-release-notes-dialog', false);
        $response->assertDontSee('id="canovia-instant-meta"', false);
    }

    public function test_invalid_instant_navigation_mode_does_not_select_fragment_layout(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'invalid')
            ->get('/timeline');

        $response->assertOk();
        $response->assertSee('desktop-app-header', false);
        $response->assertDontSee('id="canovia-instant-meta"', false);
    }

    public function test_fragment_skips_shell_only_database_queries(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->get('/timeline')->assertOk();

        $fullGroups = $this->queryGroups(DB::getQueryLog());

        DB::flushQueryLog();

        $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get('/timeline')
            ->assertOk();

        $fragmentGroups = $this->queryGroups(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(1, $fullGroups['release_notes'] ?? 0);
        $this->assertSame(0, $fragmentGroups['release_notes'] ?? 0);

        $this->assertGreaterThanOrEqual(1, $fullGroups['user_product_grants'] ?? 0);
        $this->assertSame(0, $fragmentGroups['user_product_grants'] ?? 0);

        $this->assertLessThan(
            array_sum($fullGroups),
            array_sum($fragmentGroups),
            'Fragment request should execute fewer SQL statements than the full shell request.',
        );
    }

    public function test_fragment_metadata_contains_shell_state_without_rendering_shell_markup(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();
        $this->createPlan($user);

        $response = $this->actingAs($user)
            ->withHeader('X-Canovia-Instant-Navigation', 'navigate')
            ->get('/calendar');

        $response->assertOk();
        $response->assertSee('"mobileSection":"カレンダー"', false);
        $response->assertSee('"desktop-home"', false);
        $response->assertSee('"mobile-home"', false);
        $response->assertSee('mobile-tabbar-link is-active', false);
        $response->assertDontSee('mobile-app-header', false);
    }

    public function test_instant_runtime_parses_fragment_metadata_before_dom_shell_fallback(): void
    {
        $source = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString("getElementById('canovia-instant-meta')", $source);
        $this->assertStringContainsString('fragmentMeta?.nav', $source);
        $this->assertStringContainsString('fragmentMeta?.mobileSection', $source);
        $this->assertStringContainsString('fragmentMeta?.feedbackContext', $source);
    }

    private function createPlan(User $user): Plan
    {
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Fragment Plan',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::create([
            'plan_id' => $plan->id,
            'title' => 'Fragment Task',
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
