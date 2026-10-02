<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use Illuminate\Http\Request;

final class HomePageDataService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly BehaviorEventLogger $eventLogger,
        private readonly UserBehaviorService $behaviorService,
        private readonly UserStateService $stateService,
        private readonly DashboardPresentationService $dashboardService,
        private readonly ContinuityService $continuityService,
        private readonly CalendarPresentationService $calendarService,
        private readonly ActionHomeProjectionService $actionHome,
    ) {}

    public function build(Request $request, bool $prefetch = false): array
    {
        $actorToken = $this->core->actorToken($request);
        $plans = $this->core->plans($request, [
            'tasks',
            'task_dependencies',
            'task_artifacts',
            'plan_resources',
            'plan_artifacts',
            'career',
            'availability',
            'work_logs',
            'memberships',
            'activity_logs',
        ]);

        if (! $prefetch) {
            $this->eventLogger->recordOnce(
                $actorToken,
                BehaviorEventType::DashboardViewed,
                $request,
                metadata: ['owned_plan_count' => $plans->count()],
            );
        }

        $editablePlans = $this->core->editablePlans($request);
        $collaborationPlans = $plans
            ->filter(fn ($plan) => (bool) $plan->is_collaborative)
            ->map(fn ($plan) => [
                'plan' => $plan,
                'role' => $this->core->role($request, $plan),
                'member_count' => 1 + $plan->memberships->count(),
            ])
            ->values();

        $baseline = $this->behaviorService->baseline($actorToken);
        $state = $this->stateService->calculate($actorToken, $baseline, $editablePlans);

        if (! $prefetch) {
            $this->stateService->captureDaily($actorToken, $state);
        }

        $dashboard = $this->dashboardService->build(
            $plans,
            $actorToken,
            $baseline,
            $state,
            $request->session()->get('dashboard.recommendation_excluded', []),
            $editablePlans->pluck('id')->all(),
            $request->user(),
        );

        $dashboard['continuity'] = $this->continuityService->forPlans($editablePlans, $actorToken);
        $dashboard['calendar_week'] = $this->calendarService->weekSummary($plans);
        $actionHome = $this->actionHome->build($plans, $dashboard, $request->user());

        $primaryGuidance = $dashboard['guidance_deck']->first();

        if ($primaryGuidance && ! $prefetch) {
            $adaptive = $primaryGuidance['adaptive'];
            $this->eventLogger->recordOnce(
                $actorToken,
                BehaviorEventType::RecommendationShown,
                $request,
                $primaryGuidance['plan'],
                $primaryGuidance['task'],
                [
                    'source' => 'dashboard_guidance',
                    'selection' => 'objective_priority',
                    'plan_priority' => (int) data_get($primaryGuidance, 'priority_evaluation.priority', 3),
                    'plan_priority_mode' => (string) data_get($primaryGuidance, 'priority_evaluation.mode', 'auto'),
                    'task_priority' => (int) $primaryGuidance['task']->priority,
                    'priority_score' => $adaptive?->priorityScore,
                ],
                withinMinutes: 2,
            );
        }

        return compact('dashboard', 'collaborationPlans', 'actionHome');
    }
}
