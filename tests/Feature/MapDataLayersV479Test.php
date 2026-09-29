<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapDataLayersV479Test extends TestCase
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

    public function test_execution_map_projects_explicit_data_layers_without_mutating_canonical_state(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Data Layerを実装する',
            'description' => 'Mapへ既存データだけを重ねる',
            'category' => '個人開発',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(12),
            'is_public' => false,
        ]);

        $current = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Overlayを作る',
            'description' => '表示専用',
            'estimated_minutes' => 90,
            'remaining_minutes' => 50,
            'progress_percent' => 40,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $next = Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $current->id,
            'title' => 'Layerを確認する',
            'description' => '明示Dependencyだけを表示',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 3,
            'activation_cost' => 1,
            'sort_order' => 2,
        ]);

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $current->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native,
            'type' => 'focus_session_completed',
            'external_key' => 'v479-evidence',
            'confidence' => 0.9,
            'occurred_at' => now(),
            'metadata' => ['actual_minutes' => 20],
        ]);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-map-data-layer-control', false)
            ->assertSee('data-map-layer-toggle', false)
            ->assertSee('data-map-layer-badge="progress"', false)
            ->assertSee('data-map-layer-badge="status"', false)
            ->assertSee('data-map-layer-badge="evidence"', false)
            ->assertSee('data-map-layer-badge="dependency"', false)
            ->assertSee('data-map-layer-badge="priority"', false)
            ->assertSee('表示情報')
            ->assertSee('おすすめ')
            ->assertDontSee('data-map-layer-progress="1"', false);

        $graph = $response->viewData('graph');
        $this->assertSame(1, data_get($graph, 'data_layers.schema_version'));
        $this->assertSame([], data_get($graph, 'data_layers.default_enabled'));

        foreach (['progress', 'deadline', 'status', 'evidence', 'dependency', 'priority'] as $layer) {
            $this->assertContains($layer, data_get($graph, 'data_layers.available', []));
        }

        $currentNode = collect($graph['nodes'])->firstWhere('id', 'task:'.$current->id);
        $nextNode = collect($graph['nodes'])->firstWhere('id', 'task:'.$next->id);
        $planNode = collect($graph['nodes'])->firstWhere('id', 'plan:'.$plan->id);

        $this->assertSame(40, data_get($currentNode, 'overlay.progress.percent'));
        $this->assertSame('doing', data_get($currentNode, 'overlay.status.value'));
        $this->assertSame('進行中', data_get($currentNode, 'overlay.status.label'));
        $this->assertSame(1, data_get($currentNode, 'overlay.evidence.count'));
        $this->assertSame('P1', data_get($currentNode, 'overlay.priority.label'));

        $this->assertSame(1, data_get($nextNode, 'overlay.dependency.count'));
        $this->assertSame([$current->id], data_get($nextNode, 'overlay.dependency.task_ids'));
        $this->assertSame('P3', data_get($nextNode, 'overlay.priority.label'));

        $this->assertSame($plan->deadline->format('Y-m-d'), data_get($planNode, 'overlay.deadline.date'));
        $this->assertSame($plan->deadline->format('m/d'), data_get($planNode, 'overlay.deadline.label'));
        $this->assertSame('P2', data_get($planNode, 'overlay.priority.label'));

        foreach ([$currentNode, $nextNode, $planNode] as $node) {
            $this->assertArrayNotHasKey('ready', (array) data_get($node, 'overlay', []));
            $this->assertArrayNotHasKey('blocked', (array) data_get($node, 'overlay', []));
            $this->assertArrayNotHasKey('overdue', (array) data_get($node, 'overlay', []));
        }

        $this->assertSame(40, $current->fresh()->progress_percent);
        $this->assertSame('doing', $current->fresh()->status);
        $this->assertSame(0, $next->fresh()->progress_percent);
        $this->assertSame('todo', $next->fresh()->status);
        $this->assertSame(2, $plan->fresh()->priority);

        $beforeProjectionKey = (string) ($graph['projection_key'] ?? '');
        $current->update(['progress_percent' => 55]);

        $afterGraph = $this->actingAs($user)
            ->get(route('map.index', [
                'level' => 'l3',
                'intent' => 'execution',
                'plan' => $plan->id,
            ]))
            ->viewData('graph');

        $this->assertNotSame($beforeProjectionKey, (string) ($afterGraph['projection_key'] ?? ''));
        $afterCurrentNode = collect($afterGraph['nodes'])->firstWhere('id', 'task:'.$current->id);
        $this->assertSame(55, data_get($afterCurrentNode, 'overlay.progress.percent'));
    }

    public function test_primary_task_stays_role_only_on_canvas_while_data_layers_exist(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'おすすめ表示を守る',
            'description' => 'Data LayerとNode本文を分離',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '具体Task名は詳細で見る',
            'description' => 'Canvasはおすすめだけ',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'plan' => $plan->id,
        ]));

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);

        $canvasLabel = $xpath->query(
            '//*[@data-map-node-id="task:'.$task->id.'"]//span[contains(@class,"canovia-map-node-label")]'
        )->item(0);

        $this->assertNotNull($canvasLabel);
        $this->assertSame('おすすめ', trim($canvasLabel->textContent));

        $graph = $response->viewData('graph');
        $taskNode = collect($graph['nodes'])->firstWhere('id', 'task:'.$task->id);
        $this->assertSame('具体Task名は詳細で見る', data_get($taskNode, 'classic_surface.title'));
        $this->assertSame(25, data_get($taskNode, 'overlay.progress.percent'));
    }

    public function test_global_navigation_contract_remains_present_with_data_layer_controls(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('map.index'));

        $response
            ->assertOk()
            ->assertSee('data-map-global-navigation', false)
            ->assertSee('canovia-map-global-home is-current', false);
    }
}
