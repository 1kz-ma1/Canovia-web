<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMode;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\CareerIntelligencePresentationAdapter;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\WorkspaceModeOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class CareerModeWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
        CareerAdaptiveActionService $careerActions,
        CareerIntelligencePresentationAdapter $presentationAdapter,
        IntelligencePresentationHistoryService $history,
        IntelligenceStateChangeFeedbackService $stateChanges,
        WorkspaceModeOnboardingService $onboarding,
    ) {
        $careerPlans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ])->filter(
            fn (Plan $plan) =>
                $profiles->forPlan($plan)->key === 'career',
        )->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();

        $plan = $this->selectedPlan($request, $careerPlans);

        if (! $plan instanceof Plan) {
            return view('workspace.career.index', [
                'careerPlans' => $careerPlans,
                'plan' => null,
                'canEdit' => false,
                'careerAdaptiveAction' => null,
                'intelligencePresentation' => null,
                'intelligenceHistory' => [],
                'hasCareerSignal' => false,
                'intelligenceStateChange' => null,
                'modeOnboarding' => $onboarding->build(
                    WorkspaceMode::Career,
                    [],
                ),
            ]);
        }

        $adaptiveAction = $careerActions->evaluate($plan);
        $presentation = $presentationAdapter->adapt(
            $plan,
            $adaptiveAction,
        );
        $hasCareerSignal = (bool) data_get(
            $adaptiveAction->intelligence->state->facts,
            'has_career_signal',
            false,
        );
        $canEdit = $ownership->canEdit($request, $plan);
        $completedSteps = ['create_plan'];

        if ($hasCareerSignal) {
            $completedSteps[] = 'capture_career_signal';
        }

        return view('workspace.career.index', [
            'careerPlans' => $careerPlans,
            'plan' => $plan,
            'canEdit' => $canEdit,
            'careerAdaptiveAction' => $adaptiveAction,
            'intelligencePresentation' => $presentation,
            'intelligenceHistory' => $history->forPlan(
                $plan,
                IntelligenceDomain::Career,
            ),
            'hasCareerSignal' => $hasCareerSignal,
            'intelligenceStateChange' => $stateChanges->latestForPlan(
                $plan,
                IntelligenceDomain::Career,
            ),
            'modeOnboarding' => $onboarding->build(
                WorkspaceMode::Career,
                $completedSteps,
                $plan,
                $presentation,
                $canEdit,
            ),
        ]);
    }

    /**
     * @param Collection<int,Plan> $careerPlans
     */
    private function selectedPlan(
        Request $request,
        Collection $careerPlans,
    ): ?Plan {
        if (! $request->query->has('plan_id')) {
            $plan = $careerPlans->first();

            return $plan instanceof Plan ? $plan : null;
        }

        $requestedId = filter_var(
            $request->query('plan_id'),
            FILTER_VALIDATE_INT,
        );

        abort_if(
            $requestedId === false || (int) $requestedId <= 0,
            404,
        );

        $plan = $careerPlans->firstWhere('id', (int) $requestedId);

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
}
