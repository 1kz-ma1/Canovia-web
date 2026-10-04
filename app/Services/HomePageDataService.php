<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use Illuminate\Http\Request;

final class HomePageDataService
{
    private const STATE_SNAPSHOT_INTERVAL_SECONDS = 600;

    private const STATE_SNAPSHOT_SESSION_KEY = 'action_home.state_snapshot_at';

    private const STATE_SNAPSHOT_DATE_SESSION_KEY = 'action_home.state_snapshot_date';
    public function __construct(
        private readonly CoreContextService $core,
        private readonly BehaviorEventLogger $eventLogger,
        private readonly UserBehaviorService $behaviorService,
        private readonly UserStateService $stateService,
        private readonly DashboardPresentationService $dashboardService,
        private readonly ContinuityService $continuityService,
        private readonly CalendarPresentationService $calendarService,
        private readonly ActionHomeProjectionService $actionHome,
        private readonly PlanCategoryProfileService $categoryProfiles,
        private readonly IntelligenceHomeActionService $intelligenceHome,
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
            'availability',
            'work_logs',
        ]);

        // Home previously paid the DB cost for specialized relations on every
        // request even when the account had no matching Plan. Keep the common
        // path lean, then hydrate only the optional surfaces that can render.
        $hasCareerPlan = $plans->contains(
            fn ($plan) => $this->categoryProfiles->forPlan($plan)->key === 'career'
        );
        if ($hasCareerPlan) {
            $this->core->plans($request, ['career']);
        }

        $hasCollaborativePlan = $plans->contains(
            fn ($plan) => (bool) $plan->is_collaborative
        );
        if ($hasCollaborativePlan) {
            $this->core->plans($request, ['activity_logs']);
        }

        if (! $prefetch) {
            $this->eventLogger->recordOnce(
                $actorToken,
                BehaviorEventType::DashboardViewed,
                $request,
                metadata: ['owned_plan_count' => $plans->count()],
            );
        }

        $editablePlans = $this->core->editablePlans($request);

        $baseline = $this->behaviorService->baseline($actorToken);
        $state = $this->stateService->calculate($actorToken, $baseline, $editablePlans);

        if (! $prefetch && $this->stateSnapshotDue($request)) {
            $this->stateService->captureDaily($actorToken, $state);
            $request->session()->put([
                self::STATE_SNAPSHOT_SESSION_KEY => (int) now()->timestamp,
                self::STATE_SNAPSHOT_DATE_SESSION_KEY => today()->toDateString(),
            ]);
        }

        $workSessionContext = $this->continuityService->homeContext(
            $editablePlans,
            $actorToken,
        );

        $dashboard = $this->dashboardService->build(
            $plans,
            $actorToken,
            $baseline,
            $state,
            $request->session()->get('dashboard.recommendation_excluded', []),
            $editablePlans->pluck('id')->all(),
            $request->user(),
            $workSessionContext,
        );

        $dashboard['continuity'] = $workSessionContext['continuity'] ?? null;
        $dashboard['calendar_week'] = $this->calendarService->weekSummary($plans);

        $previousPlanStatuses = (array) $request->session()->get('action_home.plan_statuses', []);
        $actionHome = $this->actionHome->build(
            $plans,
            $dashboard,
            $request->user(),
            $previousPlanStatuses,
        );

        if (! $prefetch) {
            $request->session()->put(
                'action_home.plan_statuses',
                collect($dashboard['plan_tabs'])
                    ->mapWithKeys(fn (array $item) => [
                        (string) $item['plan']->id => (string) data_get($item, 'progress.status', ''),
                    ])
                    ->all(),
            );
        }

        $primaryGuidance = $dashboard['guidance_deck']->first();
        $intelligencePresentation = $this->intelligenceHome->primary(
            $editablePlans,
            $dashboard['guidance_deck'],
        );

        if ($intelligencePresentation && ! $prefetch) {
            $this->eventLogger->recordOnce(
                $actorToken,
                BehaviorEventType::RecommendationShown,
                $request,
                $intelligencePresentation->plan,
                $intelligencePresentation->targetTask,
                [
                    'source' => 'intelligence_action',
                    'domain' => $intelligencePresentation->domain->value,
                    'selection' => 'state_readiness_decision',
                    'decision_type' => (string) $intelligencePresentation->decision->type,
                    'reason_code' => (string) $intelligencePresentation->decision->reasonCode,
                    'action_kind' => (string) $intelligencePresentation->action->kind,
                    'readiness_score' => $intelligencePresentation->readiness->score,
                    'readiness_confidence' => $intelligencePresentation->readiness->confidence->value,
                ],
                withinMinutes: 2,
            );
        } elseif ($primaryGuidance && ! $prefetch) {
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

        return compact(
            'dashboard',
            'actionHome',
            'intelligencePresentation',
        );
    }

    private function stateSnapshotDue(Request $request): bool
    {
        $capturedAt = $request->session()->get(self::STATE_SNAPSHOT_SESSION_KEY);
        $capturedDate = $request->session()->get(self::STATE_SNAPSHOT_DATE_SESSION_KEY);

        if (! is_numeric($capturedAt) || $capturedDate !== today()->toDateString()) {
            return true;
        }

        return ((int) now()->timestamp - (int) $capturedAt)
            >= self::STATE_SNAPSHOT_INTERVAL_SECONDS;
    }
}
