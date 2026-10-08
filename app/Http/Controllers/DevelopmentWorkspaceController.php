<?php

namespace App\Http\Controllers;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Enums\WorkspaceMode;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\DevelopmentIntelligencePresentationAdapter;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentExecutionContextService;
use App\Services\DevelopmentImplementationBriefService;
use App\Services\DevelopmentHomeService;
use App\Services\DevelopmentCreativePlanAccessService;
use App\Services\DevelopmentWorkspaceSurfaceService;
use App\Services\CapabilityActivationService;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\GitHubRepositoryWriter;
use App\Services\GitHubWorkflowService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\PersonalizationLivingProfileService;
use App\Services\WorkspaceModeOnboardingService;
use App\Services\PersonalizationContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class DevelopmentWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentCreativePlanAccessService $creativeAccess,
        PlanPriorityService $priorities,
        DevelopmentAdaptiveActionService $developmentActions,
        DevelopmentIntelligencePresentationAdapter $presentationAdapter,
        DevelopmentHomeService $developerHome,
        DevelopmentWorkspaceSurfaceService $surfaces,
        DevelopmentExecutionContextService $executionContext,
        DevelopmentImplementationBriefService $implementationBriefs,
        GitHubIntegrationReadinessService $githubReadiness,
        GitHubRepositoryWriter $githubRepositoryReader,
        GitHubWorkflowService $githubWorkflow,
        IntelligencePresentationHistoryService $history,
        IntelligenceStateChangeFeedbackService $stateChanges,
        WorkspaceModeOnboardingService $onboarding,
        PersonalizationContextService $personalizationContexts,
        CapabilityActivationService $capabilityActivation,
        PersonalizationLivingProfileService $livingProfile,
    ) {
        $accessiblePlans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ]);
        // Creative Plans are NOT development by default. Only this actor's
        // explicitly selected, currently accessible Creative Plans may enter.
        $partition = $creativeAccess->partition($request, $accessiblePlans, $profiles);
        $developmentPlans = $partition['plans']->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();
        $developmentCreativeCandidates = $partition['candidates'];

        $plan = $this->selectedPlan($request, $developmentPlans);

        $developmentSurface = $surfaces->selected($request);
        $developmentSurfaceTabs = $surfaces->tabs();

        if (! $plan instanceof Plan) {
            return view('workspace.development.index', [
                'developmentPlans' => $developmentPlans,
                'developmentCreativeCandidates' => $developmentCreativeCandidates,
                'plan' => null,
                'firstUseContext' => data_get(
                    $personalizationContexts->current($request),
                    'domain_context.development',
                    [],
                ),
                'canEdit' => false,
                'developmentAdaptiveAction' => null,
                'intelligencePresentation' => null,
                'intelligenceHistory' => [],
                'hasReleaseEvidence' => false,
                'developmentFocusTask' => null,
                'developmentRecentActivity' => collect(),
                'developmentUnresolvedActivity' => collect(),
                'developmentAssociationTasks' => collect(),
                'developmentActiveTasks' => collect(),
                'developmentRecentCompletedTask' => null,
                'developmentCompletedTaskCount' => 0,
                'developmentRecentCompletedHasWorkLog' => false,
                'developmentExecutionContext' => null,
                'developmentImplementationBrief' => null,
                'developmentGithubRepository' => null,
                'developmentGithubConnection' => null,
                'developmentGithubIntegrationStatus' => null,
                'githubCapabilityActivation' => null,
                'intelligenceStateChange' => null,
                'developmentSurface' => $developmentSurface,
                'developmentSurfaceTabs' => $developmentSurfaceTabs,
                'developmentRepositoryTree' => null,
                'developmentRepositoryTreeError' => null,
                'developmentTeam' => null,
                'developmentImprovements' => [],
                'developmentPreview' => null,
                'canManage' => false,
                'modeOnboarding' => $onboarding->build(
                    WorkspaceMode::Development,
                    [],
                ),
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
        $home = $developerHome->build(
            $plan,
            $focusTaskId > 0 ? $focusTaskId : null,
        );
        $primaryAction = $adaptiveAction->primaryAction();
        $actionTaskId = (int) data_get(
            $primaryAction?->metadata,
            'target_task_id',
            0,
        );
        $contextTaskId = $actionTaskId > 0
            ? $actionTaskId
            : ($focusTaskId > 0 ? $focusTaskId : null);
        $developerExecutionContext = $executionContext->build(
            $plan,
            $contextTaskId,
            $primaryAction,
        );
        $developerImplementationBrief = $implementationBriefs->build(
            $developerExecutionContext,
            $primaryAction,
        );
        $developmentGithubRepository = $plan->artifacts()
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        $developmentGithubIntegrationStatus = $githubReadiness->forActor(
            $request->user(),
        );
        $developmentGithubConnection = $developmentGithubRepository
            ? $githubReadiness->connectionStatus(
                $developmentGithubIntegrationStatus,
                is_array(data_get(
                    $developmentGithubRepository->metadata,
                    'github_app_connection',
                ))
                    ? data_get(
                        $developmentGithubRepository->metadata,
                        'github_app_connection',
                    )
                    : [],
            )
            : null;

        if (
            $developmentGithubRepository
            && is_array($developmentGithubConnection)
        ) {
            $newlyCompleted = $capabilityActivation->syncGithubCompletion(
                $request,
                $plan,
                $developmentGithubRepository,
                $developmentGithubConnection,
            );

            if ($newlyCompleted) {
                $livingProfile->refresh(
                    $request,
                    'capability_readiness',
                    $plan,
                );
            }
        }

        $githubCapabilityActivation = $capabilityActivation->github(
            $request,
            $plan,
            null,
            $developmentGithubIntegrationStatus,
            null,
        );
        $canEdit = $ownership->canEdit($request, $plan);
        $canManage = $ownership->owns($request, $plan);
        $completedSteps = ['create_plan'];

        $developmentRepositoryTree = null;
        $developmentRepositoryTreeError = null;

        if (
            $developmentSurface === 'repository'
            && $developmentGithubRepository
            && (bool) data_get(
                $developmentGithubIntegrationStatus,
                'evidence.allowed',
                false,
            )
            && (string) data_get(
                $developmentGithubConnection,
                'state',
                '',
            ) === 'ready'
        ) {
            $parsedRepository = $githubWorkflow->parseUrl(
                (string) $developmentGithubRepository->url,
            );
            $repoFullName = trim((string) (
                $parsedRepository['repo_full_name']
                ?? ''
            ));

            if ($repoFullName !== '') {
                try {
                    $developmentRepositoryTree =
                        $githubRepositoryReader->inspectRepositoryTree(
                            $repoFullName,
                        );
                } catch (\RuntimeException $exception) {
                    $developmentRepositoryTreeError =
                        $exception->getMessage();
                }
            }
        }

        $developmentTeam = $developmentSurface === 'team'
            ? $surfaces->team($plan)
            : null;

        $developmentImprovements =
            $developmentSurface === 'improvements'
                ? $surfaces->improvements(
                    $adaptiveAction,
                    $home['unresolved_activity'],
                    $home['active_tasks'],
                    is_array($developmentGithubConnection)
                        ? $developmentGithubConnection
                        : [],
                )
                : [];

        $developmentPreview = $developmentSurface === 'preview'
            ? $surfaces->preview($plan)
            : null;

        return view('workspace.development.index', [
            'developmentPlans' => $developmentPlans,
                'developmentCreativeCandidates' => $developmentCreativeCandidates,
            'plan' => $plan,
            'firstPlanContext' => $plan->tasks->isEmpty()
                ? data_get(
                    $personalizationContexts->current($request),
                    'domain_context.development',
                    [],
                )
                : [],
            'canEdit' => $canEdit,
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
            'developmentRecentActivity' => $home['recent_activity'],
            'developmentUnresolvedActivity' => $home['unresolved_activity'],
            'developmentAssociationTasks' => $home['association_tasks'],
            'developmentActiveTasks' => $home['active_tasks'],
            'developmentRecentCompletedTask' => $home['recent_completed_task'],
            'developmentCompletedTaskCount' => $home['completed_task_count'],
            'developmentRecentCompletedHasWorkLog' => $home['recent_completed_has_work_log'],
            'developmentExecutionContext' => $developerExecutionContext,
            'developmentImplementationBrief' => $developerImplementationBrief,
            'developmentGithubRepository' => $developmentGithubRepository,
            'developmentGithubConnection' => $developmentGithubConnection,
            'developmentGithubIntegrationStatus' => $developmentGithubIntegrationStatus,
            'githubCapabilityActivation' => $githubCapabilityActivation,
            'developmentSurface' => $developmentSurface,
            'developmentSurfaceTabs' => $developmentSurfaceTabs,
            'developmentRepositoryTree' => $developmentRepositoryTree,
            'developmentRepositoryTreeError' => $developmentRepositoryTreeError,
            'developmentTeam' => $developmentTeam,
            'developmentImprovements' => $developmentImprovements,
            'developmentPreview' => $developmentPreview,
            'canManage' => $canManage,
            'intelligenceStateChange' => $stateChanges->latestForPlan(
                $plan,
                IntelligenceDomain::Development,
            ),
            'modeOnboarding' => $onboarding->build(
                WorkspaceMode::Development,
                $completedSteps,
                $plan,
                $presentation,
                $canEdit,
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
