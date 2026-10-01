<?php

namespace App\Services;

final class MapComplexitySnapshotService
{
    /**
     * Structural observations only.
     *
     * This service intentionally has no score, threshold, recommendation or
     * view-selection decision. Automatic View can consume these observations
     * later after product thresholds have been validated.
     *
     * @param array<string,mixed> $graph
     * @return array<string,int|bool>
     */
    public function fromGraph(array $graph): array
    {
        $graphNodes = collect($graph['nodes'] ?? []);
        $graphEdges = collect($graph['edges'] ?? []);

        $spatialNodes = collect(data_get($graph, 'plan_workspace.roadmap_spatial.nodes', []));
        $spatialEdges = collect(data_get($graph, 'plan_workspace.roadmap_spatial.edges', []));
        $clusters = collect(data_get($graph, 'plan_workspace.roadmap_spatial.clusters', []));
        $phases = collect(data_get($graph, 'plan_workspace.roadmap_spatial.phases', []));

        $taskCount = $spatialNodes->isNotEmpty()
            ? $spatialNodes->count()
            : $graphNodes->where('type', 'task')->count();

        $dependencyCount = $spatialEdges->isNotEmpty()
            ? $spatialEdges->where('relation', 'dependency')->count()
            : $graphEdges->where('relation', 'dependency')->count();

        $blockedCount = $spatialNodes
            ->filter(fn (array $node) => ($node['visual_state'] ?? null) === 'blocked'
                || ($node['dependency_state'] ?? null) === 'blocked')
            ->count();

        $parallelClusterCount = $clusters
            ->filter(fn (array $cluster) => (bool) ($cluster['is_parallel'] ?? false))
            ->count();

        return [
            'schema_version' => 1,
            'node_count' => $graphNodes->count(),
            'edge_count' => $graphEdges->count(),
            'plan_count' => $graphNodes->where('type', 'plan')->count(),
            'task_count' => $taskCount,
            'dependency_count' => $dependencyCount,
            'cluster_count' => $clusters->count(),
            'parallel_cluster_count' => $parallelClusterCount,
            'phase_count' => $phases->count(),
            'blocked_count' => $blockedCount,
            'has_dependencies' => $dependencyCount > 0,
            'has_parallelism' => $parallelClusterCount > 0,
            'has_blockers' => $blockedCount > 0,
        ];
    }
}
