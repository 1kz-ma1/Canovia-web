<?php

namespace App\Services;

use App\Data\WorkspaceModeContextData;
use App\Enums\WorkspaceMode;
use App\Enums\WorkspaceModeSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\WorkSession;
use Illuminate\Http\Request;

final class WorkspaceModeResolver
{
    public function __construct(
        private readonly WorkspaceModeRegistry $registry,
        private readonly PlanCategoryProfileService $profiles,
        private readonly WorkspaceModePreference $preference,
    ) {}

    /**
     * Workspace context resolution keeps explicit/deep-link semantics above
     * manual preference, then uses the persisted preference for contextless
     * surfaces before falling back to Overview.
     */
    public function resolve(
        Request $request,
        ?WorkspaceMode $explicit = null,
    ): WorkspaceModeContextData {
        $routeName = $request->route()?->getName();

        // Home is always the global overview, independent of the last Workspace.
        if ($routeName === 'home') {
            return new WorkspaceModeContextData(
                mode: WorkspaceMode::Overview,
                source: WorkspaceModeSource::Default,
                routeName: $routeName,
            );
        }

        if (
            $explicit instanceof WorkspaceMode
            && $this->isPublic($explicit)
        ) {
            return new WorkspaceModeContextData(
                mode: $explicit,
                source: WorkspaceModeSource::Explicit,
                routeName: $routeName,
            );
        }

        $queryMode = $this->queryMode($request);
        if ($queryMode instanceof WorkspaceMode) {
            return new WorkspaceModeContextData(
                mode: $queryMode,
                source: WorkspaceModeSource::Explicit,
                routeName: $routeName,
            );
        }

        $routeMode = $this->routeHint($routeName);
        if (
            $routeMode instanceof WorkspaceMode
            && $this->isPublic($routeMode)
        ) {
            $plan = $this->routePlan($request);

            return new WorkspaceModeContextData(
                mode: $routeMode,
                source: WorkspaceModeSource::RouteHint,
                planId: $plan?->id,
                profileKey: $plan
                    ? $this->profiles->forPlan($plan)->key
                    : null,
                routeName: $routeName,
            );
        }

        $plan = $this->routePlan($request);
        if ($plan instanceof Plan) {
            $profile = $this->profiles->forPlan($plan);
            $definition = $this->registry->forProfile($profile->key);

            if ($definition) {
                return new WorkspaceModeContextData(
                    mode: $definition->mode,
                    source: WorkspaceModeSource::PlanProfile,
                    planId: (int) $plan->id,
                    profileKey: $profile->key,
                    routeName: $routeName,
                );
            }

            return new WorkspaceModeContextData(
                mode: WorkspaceMode::Overview,
                source: WorkspaceModeSource::PlanProfile,
                planId: (int) $plan->id,
                profileKey: $profile->key,
                routeName: $routeName,
            );
        }

        $manualMode = $this->preference->selected($request);
        if ($manualMode instanceof WorkspaceMode) {
            return new WorkspaceModeContextData(
                mode: $manualMode,
                source: WorkspaceModeSource::ManualPreference,
                routeName: $routeName,
            );
        }

        return new WorkspaceModeContextData(
            mode: WorkspaceMode::Overview,
            source: WorkspaceModeSource::Default,
            routeName: $routeName,
        );
    }

    private function queryMode(Request $request): ?WorkspaceMode
    {
        $value = mb_strtolower(trim((string) $request->query(
            'workspace_mode',
            '',
        )));

        if ($value === '') {
            return null;
        }

        $mode = WorkspaceMode::tryFrom($value);

        return $mode && $this->isPublic($mode)
            ? $mode
            : null;
    }

    private function isPublic(WorkspaceMode $mode): bool
    {
        return in_array(
            $mode->value,
            $this->registry->publicKeys(),
            true,
        );
    }

    private function routeHint(?string $routeName): ?WorkspaceMode
    {
        $routeName = trim((string) $routeName);

        if ($routeName === '') {
            return null;
        }

        if (str_starts_with($routeName, 'workspace.overview.')) {
            return WorkspaceMode::Overview;
        }

        if (
            str_starts_with($routeName, 'workspace.study.')
            || str_starts_with($routeName, 'plans.study_scope.')
            || str_starts_with($routeName, 'plans.study_action.')
            || str_starts_with($routeName, 'plans.tasks.study_')
        ) {
            return WorkspaceMode::Study;
        }

        if (str_starts_with($routeName, 'workspace.career.')
            || str_starts_with($routeName, 'plans.career.')
        ) {
            return WorkspaceMode::Career;
        }

        if (
            str_starts_with($routeName, 'workspace.development.')
            || str_starts_with($routeName, 'github_workflow.')
            || str_starts_with($routeName, 'plans.development_readiness.')
            || str_starts_with(
                $routeName,
                'plans.tasks.execution_orchestration.github.',
            )
        ) {
            return WorkspaceMode::Development;
        }

        return null;
    }

    private function routePlan(Request $request): ?Plan
    {
        $plan = $request->route('plan');
        if ($plan instanceof Plan) {
            return $plan;
        }

        $task = $request->route('task');
        if ($task instanceof Task) {
            return $task->relationLoaded('plan')
                ? $task->plan
                : $task->plan()->first();
        }

        $workSession = $request->route('workSession');
        if ($workSession instanceof WorkSession) {
            if ((int) ($workSession->plan_id ?? 0) > 0) {
                return Plan::query()->find((int) $workSession->plan_id);
            }

            $sessionTask = $workSession->relationLoaded('task')
                ? $workSession->task
                : $workSession->task()->first();

            return $sessionTask?->plan;
        }

        return null;
    }
}
