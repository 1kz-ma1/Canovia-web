<?php

namespace App\Http\Controllers;

use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Intelligence\Presentation\StudyIntelligencePresentationAdapter;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Intelligence\Study\StudyWorkspaceRecommendationService;
use App\Intelligence\Study\StudyWorkspaceStateResolver;
use App\Intelligence\Study\StudyWorkspaceSurfacePolicy;
use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\WorkspaceModeOnboardingService;
use App\Services\ExecutionSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class StudyWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
        StudyAdaptiveActionService $studyActions,
        StudyLearningTypeRouter $learningTypes,
        StudyWorkspaceRecommendationService $recommendations,
        StudyWorkspaceStateResolver $studyState,
        StudyWorkspaceSurfacePolicy $surfacePolicy,
        BehaviorIdentityService $identity,
        ExecutionSetupService $executionSetup,
        StudyIntelligencePresentationAdapter $presentationAdapter,
        IntelligencePresentationHistoryService $history,
        IntelligenceStateChangeFeedbackService $stateChanges,
        WorkspaceModeOnboardingService $onboarding,
    ) {
        $studyPlans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ])->filter(
            fn (Plan $plan) => $profiles->forPlan($plan)->key === 'study',
        )->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();

        $plan = $this->selectedPlan($request, $studyPlans);

        if (! $plan instanceof Plan) {
            return view('workspace.study.index', [
                'studyPlans' => $studyPlans,
                'plan' => null,
                'canEdit' => false,
                'studyAdaptiveAction' => null,
                'intelligencePresentation' => null,
                'intelligenceHistory' => [],
                'hasConfirmedScope' => false,
                'navigationTask' => null,
                'intelligenceStateChange' => null,
                'executionSetup' => null,
                'studyLearningType' => null,
                'studyWorkspaceState' => null,
                'studyWorkspaceRecommendation' => null,
                'studyWorkspaceComposition' => null,
                'modeOnboarding' => $onboarding->build(
                    WorkspaceMode::Study,
                    [],
                ),
            ]);
        }

        $adaptiveAction = $studyActions->evaluate($plan);
        $presentation = $presentationAdapter->adapt(
            $plan,
            $adaptiveAction,
        );
        $hasConfirmedScope = (int) data_get(
            $adaptiveAction->intelligence->state->metrics,
            'confirmed_scope_count',
            0,
        ) > 0;
        $canEdit = $ownership->canEdit($request, $plan);
        $navigationTask = $this->navigationTask(
            $plan,
            $presentation?->targetTask,
        );
        $learningType = $learningTypes->route($plan);
        $resolvedState = $studyState->resolve($plan, $adaptiveAction);
        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);
        $recommendation = $navigationTask instanceof Task
            ? $recommendations->recommend(
                $plan,
                $navigationTask,
                $request->user()?->id,
                $actorToken,
            )
            : null;
        $composition = $surfacePolicy->compose(
            $plan,
            $learningType,
            $resolvedState,
            $presentation,
            $navigationTask,
            $canEdit,
            $recommendation,
        );

        // Existing Study Plans no longer pass through a fixed Scope/Evidence
        // onboarding sequence. State First composition owns the next surface.
        $modeOnboarding = $onboarding->build(
            WorkspaceMode::Study,
            ['create_plan'],
            $plan,
            $presentation,
            $canEdit,
        );

        $executionSetupData = (
            ! (bool) ($composition['blocks_execution'] ?? false)
            && $navigationTask instanceof Task
        )
            ? $executionSetup->inspect($plan, $navigationTask)
            : null;

        return view('workspace.study.index', [
            'studyPlans' => $studyPlans,
            'plan' => $plan,
            'canEdit' => $canEdit,
            'studyAdaptiveAction' => $adaptiveAction,
            'intelligencePresentation' => $presentation,
            'intelligenceHistory' => $history->forPlan(
                $plan,
                IntelligenceDomain::Study,
            ),
            'hasConfirmedScope' => $hasConfirmedScope,
            'navigationTask' => $navigationTask,
            'intelligenceStateChange' => $stateChanges->latestForPlan(
                $plan,
                IntelligenceDomain::Study,
            ),
            'executionSetup' => $executionSetupData,
            'studyLearningType' => $learningType,
            'studyWorkspaceState' => $resolvedState,
            'studyWorkspaceRecommendation' => $recommendation,
            'studyWorkspaceComposition' => $composition,
            'modeOnboarding' => $modeOnboarding,
        ]);
    }

    /**
     * @param Collection<int,Plan> $studyPlans
     */
    private function selectedPlan(
        Request $request,
        Collection $studyPlans,
    ): ?Plan {
        if (! $request->query->has('plan_id')) {
            $plan = $studyPlans->first();

            return $plan instanceof Plan ? $plan : null;
        }

        $requestedId = filter_var(
            $request->query('plan_id'),
            FILTER_VALIDATE_INT,
        );

        abort_if($requestedId === false || (int) $requestedId <= 0, 404);

        $plan = $studyPlans->firstWhere('id', (int) $requestedId);

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

    private function navigationTask(
        Plan $plan,
        ?Task $targetTask,
    ): ?Task {
        if ($targetTask instanceof Task) {
            return $targetTask;
        }

        $tasks = $plan->relationLoaded('tasks')
            ? $plan->tasks
            : $plan->tasks()->get();

        $active = $tasks
            ->sortBy([
                ['priority', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first(
                fn (Task $task) => ! in_array(
                    (string) $task->status,
                    ['done', 'completed', 'cancelled'],
                    true,
                ),
            );

        if ($active instanceof Task) {
            return $active;
        }

        $task = $tasks
            ->sortBy([
                ['priority', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->first();

        return $task instanceof Task ? $task : null;
    }
}
