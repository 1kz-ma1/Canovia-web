<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapSemanticInteractionV472Test extends TestCase
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

    public function test_container_nodes_use_semantic_body_entry_while_execution_task_stays_focus_first(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Semantic Interactionを整える',
            'description' => 'ContainerとLeafのクリック意味を分離する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Focusを維持するTask',
            'description' => 'Execution LeafはInspectorを使う',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $intentHtml = $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('1段深く見る →')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*data-map-node-id="intent:reflection"[^>]*data-map-node-entry-mode="semantic"[^>]*>/s',
            $intentHtml,
        );
        $this->assertMatchesRegularExpression(
            '/<div[^>]*data-map-node-id="intent:collaboration"[^>]*data-map-node-entry-mode="semantic"[^>]*>/s',
            $intentHtml,
        );

        $executionHtml = $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l3',
                'intent' => 'execution',
                'plan' => $plan->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div[^>]*data-map-node-id="task:'.preg_quote((string) $task->id, '/').'"[^>]*data-map-node-entry-mode="focus"[^>]*>/s',
            $executionHtml,
        );
    }

    public function test_gesture_click_guard_keeps_interactive_nodes_available_and_focus_switch_reuses_history_entry(): void
    {
        $source = file_get_contents(resource_path('js/living-map.mjs'));

        $this->assertStringContainsString('const interactiveSceneTarget = event.target.closest?.(', $source);
        $this->assertStringContainsString('&& !interactiveSceneTarget', $source);
        $this->assertStringContainsString('if (hadFocus && windowRef.history.state?.canoviaMapFocus)', $source);
        $this->assertStringContainsString('windowRef.history.replaceState({', $source);
    }

    public function test_context_surface_is_presented_as_leaf_inspector_not_semantic_navigation_gate(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('Context Inspector')
            ->assertSee('Leaf Nodeの詳細確認と補助操作を表示します');
    }
}
