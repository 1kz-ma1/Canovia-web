<?php

namespace App\Services;

use App\Enums\MapLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

final class MapProjectionService
{
    public function __construct(
        private readonly ExecutionMapProjectionService $execution,
    ) {}

    /**
     * Backward-compatible V43 entry point.
     *
     * Existing callers continue to receive the L3 Execution projection until a
     * higher Map level is explicitly requested by a future version.
     *
     * @return array<string,mixed>
     */
    public function build(Request $request): array
    {
        return $this->project($request, MapLevel::Execution);
    }

    /**
     * Hierarchical projection boundary introduced by V44.0.
     *
     * L0-L2 are intentionally reserved here without shipping incomplete UI.
     *
     * @return array<string,mixed>
     */
    public function project(Request $request, MapLevel $level): array
    {
        return match ($level) {
            MapLevel::Execution => $this->execution->build($request),
            default => throw new LogicException(
                'Map level '.$level->value.' is not projected until its version is implemented.'
            ),
        };
    }

    /**
     * Build the server-authoritative Companion context for one currently projected Map node.
     *
     * The client submits only map_node_id. Labels, neighbors and target IDs are rebuilt
     * from the latest projection so stale/tampered browser payloads never become AI context.
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
