<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlobalMapNavigationV478Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_deep_execution_map_always_exposes_home_and_user_facing_depth(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Global Navigationを整える',
            'description' => 'どこからでも全体へ戻れる',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '現在地を確認する',
            'description' => 'L3でも迷わない',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-global-navigation', false)
            ->assertSee('data-map-global-home', false)
            ->assertSee('aria-label="Canovia全体へ戻る"', false)
            ->assertSee('data-map-zoom-direction="out"', false)
            ->assertSee('現在地')
            ->assertSee('Global Navigationを整える')
            ->assertSee('全体')
            ->assertSee('領域')
            ->assertSee('Plan')
            ->assertSee('実行');
    }

    public function test_home_map_marks_global_home_as_current_instead_of_linking_to_itself(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-global-navigation', false)
            ->assertSee('canovia-map-global-home is-current', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_legacy_debug_depth_bar_is_visually_retired_without_removing_runtime_dom(): void
    {
        $blade = file_get_contents(resource_path('views/map/partials/topbar.blade.php'));
        $css = file_get_contents(resource_path('css/map/navigation.css'));

        $this->assertStringContainsString("map.partials.hierarchy-navigation", $blade);
        $this->assertStringContainsString(
            'body[data-route-name="map.index"] .canovia-map-fullscreen-hierarchy',
            $css,
        );
        $this->assertStringContainsString('display: none;', $css);
    }
}
