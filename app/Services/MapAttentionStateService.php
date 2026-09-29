<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Collection;

final class MapAttentionStateService
{
    /**
     * Apply V43-compatible visual attention to a semantic execution graph.
     *
     * Navigation existence/relations are already fixed when this runs. This layer
     * only decides what should stand out and where it should appear.
     *
     * @param Collection<int,array<string,mixed>> $nodes
     * @param Collection<int,array<string,mixed>> $edges
     * @param array<string,mixed> $context
     * @return array{
     *     nodes: Collection<int,array<string,mixed>>,
     *     edges: Collection<int,array<string,mixed>>,
     *     primary_node_id:?string,
     *     primary_launch:?array<string,mixed>,
     *     has_primary_action:bool
     * }
     */
    public function apply(Collection $nodes, Collection $edges, array $context): array
    {
        $currentTask = $context['current_task'] ?? null;
        $primaryNodeId = $currentTask instanceof Task
            ? 'task:'.$currentTask->id
            : null;

        $projectedNodes = $nodes
            ->map(fn (array $node) => $this->projectNodeAttention($node))
            ->values();

        $projectedEdges = $edges
            ->map(fn (array $edge) => $this->projectEdgeAttention($edge))
            ->values();

        $primaryNode = $primaryNodeId
            ? $projectedNodes->first(fn (array $node) => ($node['id'] ?? null) === $primaryNodeId)
            : null;

        $primaryLaunch = null;

        if (is_array($primaryNode)) {
            $actions = collect(data_get($primaryNode, 'classic_surface.actions', []));
            $primaryLaunch = $actions->first(
                fn ($action) => is_array($action) && ($action['primary'] ?? false)
            ) ?? $actions->first();

            if (! is_array($primaryLaunch)) {
                $primaryLaunch = null;
            }
        }

        return [
            'nodes' => $projectedNodes,
            'edges' => $projectedEdges,
            'primary_node_id' => $primaryNodeId,
            'primary_launch' => $primaryLaunch,
            'has_primary_action' => $primaryNodeId !== null,
        ];
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function projectNodeAttention(array $node): array
    {
        $attention = match ((string) ($node['attention_role'] ?? '')) {
            'goal' => [
                'importance' => 0.60,
                'state' => 'future',
                'position_role' => 'future-goal',
                'size_weight' => 0.70,
                'position' => ['x' => 34, 'y' => 16],
            ],
            'plan' => [
                'importance' => 0.96,
                'state' => 'hub',
                'position_role' => 'context-plan',
                'size_weight' => 0.96,
                'position' => ['x' => 50, 'y' => 50],
            ],
            'primary-task' => [
                'importance' => 1.0,
                'state' => 'primary',
                'position_role' => 'action-primary',
                'size_weight' => 0.94,
                'position' => ['x' => 70, 'y' => 31],
            ],
            'next-task' => [
                'importance' => 0.68,
                'state' => 'future',
                'position_role' => 'future-next',
                'size_weight' => 0.76,
                'position' => ['x' => 56, 'y' => 16],
            ],
            'dependency-task-1' => [
                'importance' => 0.60,
                'state' => 'input',
                'position_role' => 'input-dependency-1',
                'size_weight' => 0.72,
                'position' => ['x' => 18, 'y' => 34],
            ],
            'dependency-task-2' => [
                'importance' => 0.58,
                'state' => 'input',
                'position_role' => 'input-dependency-2',
                'size_weight' => 0.70,
                'position' => ['x' => 18, 'y' => 50],
            ],
            'dependency-task-3' => [
                'importance' => 0.56,
                'state' => 'input',
                'position_role' => 'input-dependency-3',
                'size_weight' => 0.68,
                'position' => ['x' => 18, 'y' => 66],
            ],
            'tool' => [
                'importance' => 0.78,
                'state' => 'action',
                'position_role' => 'action-tool',
                'size_weight' => 0.82,
                'position' => ['x' => 82, 'y' => 51],
            ],
            'evidence' => [
                'importance' => 0.56,
                'state' => 'past',
                'position_role' => 'past-evidence',
                'size_weight' => 0.70,
                'position' => ['x' => 50, 'y' => 79],
            ],
            'inbox' => [
                'importance' => 0.50,
                'state' => 'input',
                'position_role' => 'input-inbox',
                'size_weight' => 0.68,
                'position' => ['x' => 18, 'y' => 52],
            ],
            default => [
                'importance' => 0.50,
                'state' => 'context',
                'position_role' => 'context',
                'size_weight' => 0.70,
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
        ];
    }

    /**
     * @param array<string,mixed> $edge
     * @return array<string,mixed>
     */
    private function projectEdgeAttention(array $edge): array
    {
        $strength = match ((string) ($edge['attention_role'] ?? '')) {
            'goal-plan' => 0.68,
            'plan-primary' => 1.0,
            'primary-next' => 0.72,
            'primary-tool' => 0.86,
            'primary-evidence' => 0.62,
            'dependency-next' => 0.62,
            'inbox-plan' => 0.45,
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
