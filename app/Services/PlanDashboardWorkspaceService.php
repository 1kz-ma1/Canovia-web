<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Request;

final class PlanDashboardWorkspaceService
{
    public function __construct(
        private readonly PlanProgressService $progress,
        private readonly RoadmapService $roadmap,
        private readonly RoadmapSpatialProjectionService $roadmapSpatial,
        private readonly PlanDashboardBoardService $board,
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Request $request, Plan $plan): array
    {
        $plan->loadMissing([
            'tasks.prerequisite',
            'tasks.prerequisites',
            'tasks.resources',
            'workLogs' => fn ($query) => $query
                ->with('task')
                ->latest('worked_on')
                ->latest('id'),
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $progress = $this->progress->calculate($plan);
        $roadmap = $this->roadmap->build($plan);
        $roadmapSpatial = $this->roadmapSpatial->build($roadmap);

        return [
            'plan' => $plan,
            'progress' => $progress,
            'roadmap' => $roadmap,
            'roadmap_spatial' => $roadmapSpatial,
            'dashboard_board' => $this->board->build(
                $plan,
                $progress,
                $roadmap,
                $roadmapSpatial,
            ),
            'can_view' => $this->ownership->canView($request, $plan),
            'can_edit' => $this->ownership->canEdit($request, $plan),
            'can_manage' => $this->ownership->owns($request, $plan),
            'execution_url' => route('map.index', [
                'level' => \App\Enums\MapLevel::Execution->value,
                'intent' => 'execution',
                'plan' => $plan->id,
            ]),
        ];
    }
}
