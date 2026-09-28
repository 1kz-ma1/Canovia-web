<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class HierarchyMapAttentionStateService
{
    /**
     * @param Collection<int,array<string,mixed>> $nodes
     * @param Collection<int,array<string,mixed>> $edges
     * @return array{
     *     nodes:Collection<int,array<string,mixed>>,
     *     edges:Collection<int,array<string,mixed>>,
     *     center_node_id:string|null
     * }
     */
    public function apply(Collection $nodes, Collection $edges, ?string $centerNodeId): array
    {
        $children = $nodes
            ->filter(fn (array $node) => ($node['attention_role'] ?? null) === 'hierarchy-child')
            ->values();

        $positions = $this->childPositions($children->count());

        $projectedNodes = $nodes
            ->map(function (array $node) use ($centerNodeId, $children, $positions) {
                $isCenter = ($node['id'] ?? null) === $centerNodeId;
                $childIndex = $children->search(
                    fn (array $candidate) => ($candidate['id'] ?? null) === ($node['id'] ?? null)
                );

                $position = $isCenter
                    ? ['x' => 50, 'y' => 50]
                    : ($positions[$childIndex === false ? 0 : $childIndex] ?? ['x' => 50, 'y' => 24]);

                return [
                    'id' => $node['id'] ?? null,
                    'type' => $node['type'] ?? null,
                    'entity_id' => $node['entity_id'] ?? null,
                    'eyebrow' => $node['eyebrow'] ?? '',
                    'label' => $node['label'] ?? '',
                    'subtitle' => $node['subtitle'] ?? '',
                    'importance' => $isCenter ? 0.92 : 0.72,
                    'state' => $isCenter ? 'hierarchy-parent' : 'hierarchy-child',
                    'position_role' => $isCenter ? 'hierarchy-parent' : 'hierarchy-child',
                    'size_weight' => $isCenter ? 0.96 : 0.78,
                    'position' => $position,
                    'available_action' => $node['available_action'] ?? null,
                    'classic_surface' => $node['classic_surface'] ?? [],
                    'navigation_kind' => $node['navigation_kind'] ?? null,
                ];
            })
            ->values();

        $projectedEdges = $edges
            ->map(fn (array $edge) => [
                'source' => (string) ($edge['source'] ?? ''),
                'target' => (string) ($edge['target'] ?? ''),
                'relation' => (string) ($edge['relation'] ?? ''),
                'strength' => 0.60,
                'secondary' => (bool) ($edge['secondary'] ?? false),
            ])
            ->values();

        return [
            'nodes' => $projectedNodes,
            'edges' => $projectedEdges,
            'center_node_id' => $centerNodeId,
        ];
    }

    /**
     * Deterministic in-memory projection only. Positions are never persisted.
     *
     * @return array<int,array{x:int,y:int}>
     */
    private function childPositions(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $positions = [];
        $radiusX = $count <= 4 ? 30 : 34;
        $radiusY = $count <= 4 ? 30 : 34;

        for ($index = 0; $index < $count; $index++) {
            $angle = (-90 + (360 / $count) * $index) * M_PI / 180;

            $positions[] = [
                'x' => (int) round(50 + cos($angle) * $radiusX),
                'y' => (int) round(50 + sin($angle) * $radiusY),
            ];
        }

        return $positions;
    }
}
