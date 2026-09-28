<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class IntentMapProjectionService
{
    public function __construct(
        private readonly IntentNavigationGraphService $navigationGraph,
        private readonly IntentMapAttentionStateService $attention,
    ) {}

    /**
     * Build the L0 Intent Hub projection.
     *
     * @return array{
     *     level:string,
     *     nodes:Collection<int,array<string,mixed>>,
     *     edges:Collection<int,array<string,mixed>>,
     *     center_node_id:string,
     *     primary_node_id:null,
     *     primary_launch:null,
     *     has_primary_action:false,
     *     projection_key:string
     * }
     */
    public function build(): array
    {
        $graph = $this->navigationGraph->build();
        $attention = $this->attention->apply(
            $graph['nodes'],
            $graph['edges'],
        );

        $nodes = $attention['nodes']
            ->map(fn (array $node) => $this->withDirectNavigation($node))
            ->values();
        $edges = $attention['edges']->values();
        $centerNodeId = $attention['center_node_id'];

        return [
            'level' => 'l0',
            'nodes' => $nodes,
            'edges' => $edges,
            'center_node_id' => $centerNodeId,
            'primary_node_id' => null,
            'primary_launch' => null,
            'has_primary_action' => false,
            'projection_key' => $this->projectionKey($nodes, $edges, $centerNodeId),
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function withDirectNavigation(array $node): array
    {
        $actions = collect(data_get($node, 'classic_surface.actions', []));
        $action = $actions->first(fn ($candidate) => is_array($candidate) && ($candidate['primary'] ?? false))
            ?? $actions->first();

        $node['direct_navigation'] = is_array($action) && filled($action['url'] ?? null)
            ? [
                'label' => (string) ($action['label'] ?? '開く'),
                'url' => (string) $action['url'],
            ]
            : null;

        return $node;
    }

    private function projectionKey(Collection $nodes, Collection $edges, string $centerNodeId): string
    {
        return hash(
            'sha256',
            (string) json_encode([
                'level' => 'l0',
                'center_node_id' => $centerNodeId,
                'nodes' => $nodes->all(),
                'edges' => $edges->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
