<?php

namespace App\Services;

use App\Models\GuidedExecution;
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
        private readonly BehaviorIdentityService $identity,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Request $request, Plan $plan): array
    {
        $canView = $this->ownership->canView($request, $plan);
        if (! $canView) {
            return [
                'plan' => $plan,
                'can_view' => false,
                'can_edit' => false,
                'can_manage' => false,
            ];
        }

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
        $canEdit = $this->ownership->canEdit($request, $plan);
        $board = $this->board->build($plan, $progress, $roadmap, $roadmapSpatial);
        // Reflections are personal Evidence, not WorkLogs or progress events.
        // Viewer-only collaborators must never receive an action link or its text.
        $board['reflections'] = $canEdit ? $this->recentOwnReflections($request, $plan) : [];

        return [
            'plan' => $plan,
            'progress' => $progress,
            'roadmap' => $roadmap,
            'roadmap_spatial' => $roadmapSpatial,
            'dashboard_board' => $board,
            'can_view' => true,
            'can_edit' => $canEdit,
            'can_manage' => $this->ownership->owns($request, $plan),
            'execution_url' => route('map.index', [
                'level' => \App\Enums\MapLevel::Execution->value,
                'intent' => 'execution',
                'plan' => $plan->id,
            ]),
        ];
    }

    /**
     * Only the current author's completed, evidence-backed reflections.
     *
     * The Guided Execution view is author-scoped and editor-only, so the
     * dashboard uses the same identity boundary before offering a link.
     * This is deliberately not a WorkLog projection or a count of progress.
     *
     * @return array<int,array<string,string|int|null>>
     */
    private function recentOwnReflections(Request $request, Plan $plan): array
    {
        $actorToken = $this->identity->resolve($request);
        $userId = $request->user()?->id;

        return GuidedExecution::query()
            ->with('task:id,plan_id,title')
            ->where('plan_id', $plan->id)
            ->where('status', GuidedExecution::STATUS_COMPLETED)
            ->whereNotNull('task_evidence_id')
            ->whereHas('task', fn ($query) => $query->where('plan_id', $plan->id))
            ->whereHas('evidence', fn ($query) => $query
                ->where('plan_id', $plan->id)
                ->whereColumn('task_evidences.task_id', 'guided_executions.task_id')
                ->where('type', 'guided_execution_reflected'))
            ->where(function ($query) use ($userId, $actorToken) {
                if ($userId !== null) {
                    $query->where('user_id', $userId)
                        ->orWhere(fn ($guest) => $guest
                            ->whereNull('user_id')
                            ->where('actor_token', $actorToken));

                    return;
                }

                $query->whereNull('user_id')->where('actor_token', $actorToken);
            })
            ->latest('reflected_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (GuidedExecution $execution) => [
                'id' => (int) $execution->id,
                'task_id' => (int) $execution->task_id,
                'task_title' => (string) $execution->task->title,
                'date' => $execution->reflected_at?->format('Y-m-d'),
                'outcome' => mb_strimwidth(trim((string) $execution->actual_outcome), 0, 110, '…'),
                'url' => route('plans.tasks.guided_execution.show', [$plan, $execution->task]),
            ])
            ->all();
    }
}
