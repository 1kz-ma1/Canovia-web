<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Support\MapNodePresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MapPresentationFoundationV477Test extends TestCase
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

    public function test_leaf_presentation_exposes_role_instead_of_internal_content(): void
    {
        $this->assertSame('おすすめ', MapNodePresentation::for([
            'type' => 'task',
            'state' => 'primary',
            'position_role' => 'action-primary',
            'eyebrow' => 'NOW · PRIMARY ACTION',
            'label' => 'V47.7を実装する',
            'subtitle' => '具体的な説明',
        ])['label']);

        $this->assertSame('次にやる', MapNodePresentation::for([
            'type' => 'task',
            'state' => 'future',
            'position_role' => 'future-next',
            'eyebrow' => 'NEXT TASK',
            'label' => '次のTask名',
        ])['label']);

        $this->assertSame('レビュー待ち', MapNodePresentation::for([
            'type' => 'collaboration_item',
            'eyebrow' => 'REVIEW WAITING',
            'label' => 'PR #123',
        ])['label']);

        $this->assertSame('外部確認', MapNodePresentation::for([
            'type' => 'collaboration_item',
            'eyebrow' => 'EXTERNAL TOOL',
            'label' => 'GitHub Pull Request',
        ])['label']);

        $this->assertSame('Evidence', MapNodePresentation::for([
            'type' => 'evidence',
            'eyebrow' => 'EVIDENCE',
            'label' => '実装完了ログ',
        ])['label']);

        $container = MapNodePresentation::for([
            'type' => 'plan',
            'eyebrow' => 'PLAN',
            'label' => 'Canoviaを改善する',
            'subtitle' => '期限 2026/12/01',
        ]);

        $this->assertSame('container', $container['kind']);
        $this->assertSame('Canoviaを改善する', $container['label']);
        $this->assertNull($container['eyebrow']);
        $this->assertNull($container['subtitle']);
    }

    public function test_execution_canvas_shows_recommendation_role_and_keeps_task_detail_in_palette(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Map Presentationを整える',
            'description' => 'Canvasと詳細を分離する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '具体的なTask名はPaletteで見る',
            'description' => 'Nodeには役割だけを出す',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
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

        $response
            ->assertOk()
            ->assertSee('data-map-presentation-kind="leaf"', false)
            ->assertSee('おすすめ')
            ->assertSee('具体的なTask名はPaletteで見る')
            ->assertSee('選んだマスの内容と操作を確認できます')
            ->assertDontSee('Context Inspector')
            ->assertDontSee('NOW · PRIMARY ACTION');

        $graph = $response->viewData('graph');
        $taskNode = $graph['nodes']->firstWhere('id', 'task:'.$task->id);

        $this->assertSame(
            '具体的なTask名はPaletteで見る',
            data_get($taskNode, 'classic_surface.title'),
            'canonical detail copy stays in the palette source'
        );
        $this->assertSame('action-primary', $taskNode['position_role']);
    }

    public function test_leaf_nodes_are_palette_first_even_when_a_direct_destination_exists(): void
    {
        $blade = file_get_contents(resource_path('views/map/index.blade.php'));

        $this->assertStringContainsString(
            '&& (! $isLeafPresentation || $isSatelliteNavigation)',
            $blade,
        );
        $this->assertStringContainsString(
            'data-map-presentation-kind="{{ $presentationKind }}"',
            $blade,
        );
        $this->assertStringContainsString(
            'data-map-node-entry-mode="{{ $nodeEntryMode }}"',
            $blade,
        );
        $this->assertStringContainsString('data-map-direct-open', $blade);
    }
}
