<?php

namespace App\Enums;

enum MapSurfaceRole: string
{
    case GlobalNavigation = 'global_navigation';
    case PlanContext = 'plan_context';
    case CollaborationContext = 'collaboration_context';
    case ReflectionContext = 'reflection_context';
    case ExecutionContext = 'execution_context';
    case HierarchyContext = 'hierarchy_context';

    public function scope(): string
    {
        return $this === self::GlobalNavigation ? 'global' : 'contextual';
    }

    /**
     * @return array<int,string>
     */
    public static function telemetryValues(): array
    {
        return array_map(
            static fn (self $role) => $role->value,
            self::cases(),
        );
    }
}
