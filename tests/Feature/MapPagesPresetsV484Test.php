<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapPagesPresetsV484Test extends TestCase
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

    public function test_l0_renders_map_page_switcher_even_when_no_data_layers_are_available(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-page-control', false)
            ->assertSee('data-map-page-save-form', false)
            ->assertSee('data-map-page-id="builtin:overview"', false)
            ->assertSee('data-map-page-id="builtin:plan"', false)
            ->assertSee('data-map-page-id="builtin:execution"', false)
            ->assertSee('data-map-page-id="builtin:reflection"', false)
            ->assertSee('data-map-page-id="builtin:collaboration"', false)
            ->assertSee('現在のMapをページとして保存')
            ->assertSee('Plan / Taskはコピーしません。');
    }

    public function test_execution_map_composes_map_pages_and_data_layers_without_new_canonical_entities(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP学習ページを作る',
            'description' => '同じPlanを別の見方で開く',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '午前問題を解く',
            'description' => 'Page保存の対象Task',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $url = route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ]);

        $response = $this->actingAs($user)->get($url);

        $response
            ->assertOk()
            ->assertSee('data-map-page-control', false)
            ->assertSee('data-map-data-layer-control', false)
            ->assertSee('data-map-current-route=', false)
            ->assertSee('data-map-current-label=', false)
            ->assertSee('data-map-page-custom-list', false)
            ->assertSee('data-map-page-max-custom="8"', false);

        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('AP学習ページを作る', $plan->fresh()->title);
        $this->assertSame(25, $plan->tasks()->firstOrFail()->progress_percent);
    }

    public function test_map_page_runtime_is_local_presentation_state_and_does_not_post_canonical_mutations(): void
    {
        $runtime = file_get_contents(resource_path('js/map-pages.mjs'));

        $this->assertStringContainsString("canovia.map.pages.v1", $runtime);
        $this->assertStringContainsString("canovia.map.pages.active.v1", $runtime);
        $this->assertStringContainsString('normalizeMapPageRoute', $runtime);
        $this->assertStringContainsString('readMapDataLayerState', $runtime);
        $this->assertStringContainsString('persistMapDataLayerState', $runtime);
        $this->assertStringContainsString('requestGlobalHomeReset', $runtime);
        $this->assertStringNotContainsString("fetch(", $runtime);
        $this->assertStringNotContainsString("method: 'POST'", $runtime);
        $this->assertStringNotContainsString('/plans/', $runtime);
        $this->assertStringNotContainsString('/tasks/', $runtime);
    }

    public function test_every_visible_canovia_home_action_uses_hard_reset_semantics(): void
    {
        $navigation = file_get_contents(
            resource_path('views/map/partials/extensions/global-navigation.blade.php')
        );
        $topbar = file_get_contents(resource_path('views/map/partials/topbar.blade.php'));

        $this->assertGreaterThanOrEqual(2, substr_count($navigation, 'data-map-global-home'));
        $this->assertStringContainsString('>Canovia全体</a>', $topbar);
        $this->assertStringContainsString('data-map-global-home', $topbar);
    }
}
