<?php

namespace App\Services;

use Illuminate\Support\Collection;

final class RoadmapSpatialProjectionService
{
    private const PHASE_GAP = 280;

    private const PHASE_START_X = 170;

    private const CLUSTER_START_Y = 150;

    private const TASK_GAP_Y = 112;

    private const CLUSTER_GAP_Y = 52;

    /**
     * Convert the canonical Roadmap task flow into a read-only spatial graph.
     *
     * "Phase" here means dependency depth, not a new persisted Plan phase.
     * Tasks with the same direct prerequisite set inside one depth are grouped
     * into a presentation-only cluster so parallel work becomes visible.
     *
     * @param array<string,mixed> $roadmap
     * @return array<string,mixed>
     */
    public function build(array $roadmap): array
    {
        $sourceNodes = collect($roadmap['nodes'] ?? [])
            ->filter(fn ($node) => is_array($node) && (int) ($node['task_id'] ?? 0) > 0)
            ->values();

        if ($sourceNodes->isEmpty()) {
            return [
                'schema_version' => 1,
                'width' => 760,
                'height' => 520,
                'phases' => [],
                'clusters' => [],
                'nodes' => [],
                'edges' => [],
                'current_task_id' => null,
                'parallel_cluster_count' => 0,
                'projection_key' => hash('sha256', 'roadmap-spatial-empty'),
            ];
        }

        $nodesByTaskId = $sourceNodes->keyBy(fn (array $node) => (int) $node['task_id']);
        $depthMemo = [];

        foreach ($nodesByTaskId->keys() as $taskId) {
            $this->dependencyDepth((int) $taskId, $nodesByTaskId, $depthMemo, []);
        }

        $phaseBuckets = $sourceNodes
            ->groupBy(fn (array $node) => (int) ($depthMemo[(int) $node['task_id']] ?? 0))
            ->sortKeys();

        $phaseCount = $phaseBuckets->count();
        $width = max(760, (self::PHASE_START_X * 2) + max(0, $phaseCount - 1) * self::PHASE_GAP);
        $phaseStartX = max(
            self::PHASE_START_X,
            (int) round(($width - max(0, $phaseCount - 1) * self::PHASE_GAP) / 2),
        );

        $phases = [];
        $clusters = [];
        $projectedNodes = [];
        $positions = [];
        $maxY = self::CLUSTER_START_Y;

        foreach ($phaseBuckets as $depth => $phaseNodes) {
            $depth = (int) $depth;
            $phaseX = $phaseStartX + ($depth * self::PHASE_GAP);
            $phaseId = 'roadmap:phase:'.$depth;
            $clusterGroups = $phaseNodes
                ->groupBy(fn (array $node) => $this->dependencySignature($node, $nodesByTaskId))
                ->values();

            $yCursor = self::CLUSTER_START_Y;

            foreach ($clusterGroups as $clusterIndex => $clusterNodes) {
                $clusterNodes = collect($clusterNodes)->values();
                $clusterId = 'roadmap:cluster:'.$depth.':'.$clusterIndex;
                $taskCount = $clusterNodes->count();
                $clusterHeight = max(92, 42 + ($taskCount * self::TASK_GAP_Y));
                $clusterTop = $yCursor - 34;
                $parallel = $taskCount > 1;

                $clusters[] = [
                    'id' => $clusterId,
                    'phase_id' => $phaseId,
                    'depth' => $depth,
                    'x' => $phaseX,
                    'y' => $clusterTop,
                    'width' => 214,
                    'height' => $clusterHeight,
                    'task_count' => $taskCount,
                    'is_parallel' => $parallel,
                    'label' => $parallel ? '並行 '.$taskCount.'件' : 'Task',
                ];

                foreach ($clusterNodes as $taskIndex => $node) {
                    $taskId = (int) $node['task_id'];
                    $taskY = $yCursor + 24 + ($taskIndex * self::TASK_GAP_Y);
                    $positions[$taskId] = ['x' => $phaseX, 'y' => $taskY];

                    $projectedNodes[] = [
                        ...$node,
                        'id' => 'task:'.$taskId,
                        'phase_id' => $phaseId,
                        'cluster_id' => $clusterId,
                        'dependency_depth' => $depth,
                        'x' => $phaseX,
                        'y' => $taskY,
                    ];
                }

                $yCursor += $clusterHeight + self::CLUSTER_GAP_Y;
            }

            $maxY = max($maxY, $yCursor);

            $phases[] = [
                'id' => $phaseId,
                'depth' => $depth,
                'x' => $phaseX,
                'label' => $depth === 0 ? '起点' : '段階 '.($depth + 1),
                'task_count' => $phaseNodes->count(),
                'cluster_count' => $clusterGroups->count(),
            ];
        }

        $edges = [];
        $dependencyPairs = [];

        foreach ($projectedNodes as $node) {
            $taskId = (int) $node['task_id'];
            $targetPosition = $positions[$taskId] ?? null;
            if (! $targetPosition) {
                continue;
            }

            foreach ($this->dependencyIds($node, $nodesByTaskId) as $dependencyId) {
                $sourcePosition = $positions[$dependencyId] ?? null;
                if (! $sourcePosition) {
                    continue;
                }

                $pairKey = $dependencyId.'>'.$taskId;
                $dependencyPairs[$pairKey] = true;
                $edges[] = [
                    'source' => 'task:'.$dependencyId,
                    'target' => 'task:'.$taskId,
                    'relation' => 'dependency',
                    'x1' => $sourcePosition['x'],
                    'y1' => $sourcePosition['y'],
                    'x2' => $targetPosition['x'],
                    'y2' => $targetPosition['y'],
                ];
            }

            foreach (collect($node['source_task_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0 && isset($positions[$id]))
                ->unique()
                ->values() as $sourceTaskId) {
                $pairKey = $sourceTaskId.'>'.$taskId;
                if (isset($dependencyPairs[$pairKey])) {
                    continue;
                }

                $sourcePosition = $positions[$sourceTaskId];
                $edges[] = [
                    'source' => 'task:'.$sourceTaskId,
                    'target' => 'task:'.$taskId,
                    'relation' => 'lineage',
                    'x1' => $sourcePosition['x'],
                    'y1' => $sourcePosition['y'],
                    'x2' => $targetPosition['x'],
                    'y2' => $targetPosition['y'],
                ];
            }
        }

        $height = max(520, $maxY + 80);
        $currentTaskId = collect($projectedNodes)
            ->first(fn (array $node) => (bool) ($node['is_current'] ?? false))['task_id'] ?? null;

        $projection = [
            'schema_version' => 1,
            'width' => $width,
            'height' => $height,
            'phases' => $phases,
            'clusters' => $clusters,
            'nodes' => $projectedNodes,
            'edges' => $edges,
            'current_task_id' => $currentTaskId ? (int) $currentTaskId : null,
            'parallel_cluster_count' => collect($clusters)->where('is_parallel', true)->count(),
        ];

        $projection['projection_key'] = hash(
            'sha256',
            (string) json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $projection;
    }

    /**
     * @param Collection<int,array<string,mixed>> $nodesByTaskId
     * @param array<int,int> $memo
     * @param array<int,bool> $visiting
     */
    private function dependencyDepth(
        int $taskId,
        Collection $nodesByTaskId,
        array &$memo,
        array $visiting,
    ): int {
        if (isset($memo[$taskId])) {
            return $memo[$taskId];
        }

        if (isset($visiting[$taskId])) {
            // Cycles should never make Roadmap disappear. Collapse the cyclic
            // portion to the current depth and let deterministic task order
            // keep the visual stable.
            return 0;
        }

        $node = $nodesByTaskId->get($taskId);
        if (! is_array($node)) {
            return 0;
        }

        $visiting[$taskId] = true;
        $depth = 0;

        foreach ($this->dependencyIds($node, $nodesByTaskId) as $dependencyId) {
            $depth = max(
                $depth,
                1 + $this->dependencyDepth($dependencyId, $nodesByTaskId, $memo, $visiting),
            );
        }

        $memo[$taskId] = $depth;

        return $depth;
    }

    /**
     * @param Collection<int,array<string,mixed>> $nodesByTaskId
     * @return array<int,int>
     */
    private function dependencyIds(array $node, Collection $nodesByTaskId): array
    {
        return collect($node['depends_on_task_ids'] ?? [])
            ->push($node['depends_on_task_id'] ?? null)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $nodesByTaskId->has($id))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Tasks with the same direct prerequisites at one dependency depth are
     * presentation-only peers in one cluster.
     *
     * @param Collection<int,array<string,mixed>> $nodesByTaskId
     */
    private function dependencySignature(array $node, Collection $nodesByTaskId): string
    {
        $ids = $this->dependencyIds($node, $nodesByTaskId);

        return $ids === [] ? 'root' : implode('-', $ids);
    }
}
