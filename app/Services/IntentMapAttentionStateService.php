<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class IntentMapAttentionStateService
{
    /**
     * Apply the stable V44.1 L0 spatial grammar.
     *
     * Recommendation does not create/remove these nodes. This layer only projects
     * the semantic L0 graph into the current visual attention state.
     *
     * @param Collection<int,array<string,mixed>> $nodes
     * @param Collection<int,array<string,mixed>> $edges
     * @return array{
     *     nodes:Collection<int,array<string,mixed>>,
     *     edges:Collection<int,array<string,mixed>>,
     *     center_node_id:string
     * }
     */
    public function apply(Collection $nodes, Collection $edges): array
    {
        return [
            'nodes' => $nodes
                ->map(fn (array $node) => $this->projectNodeAttention($node))
                ->values(),
            'edges' => $edges
                ->map(fn (array $edge) => $this->projectEdgeAttention($edge))
                ->values(),
            'center_node_id' => 'intent:space-station',
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function projectNodeAttention(array $node): array
    {
        $attention = match ((string) ($node['attention_role'] ?? '')) {
            'space-station' => [
                'importance' => 0.96,
                'state' => 'hub',
                'position_role' => 'space-station',
                'size_weight' => 1.0,
                'position' => ['x' => 50, 'y' => 50],
            ],
            'intent-plan' => [
                'importance' => 0.78,
                'state' => 'intent',
                'position_role' => 'intent-plan',
                'size_weight' => 0.82,
                'position' => ['x' => 24, 'y' => 23],
            ],
            'intent-execution' => [
                'importance' => 0.82,
                'state' => 'intent',
                'position_role' => 'intent-execution',
                'size_weight' => 0.86,
                'position' => ['x' => 76, 'y' => 23],
            ],
            'intent-reflection' => [
                'importance' => 0.74,
                'state' => 'intent',
                'position_role' => 'intent-reflection',
                'size_weight' => 0.78,
                'position' => ['x' => 76, 'y' => 77],
            ],
            'intent-collaboration' => [
                'importance' => 0.74,
                'state' => 'intent',
                'position_role' => 'intent-collaboration',
                'size_weight' => 0.78,
                'position' => ['x' => 24, 'y' => 77],
            ],
            default => [
                'importance' => 0.60,
                'state' => 'intent',
                'position_role' => 'intent-context',
                'size_weight' => 0.72,
                'position' => ['x' => 50, 'y' => 50],
            ],
        };

        return [
            'id' => $node['id'] ?? null,
            'type' => $node['type'] ?? null,
            'entity_id' => $node['entity_id'] ?? null,
            'eyebrow' => $node['eyebrow'] ?? '',
            'label' => $node['label'] ?? '',
            'subtitle' => $node['subtitle'] ?? '',
            'importance' => $attention['importance'],
            'state' => $attention['state'],
            'position_role' => $attention['position_role'],
            'size_weight' => $attention['size_weight'],
            'position' => $attention['position'],
            'available_action' => $node['available_action'] ?? null,
            'classic_surface' => $node['classic_surface'] ?? [],
            'navigation_kind' => $node['navigation_kind'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $edge
     * @return array<string,mixed>
     */
    private function projectEdgeAttention(array $edge): array
    {
        $strength = match ((string) ($edge['attention_role'] ?? '')) {
            'station-execution' => 0.68,
            'station-plan' => 0.60,
            'station-reflection', 'station-collaboration' => 0.54,
            default => 0.50,
        };

        return [
            'source' => (string) ($edge['source'] ?? ''),
            'target' => (string) ($edge['target'] ?? ''),
            'relation' => (string) ($edge['relation'] ?? ''),
            'strength' => $strength,
            'secondary' => (bool) ($edge['secondary'] ?? false),
        ];
    }
}
