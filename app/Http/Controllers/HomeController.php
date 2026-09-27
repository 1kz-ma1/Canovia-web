<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Models\Plan;
use App\Models\WorkLog;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\DashboardPresentationService;
use App\Services\ContinuityService;
use App\Services\CalendarPresentationService;
use App\Services\PlanOwnershipService;
use App\Services\PlanProgressService;
use App\Services\UserBehaviorService;
use App\Services\UserStateService;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(
        Request $request,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $eventLogger,
        PlanOwnershipService $ownership,
        UserBehaviorService $behaviorService,
        UserStateService $stateService,
        DashboardPresentationService $dashboardService,
        ContinuityService $continuityService,
        CalendarPresentationService $calendarService,
    ) {
        $actorToken = $identity->resolve($request);
        $plans = $ownership->ownedPlans($request, [
            'tasks' => fn ($query) => $query->with(['prerequisite', 'resources', 'artifacts'])->orderBy('sort_order')->orderBy('id'),
            'resources',
            'artifacts',
            'careerApplications.selectionEvents.interviewReview',
            'careerCaptures',
            'availabilityRules',
            'availabilityOverrides',
            'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id'),
            'memberships',
        ]);
        $eventLogger->recordOnce(
            $actorToken,
            BehaviorEventType::DashboardViewed,
            $request,
            metadata: ['owned_plan_count' => $plans->count()],
        );
        $editablePlans = $plans->filter(fn ($plan) => $ownership->canEdit($request, $plan))->values();
        $collaborationPlans = $plans
            ->filter(fn ($plan) => (bool) $plan->is_collaborative)
            ->map(fn ($plan) => [
                'plan' => $plan,
                'role' => $ownership->role($request, $plan),
                'member_count' => 1 + $plan->memberships->count(),
            ])
            ->values();
        $baseline = $behaviorService->baseline($actorToken);
        $state = $stateService->calculate($actorToken, $baseline, $editablePlans);
        $stateService->captureDaily($actorToken, $state);
        $dashboard = $dashboardService->build(
            $plans,
            $actorToken,
            $baseline,
            $state,
            $request->session()->get('dashboard.recommendation_excluded', []),
            $editablePlans->pluck('id')->all(),
            $request->user(),
        );

        $continuity = $continuityService->forPlans($editablePlans, $actorToken);
        $dashboard['continuity'] = $continuity;
        $dashboard['calendar_week'] = $calendarService->weekSummary($plans);

        $primaryGuidance = $dashboard['guidance_deck']->first();

        if ($primaryGuidance) {
            $adaptive = $primaryGuidance['adaptive'];
            $eventLogger->recordOnce(
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

        return view('dashboard.index', compact('dashboard', 'collaborationPlans'));
    }

    public function legacy(Request $request, PlanProgressService $progressService, PlanOwnershipService $ownership)
    {
        $ownedPlans = $ownership->ownedPlans($request, ['tasks', 'workLogs']);

        $planProgressItems = $ownedPlans
            ->map(function (Plan $plan) use ($progressService) {
                return [
                    'plan' => $plan,
                    'progress' => $progressService->calculate($plan),
                ];
            });

        $myPlans = $planProgressItems->take(6);
        $dashboardJsonPlans = $ownedPlans->sortBy('title')->values();

        $inProgressTasks = $ownedPlans
            ->flatMap(function (Plan $plan) {
                return $plan->tasks
                    ->where('status', 'doing')
                    ->toBase()
                    ->map(function ($task) use ($plan) {
                        return [
                            'plan' => $plan,
                            'task' => $task,
                        ];
                    });
            })
            ->sort(function (array $left, array $right) {
                $priorityComparison = $left['task']->priority <=> $right['task']->priority;

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $leftDeadline = $left['plan']->deadline?->timestamp ?? PHP_INT_MAX;
                $rightDeadline = $right['plan']->deadline?->timestamp ?? PHP_INT_MAX;

                return $leftDeadline <=> $rightDeadline;
            })
            ->values();

        $dailyRequiredPlans = $planProgressItems
            ->filter(function (array $item) {
                $plan = $item['plan'];
                $progress = $item['progress'];

                $isCompleted = $progress['weighted_progress_percent'] >= 100
                    || ($plan->tasks->isNotEmpty()
                        && $plan->tasks->every(fn ($task) => in_array($task->status, ['done', 'cancelled'], true)));

                return ! $isCompleted
                    && $progress['remaining_days'] !== null
                    && $progress['remaining_days'] >= 0
                    && $progress['daily_required_minutes'] > 0;
            })
            ->sortByDesc(fn (array $item) => $item['progress']['daily_required_minutes'])
            ->values();

        $totalDailyRequiredMinutes = (int) $dailyRequiredPlans
            ->sum(fn (array $item) => $item['progress']['daily_required_minutes']);

        $dailyRequiredPlans = $dailyRequiredPlans
            ->map(function (array $item) use ($totalDailyRequiredMinutes) {
                $dailyRequiredMinutes = $item['progress']['daily_required_minutes'];

                $item['share_percent'] = $totalDailyRequiredMinutes > 0
                    ? round(($dailyRequiredMinutes / $totalDailyRequiredMinutes) * 100, 1)
                    : 0;

                return $item;
            });

        $recentWorkLogs = WorkLog::with(['plan', 'task'])
            ->whereIn('plan_id', $ownedPlans->pluck('id'))
            ->latest()
            ->take(6)
            ->get();

        $dashboardJsonPlanId = (int) $request->session()->get('dashboard_ai_json.plan_id', 0);
        $dashboardJsonPlan = $dashboardJsonPlanId > 0
            ? $ownedPlans->firstWhere('id', $dashboardJsonPlanId)
            : null;
        $dashboardJsonProposal = $dashboardJsonPlan
            ? $request->session()->get('plan_review_proposals.' . $dashboardJsonPlan->id)
            : null;
        $dashboardJsonPending = $request->session()->get('dashboard_ai_json.pending');

        if ($dashboardJsonPlanId > 0 && ! $dashboardJsonPlan) {
            $request->session()->forget([
                'dashboard_ai_json.plan_id',
                'dashboard_ai_json.pending',
            ]);
            $dashboardJsonPlan = null;
            $dashboardJsonProposal = null;
            $dashboardJsonPending = null;
        }

        if ($dashboardJsonPlanId > 0 && ! $dashboardJsonProposal && ! $dashboardJsonPending) {
            $request->session()->forget('dashboard_ai_json.plan_id');
            $dashboardJsonPlan = null;
        }

        return view('home', compact(
            'myPlans',
            'inProgressTasks',
            'dailyRequiredPlans',
            'totalDailyRequiredMinutes',
            'recentWorkLogs',
            'dashboardJsonPlan',
            'dashboardJsonProposal',
            'dashboardJsonPending',
            'dashboardJsonPlans'
        ));
    }
}
