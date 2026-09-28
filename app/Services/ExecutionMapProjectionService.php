<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExecutionMapProjectionService
{
    public function __construct(
        private readonly MapExecutionContextService $context,
        private readonly ExecutionNavigationGraphService $navigationGraph,
        private readonly MapAttentionStateService $attention,
    ) {}

    /**
     * Preserve the V43 execution projection contract while composing the new
     * Navigation Graph and Attention State boundaries.
     *
     * @return array{
     *     nodes: Collection<int,array<string,mixed>>,
     *     edges: Collection<int,array<string,mixed>>,
     *     primary_node_id:?string,
     *     primary_launch:?array<string,mixed>,
     *     has_primary_action:bool,
     *     projection_key:string
     * }
     */
    public function build(Request $request): array
    {
        $context = $this->context->resolve($request);
        $graph = $this->navigationGraph->build($context);
        $attention = $this->attention->apply(
            $graph['nodes'],
            $graph['edges'],
            $context,
        );

        $primaryNodeId = $attention['primary_node_id'];
        $nodes = $attention['nodes']
            ->map(fn (array $node) => $this->withDirectNavigation($node, $primaryNodeId))
            ->values();
        $edges = $attention['edges']->values();

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'primary_node_id' => $primaryNodeId,
            'primary_launch' => $attention['primary_launch'],
            'has_primary_action' => $attention['has_primary_action'],
            'projection_key' => $this->projectionKey($nodes, $edges, $primaryNodeId),
        ];
    }

    /**
     * Add a direct-open destination only when the node has one clear canonical place.
     *
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function withDirectNavigation(array $node, ?string $primaryNodeId): array
    {
        $node['direct_navigation'] = null;

        if (($node['id'] ?? null) === $primaryNodeId) {
            return $node;
        }

        if (! in_array(($node['type'] ?? null), ['goal', 'plan', 'tool', 'evidence', 'inbox'], true)) {
            return $node;
        }

        $actions = collect(data_get($node, 'classic_surface.actions', []));
        $action = $actions->first(fn ($candidate) => is_array($candidate) && ($candidate['primary'] ?? false))
            ?? $actions->first();

        if (! is_array($action) || ! filled($action['url'] ?? null)) {
            return $node;
        }

        $node['direct_navigation'] = [
            'label' => (string) ($action['label'] ?? '開く'),
            'url' => (string) $action['url'],
        ];

        return $node;
    }

    private function projectionKey(Collection $nodes, Collection $edges, ?string $primaryNodeId): string
    {
        $payload = [
            'primary_node_id' => $primaryNodeId,
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
        ];

        return hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
