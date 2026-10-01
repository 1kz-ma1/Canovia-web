<?php

namespace App\Services;

use App\Enums\MapSurfaceRole;

final class MapSurfaceContextService
{
    /**
     * Resolve what problem the current Map surface is serving.
     *
     * This is descriptive only. It must not decide whether Map or Classic
     * should be shown to the user.
     *
     * @param array<string,mixed> $graph
     * @return array{role:string,scope:string,intent:?string,level:string}
     */
    public function resolve(array $graph): array
    {
        $level = (string) ($graph['level'] ?? 'l0');
        $intent = is_string(data_get($graph, 'hierarchy.intent'))
            ? (string) data_get($graph, 'hierarchy.intent')
            : null;

        $role = match (true) {
            (bool) ($graph['plan_workspace_mode'] ?? false) => MapSurfaceRole::PlanContext,
            (bool) ($graph['collaboration_workspace_mode'] ?? false) => MapSurfaceRole::CollaborationContext,
            (bool) ($graph['reflection_mode'] ?? false) => MapSurfaceRole::ReflectionContext,
            $level === 'l3' || $intent === 'execution' => MapSurfaceRole::ExecutionContext,
            $level === 'l0' => MapSurfaceRole::GlobalNavigation,
            (bool) ($graph['collaboration_mode'] ?? false) => MapSurfaceRole::CollaborationContext,
            default => MapSurfaceRole::HierarchyContext,
        };

        return [
            'role' => $role->value,
            'scope' => $role->scope(),
            'intent' => $intent,
            'level' => $level,
        ];
    }
}
