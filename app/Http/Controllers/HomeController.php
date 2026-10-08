<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\WorkLog;
use App\Services\HomePageDataService;
use App\Services\PlanOwnershipService;
use App\Services\PlanProgressService;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(
        Request $request,
        HomePageDataService $page,
    ) {
        return view(
            'dashboard.index',
            $page->build(
                $request,
                prefetch: $request->header('X-Canovia-Instant-Navigation') === 'prefetch',
            ),
        );
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
