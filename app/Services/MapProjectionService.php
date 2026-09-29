<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MapProjectionService
{
    public function __construct(
        private readonly IntentMapProjectionService $intent,
        private readonly HierarchyMapProjectionService $hierarchy,
        private readonly CollaborationMapProjectionService $collaboration,
        private readonly ReflectionMapProjectionService $reflection,
        private readonly ExecutionMapProjectionService $execution,
        private readonly MapSpatialMemoryService $spatialMemory,
        private readonly MapDataLayerProjectionService $dataLayers,
    ) {}

    /**
     * Backward-compatible V43/V44.0 entry point.
     *
     * Internal callers that do not choose a level still receive the L3 Execution
     * projection. The public /map route chooses L0 explicitly in V44.1.
     *
     * @return array<string,mixed>
     */
    public function build(Request $request): array
    {
        return $this->project($request, MapLevel::Execution);
    }

    /**
     * Hierarchical projection boundary.
     *
     * @return array<string,mixed>
     */
    public function project(Request $request, MapLevel $level): array
    {
        $projection = match ($level) {
            MapLevel::Intent => $this->intent->build($request),
            MapLevel::Domain, MapLevel::Plan => match ($request->query('intent')) {
                'collaboration' => $this->collaboration->build($request, $level),
                'reflection' => $this->reflection->build($request, $level),
                default => $this->hierarchy->build($request, $level),
            },
            MapLevel::Execution => $this->execution->build($request),
        };

        $projection = $this->spatialMemory->decorate($request, $level, $projection);

        return $this->dataLayers->decorate($projection);
    }

    /**
     * Build the server-authoritative Companion context for one currently projected L3 node.
     *
     * V44.1 keeps contextual Companion on Execution Map only. Space Station gets
     * its dedicated Companion/Capture routing in V44.2.
     *
     * @return array<string,mixed>|null
     */
    public function companionContext(Request $request, string $nodeId): ?array
    {
        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return null;
        }

        $graph = $this->build($request);
        $nodes = collect($graph['nodes'] ?? [])->keyBy('id');
        $edges = collect($graph['edges'] ?? []);
        $selected = $nodes->get($nodeId);

        if (! is_array($selected)) {
            return null;
        }

        $relations = $edges
            ->filter(fn (array $edge) => ($edge['source'] ?? null) === $nodeId || ($edge['target'] ?? null) === $nodeId)
            ->map(function (array $edge) use ($nodeId, $nodes) {
                $outgoing = ($edge['source'] ?? null) === $nodeId;
                $neighborId = $outgoing ? ($edge['target'] ?? null) : ($edge['source'] ?? null);
                $neighbor = is_string($neighborId) ? $nodes->get($neighborId) : null;

                if (! is_array($neighbor)) {
                    return null;
                }

                return [
                    'relation' => (string) ($edge['relation'] ?? ''),
                    'direction' => $outgoing ? 'outgoing' : 'incoming',
                    'node' => $this->companionNode($neighbor),
                ];
            })
            ->filter()
            ->take(8)
            ->values();

        $primary = $nodes->get((string) ($graph['primary_node_id'] ?? ''));
        $planNode = $nodes->first(fn ($node) => is_array($node) && ($node['type'] ?? null) === 'plan');

        $selectedType = (string) ($selected['type'] ?? '');
        $selectedEntityId = (int) ($selected['entity_id'] ?? 0);
        $primaryTaskId = is_array($primary) && ($primary['type'] ?? null) === 'task'
            ? (int) ($primary['entity_id'] ?? 0)
            : 0;
        $planId = is_array($planNode) ? (int) ($planNode['entity_id'] ?? 0) : 0;

        $taskId = match ($selectedType) {
            'task' => $selectedEntityId,
            'tool', 'evidence' => $primaryTaskId,
            default => 0,
        };

        return [
            'projection_key' => (string) ($graph['projection_key'] ?? ''),
            'selected_node' => $this->companionNode($selected),
            'surrounding_nodes' => $relations->all(),
            'primary_action' => is_array($primary) ? $this->companionNode($primary) : null,
            'target' => [
                'plan_id' => in_array($selectedType, ['goal', 'plan', 'task', 'tool', 'evidence'], true) && $planId > 0
                    ? $planId
                    : null,
                'task_id' => $taskId > 0 ? $taskId : null,
                'inbox_item_id' => $selectedType === 'inbox' && $selectedEntityId > 0
                    ? $selectedEntityId
                    : null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function companionNode(array $node): array
    {
        return [
            'id' => (string) ($node['id'] ?? ''),
            'type' => (string) ($node['type'] ?? ''),
            'entity_id' => isset($node['entity_id']) ? (int) $node['entity_id'] : null,
            'label' => Str::limit((string) ($node['label'] ?? ''), 180),
            'subtitle' => Str::limit((string) ($node['subtitle'] ?? ''), 260),
            'state' => (string) ($node['state'] ?? ''),
            'position_role' => (string) ($node['position_role'] ?? ''),
            'importance' => (float) ($node['importance'] ?? 0),
        ];
    }
}
