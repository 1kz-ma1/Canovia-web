<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\SpecializedWorkspaceTopService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class StudyWorkspaceTopController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
        SpecializedWorkspaceTopService $tops,
    ) {
        $plans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
            'resources',
            'studyScopeCaptures.items',
        ])->filter(
            fn (Plan $plan) => $profiles->forPlan($plan)->key === 'study',
        )->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();

        $summaries = $tops->summarize($plans)->map(
            function (array $summary) use ($ownership, $request) {
                /** @var Plan $plan */
                $plan = $summary['plan'];

                $confirmedScopeCount = $plan->studyScopeCaptures
                    ->where('status', 'confirmed')
                    ->sum(
                        fn ($capture) => $capture->items->count(),
                    );

                return [
                    ...$summary,
                    'can_edit' => $ownership->canEdit(
                        $request,
                        $plan,
                    ),
                    'confirmed_scope_count' =>
                        (int) $confirmedScopeCount,
                    'resource_count' => $plan->resources->count(),
                ];
            },
        )->values();

        return view('workspace.study.top', [
            'studyPlans' => $plans,
            'studyPlanSummaries' => $summaries,
        ]);
    }

    private function comparePlans(
        Plan $left,
        Plan $right,
        PlanPriorityService $priorities,
    ): int {
        $priority = (int) data_get(
            $priorities->evaluate($left),
            'priority',
            5,
        ) <=> (int) data_get(
            $priorities->evaluate($right),
            'priority',
            5,
        );

        if ($priority !== 0) {
            return $priority;
        }

        $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
            <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

        if ($deadline !== 0) {
            return $deadline;
        }

        return ((int) $left->id) <=> ((int) $right->id);
    }
}
