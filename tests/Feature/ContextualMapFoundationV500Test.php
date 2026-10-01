<?php

namespace Tests\Feature;

use App\Services\MapComplexitySnapshotService;
use App\Services\MapSurfaceContextService;
use Tests\TestCase;

class ContextualMapFoundationV500Test extends TestCase
{
    public function test_surface_role_resolution_describes_context_without_selecting_a_view(): void
    {
        $service = app(MapSurfaceContextService::class);

        $this->assertSame([
            'role' => 'global_navigation',
            'scope' => 'global',
            'intent' => null,
            'level' => 'l0',
        ], $service->resolve([
            'level' => 'l0',
            'hierarchy' => [],
        ]));

        $this->assertSame('plan_context', $service->resolve([
            'level' => 'l2',
            'plan_workspace_mode' => true,
            'hierarchy' => ['intent' => 'plan'],
        ])['role']);

        $this->assertSame('collaboration_context', $service->resolve([
            'level' => 'l2',
            'collaboration_workspace_mode' => true,
            'hierarchy' => ['intent' => 'collaboration'],
        ])['role']);

        $this->assertSame('reflection_context', $service->resolve([
            'level' => 'l1',
            'reflection_mode' => true,
            'hierarchy' => ['intent' => 'reflection'],
        ])['role']);

        $this->assertSame('execution_context', $service->resolve([
            'level' => 'l3',
            'hierarchy' => ['intent' => 'execution'],
        ])['role']);

        $hierarchy = $service->resolve([
            'level' => 'l1',
            'hierarchy' => ['intent' => 'plan'],
        ]);

        $this->assertSame('hierarchy_context', $hierarchy['role']);
        $this->assertSame('contextual', $hierarchy['scope']);
        $this->assertArrayNotHasKey('preferred_view', $hierarchy);
        $this->assertArrayNotHasKey('recommendation', $hierarchy);
    }

    public function test_complexity_snapshot_exposes_only_structural_observations(): void
    {
        $snapshot = app(MapComplexitySnapshotService::class)->fromGraph([
            'nodes' => [
                ['id' => 'plan:1', 'type' => 'plan'],
                ['id' => 'task:1', 'type' => 'task'],
                ['id' => 'task:2', 'type' => 'task'],
            ],
            'edges' => [
                ['source' => 'task:1', 'target' => 'task:2', 'relation' => 'dependency'],
            ],
            'plan_workspace' => [
                'roadmap_spatial' => [
                    'nodes' => [
                        [
                            'task_id' => 1,
                            'visual_state' => 'current',
                            'dependency_state' => 'ready',
                        ],
                        [
                            'task_id' => 2,
                            'visual_state' => 'blocked',
                            'dependency_state' => 'blocked',
                        ],
                        [
                            'task_id' => 3,
                            'visual_state' => 'future',
                            'dependency_state' => 'ready',
                        ],
                    ],
                    'edges' => [
                        ['source' => 'task:1', 'target' => 'task:2', 'relation' => 'dependency'],
                        ['source' => 'task:2', 'target' => 'task:3', 'relation' => 'dependency'],
                        ['source' => 'task:1', 'target' => 'task:3', 'relation' => 'lineage'],
                    ],
                    'clusters' => [
                        ['id' => 'cluster:a', 'is_parallel' => true],
                        ['id' => 'cluster:b', 'is_parallel' => false],
                    ],
                    'phases' => [
                        ['id' => 'phase:0'],
                        ['id' => 'phase:1'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame(3, $snapshot['node_count']);
        $this->assertSame(1, $snapshot['edge_count']);
        $this->assertSame(1, $snapshot['plan_count']);
        $this->assertSame(3, $snapshot['task_count']);
        $this->assertSame(2, $snapshot['dependency_count']);
        $this->assertSame(2, $snapshot['cluster_count']);
        $this->assertSame(1, $snapshot['parallel_cluster_count']);
        $this->assertSame(2, $snapshot['phase_count']);
        $this->assertSame(1, $snapshot['blocked_count']);
        $this->assertTrue($snapshot['has_dependencies']);
        $this->assertTrue($snapshot['has_parallelism']);
        $this->assertTrue($snapshot['has_blockers']);

        $this->assertArrayNotHasKey('score', $snapshot);
        $this->assertArrayNotHasKey('complexity_score', $snapshot);
        $this->assertArrayNotHasKey('preferred_view', $snapshot);
        $this->assertArrayNotHasKey('recommendation', $snapshot);
        $this->assertArrayNotHasKey('threshold', $snapshot);
    }
}
