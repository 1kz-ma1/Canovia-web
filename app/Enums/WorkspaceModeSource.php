<?php

namespace App\Enums;

enum WorkspaceModeSource: string
{
    case Explicit = 'explicit';
    case RouteHint = 'route_hint';
    case PlanProfile = 'plan_profile';
    case Default = 'default';
}
