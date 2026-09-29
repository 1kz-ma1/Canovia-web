<?php

namespace App\Services;

use Illuminate\Http\Request;

final class RoadmapPageDataService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly UserBehaviorService $behaviorService,
        private readonly UserStateService $stateService,
        private readonly RecommendationService $recommendationService,
        private readonly RoadmapService $roadmapService,
        private readonly RoadmapSpatialProjectionService $roadmapSpatialProjection,
        private readonly ContinuityService $continuityService,
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}

    public function build(Request $request, ?int $selectedPlanId = null): array
    {
        $plans = $this->core->plans($request, [
            'tasks',
            'task_dependencies',
            'work_logs',
        ]);

        $selectedPlanId ??= (int) $request->integer('plan_id');
        $plan = $selectedPlanId > 0 ? $plans->firstWhere('id', $selectedPlanId) : $plans->first();

        $roadmap = null;
        $recommendation = null;
        $continuity = null;
        $previousPlan = null;
        $nextPlan = null;
        $canEdit = false;
        $canManage = false;
        $collaborationRole = null;
        $roadmapPresentation = null;
        $roadmapSpatial = null;

        if ($plan) {
            $canEdit = $this->core->canEdit($request, $plan);
            $canManage = $this->core->owns($request, $plan);
            $collaborationRole = $this->core->role($request, $plan);

            $planIndex = $plans->values()->search(fn ($candidate) => $candidate->id === $plan->id);
            if ($planIndex !== false) {
                $previousPlan = $planIndex > 0 ? $plans->values()->get($planIndex - 1) : null;
                $nextPlan = $planIndex < ($plans->count() - 1) ? $plans->values()->get($planIndex + 1) : null;
            }

            $profile = $this->categoryProfiles->forPlan($plan);
            $roadmapPresentation = [
                ...$profile->toArray(),
                'active_renderer' => 'spatial_map',
            ];

            $actorToken = $this->core->actorToken($request);
            $baseline = $this->behaviorService->baseline($actorToken);
            $state = $this->stateService->calculate($actorToken, $baseline, collect([$plan]));
            $recommendation = $this->recommendationService->recommend(
                collect([$plan]),
                $state,
                actorToken: $actorToken,
                preferredPlanId: $plan->id,
            );
            $continuity = $this->continuityService->forPlan($plan, $actorToken);
            $roadmap = $this->roadmapService->build(
                $plan,
                $recommendation?->task?->id,
                $continuity['task_id'] ?? null,
            );
            $roadmapSpatial = $this->roadmapSpatialProjection->build($roadmap);
        }

        return compact(
            'plans',
            'plan',
            'roadmap',
            'recommendation',
            'continuity',
            'previousPlan',
            'nextPlan',
            'canEdit',
            'canManage',
            'collaborationRole',
            'roadmapPresentation',
            'roadmapSpatial',
        );
    }
}
