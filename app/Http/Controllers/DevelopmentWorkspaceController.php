<?php

namespace App\Http\Controllers;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\DevelopmentIntelligencePresentationAdapter;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class DevelopmentWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
        DevelopmentAdaptiveActionService $developmentActions,
        DevelopmentIntelligencePresentationAdapter $presentationAdapter,
        IntelligencePresentationHistoryService $history,
        IntelligenceStateChangeFeedbackService $stateChanges,
    ) {
        $developmentPlans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ])->filter(
            fn (Plan $plan) => $profiles->forPlan($plan)->key === 'development',
        )->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();

        $plan = $this->selectedPlan($request, $developmentPlans);

        if (! $plan instanceof Plan) {
            return view('workspace.development.index', [
                'developmentPlans' => $developmentPlans,
                'plan' => null,
                'canEdit' => false,
                'developmentAdaptiveAction' => null,
                'intelligencePresentation' => null,
                'intelligenceHistory' => [],
                'hasReleaseEvidence' => false,
                'developmentFocusTask' => null,
                'intelligenceStateChange' => null,
            ]);
        }

        $adaptiveAction = $developmentActions->evaluate($plan);
        $presentation = $presentationAdapter->adapt(
            $plan,
            $adaptiveAction,
        );
        $focusState = data_get(
            $adaptiveAction->intelligence->state->facts,
            'focus_task_state',
        );
        $focusState = is_array($focusState) ? $focusState : null;
        $focusTaskId = (int) data_get($focusState, 'task_id', 0);

        return view('workspace.development.index', [
            'developmentPlans' => $developmentPlans,
            'plan' => $plan,
            'canEdit' => $ownership->canEdit($request, $plan),
            'developmentAdaptiveAction' => $adaptiveAction,
            'intelligencePresentation' => $presentation,
            'intelligenceHistory' => $history->forPlan(
                $plan,
                IntelligenceDomain::Development,
            ),
            'hasReleaseEvidence' => $focusState !== null,
            'developmentFocusTask' => $focusTaskId > 0
                ? $this->task($plan, $focusTaskId)
                : null,
            'intelligenceStateChange' => $stateChanges->latestForPlan(
                $plan,
                IntelligenceDomain::Development,
            ),
        ]);
    }

    /**
     * @param Collection<int,Plan> $developmentPlans
     */
    private function selectedPlan(
        Request $request,
        Collection $developmentPlans,
    ): ?Plan {
        if (! $request->query->has('plan_id')) {
            $plan = $developmentPlans->first();

            return $plan instanceof Plan ? $plan : null;
        }

        $requestedId = filter_var(
            $request->query('plan_id'),
            FILTER_VALIDATE_INT,
        );

        abort_if($requestedId === false || (int) $requestedId <= 0, 404);

        $plan = $developmentPlans->firstWhere('id', (int) $requestedId);

        abort_unless($plan instanceof Plan, 404);

        return $plan;
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

    private function task(Plan $plan, int $taskId): ?Task
    {
        $task = $plan->relationLoaded('tasks')
            ? $plan->tasks->firstWhere('id', $taskId)
            : $plan->tasks()->whereKey($taskId)->first();

        return $task instanceof Task ? $task : null;
    }
}
