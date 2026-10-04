<?php

namespace App\Http\Controllers;

use App\Intelligence\Study\StudyActionTaskProjector;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\PlanActivityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\ExecutionLaunchResolver;
use Illuminate\Http\Request;

class StudyAdaptiveActionController extends Controller
{
    public function execute(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        StudyAdaptiveActionService $actions,
        StudyActionTaskProjector $projector,
        PlanActivityService $activity,
        ExecutionLaunchResolver $launches,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $result = $actions->refresh($plan, now());
        $action = $result->primaryAction();

        abort_unless($action !== null, 409);

        $routeKind = (string) data_get(
            $action->metadata,
            'route_kind',
            'study_scope',
        );

        if ($routeKind === 'study_scope') {
            return redirect()
                ->route('plans.study_scope.index', $plan)
                ->with('status', $action->intent);
        }

        $targetTask = $this->targetTask($plan, $action->metadata);

        if (
            $targetTask
            && in_array($routeKind, ['study_practice', 'study_recall'], true)
        ) {
            $launch = $launches->forStudyAction(
                $plan,
                $targetTask,
                $routeKind === 'study_recall'
                    ? 'plans.tasks.study_recall.show'
                    : 'plans.tasks.study_practice.show',
            );

            return redirect()->route(
                $launch->routeName,
                $launch->parameters,
            );
        }

        $projection = $projector->project($plan, $result);
        $task = $projection['task'];

        if ($projection['created']) {
            $activity->record(
                $plan,
                $request->user(),
                'task_created',
                'task',
                (int) $task->id,
                [
                    'task_title' => $task->title,
                    'source' => 'intelligence_action_projection',
                ],
            );

            // Task projection changes execution reality. Re-evaluate immediately
            // so the persisted current Action no longer says a Task must be created.
            $actions->tryRefresh($plan, now());
        }

        $launch = $launches->forStudyAction(
            $plan,
            $task,
            'plans.tasks.study_practice.show',
        );

        return redirect()
            ->route($launch->routeName, $launch->parameters)
            ->with(
                'status',
                $projection['created']
                    ? '今のActionを実行できる形としてTaskへ投影しました。学習結果は引き続きEvidenceから判断します。'
                    : '今のActionに対応する既存Taskで進めます。',
            );
    }

    private function targetTask(Plan $plan, array $metadata): ?Task
    {
        $taskId = filter_var(
            $metadata['target_task_id'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($taskId === false || (int) $taskId <= 0) {
            return null;
        }

        return Task::query()
            ->where('plan_id', $plan->id)
            ->whereKey((int) $taskId)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->where('progress_percent', '<', 100)
            ->first();
    }
}
