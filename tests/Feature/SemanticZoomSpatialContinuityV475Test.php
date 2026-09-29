<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\MapHierarchyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SemanticZoomSpatialContinuityV475Test extends TestCase
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

    public function test_plan_node_identity_exists_on_both_l2_and_l3_for_spatial_continuity(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Spatial Continuityを仕上げる',
            'description' => '選択Nodeのidentityを保つ',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Node continuityを確認する',
            'description' => 'L3のPrimary Action',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $hierarchy = app(MapHierarchyContextService::class);
        $domainKey = $hierarchy->domainKey($plan->category);

        $l2 = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2',
            'intent' => 'execution',
            'domain' => $domainKey,
        ]));

        $l2
            ->assertOk()
            ->assertSee('data-map-node-id="plan:'.$plan->id.'"', false)
            ->assertSee('data-map-node-entry-mode="semantic"', false);

        $l2Graph = $l2->viewData('graph');
        $l2Plan = $l2Graph['nodes']->firstWhere('id', 'plan:'.$plan->id);

        $this->assertSame('zoom-in', data_get($l2Plan, 'direct_navigation.kind'));

        $l3 = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l3',
            'intent' => 'execution',
            'domain' => $domainKey,
            'plan' => $plan->id,
        ]));

        $l3->assertOk();
        $l3Graph = $l3->viewData('graph');

        $this->assertTrue($l3Graph['nodes']->contains(
            fn (array $node) => ($node['id'] ?? null) === 'plan:'.$plan->id
        ));
        $this->assertSame('task:'.$task->id, $l3Graph['primary_node_id']);
    }

    public function test_runtime_persists_only_short_lived_geometry_and_structural_identity_for_transition(): void
    {
        $source = file_get_contents(resource_path('js/living-map.mjs'));

        $this->assertStringContainsString('source_node_ref:', $source);
        $this->assertStringContainsString('source_rect:', $source);
        $this->assertStringContainsString('from_route:', $source);
        $this->assertStringContainsString('nodeElementById.get(sourceNodeRef)', $source);
        $this->assertStringContainsString('semanticContinuityTransform(sourceRect, targetRect)', $source);

        $payloadStart = strpos($source, 'writeSemanticTransition(windowRef, {');
        $this->assertNotFalse($payloadStart);

        $payloadEnd = strpos($source, '});', $payloadStart);
        $this->assertNotFalse($payloadEnd);

        $payload = substr($source, $payloadStart, $payloadEnd - $payloadStart);

        $this->assertStringNotContainsString('label', $payload);
        $this->assertStringNotContainsString('subtitle', $payload);
        $this->assertStringNotContainsString('description', $payload);
        $this->assertStringNotContainsString('content', $payload);
    }

    public function test_css_animates_anchor_then_surrounding_nodes_and_respects_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('V47.5 Semantic Zoom Spatial Continuity', $css);
        $this->assertStringContainsString('.is-semantic-continuity-anchor', $css);
        $this->assertStringContainsString('canovia-map-semantic-anchor-arrival', $css);
        $this->assertStringContainsString('canovia-map-semantic-child-emerge', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }
}
