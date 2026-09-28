<?php

namespace Tests\Feature;

use App\Services\PersonalizedSatellitePromotionService;
use Tests\TestCase;

class MapSignalDensityV456Test extends TestCase
{
    public function test_map_route_force_overrides_app_padding_and_density_for_true_edge_to_edge_canvas(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'body[data-route-name="map.index"] .app-main {',
            $css,
        );
        $this->assertStringContainsString('padding: 0 !important;', $css);
        $this->assertStringContainsString('margin: 0 !important;', $css);
        $this->assertStringContainsString('width: 100vw !important;', $css);
        $this->assertStringContainsString('height: 100dvh !important;', $css);
    }

    public function test_weak_personalization_signal_does_not_create_l0_noise(): void
    {
        $promotion = app(PersonalizedSatellitePromotionService::class);

        $result = $promotion->promote(collect([
            [
                'id' => 'candidate:weak',
                'kind' => 'plan',
                'node_type' => 'satellite_plan',
                'anchor_node_id' => 'intent:plan',
                'signals' => [
                    'importance' => 1.0,
                    'usage_frequency' => 0.0,
                    'recency' => 0.25,
                    'continuity' => 0.45,
                ],
            ],
        ]));

        $this->assertCount(0, $result['nodes']);
        $this->assertCount(0, $result['edges']);
    }

    public function test_same_intent_only_promotes_one_shortcut_even_when_multiple_candidates_are_strong(): void
    {
        $promotion = app(PersonalizedSatellitePromotionService::class);

        $candidates = collect([
            $this->strongCandidate('candidate:first', 'intent:plan', 1.0),
            $this->strongCandidate('candidate:second', 'intent:plan', 0.95),
            $this->strongCandidate('candidate:execution', 'intent:execution', 0.90),
        ]);

        $result = $promotion->promote($candidates, 2);

        $this->assertCount(2, $result['nodes']);
        $this->assertSame('candidate:first', data_get($result, 'nodes.0.id'));
        $this->assertSame('candidate:execution', data_get($result, 'nodes.1.id'));
    }

    private function strongCandidate(string $id, string $anchor, float $importance): array
    {
        return [
            'id' => $id,
            'kind' => 'plan',
            'node_type' => 'satellite_plan',
            'anchor_node_id' => $anchor,
            'signals' => [
                'importance' => $importance,
                'usage_frequency' => 1.0,
                'recency' => 1.0,
                'continuity' => 1.0,
            ],
        ];
    }
}
