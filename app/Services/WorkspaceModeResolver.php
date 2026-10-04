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
    ) {}

    /**
     * V54.0 resolves current Workspace context but does not persist a manual
     * choice yet. V54.2 will own persistence and precedence over auto context.
     */
    public function resolve(
        Request $request,
        ?WorkspaceMode $explicit = null,
    ): WorkspaceModeContextData {
        $routeName = $request->route()?->getName();

        if ($explicit instanceof WorkspaceMode) {
            return new WorkspaceModeContextData(
                mode: $explicit,
                source: WorkspaceModeSource::Explicit,
                routeName: $routeName,
            );
        }

        $routeMode = $this->routeHint($routeName);
        if ($routeMode instanceof WorkspaceMode) {
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

        return new WorkspaceModeContextData(
            mode: WorkspaceMode::Overview,
            source: WorkspaceModeSource::Default,
            routeName: $routeName,
        );
    }

    private function routeHint(?string $routeName): ?WorkspaceMode
    {
        $routeName = trim((string) $routeName);

        if ($routeName === '') {
            return null;
        }

        if (
            str_starts_with($routeName, 'plans.study_scope.')
            || str_starts_with($routeName, 'plans.study_action.')
            || str_starts_with($routeName, 'plans.tasks.study_')
        ) {
            return WorkspaceMode::Study;
        }

        if (
            str_starts_with($routeName, 'github_workflow.')
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
