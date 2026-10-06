<?php

namespace App\Http\Controllers;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentCodingAgentHandoffService;
use App\Services\DevelopmentExecutionContextService;
use App\Services\DevelopmentImplementationBriefService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DevelopmentCodingAgentHandoffController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentAdaptiveActionService $developmentActions,
        DevelopmentExecutionContextService $executionContext,
        DevelopmentImplementationBriefService $briefs,
        DevelopmentCodingAgentHandoffService $handoff,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless(
            $profiles->forPlan($plan)->key === 'development',
            404,
        );

        if (
            in_array($task->status, ['done', 'cancelled'], true)
            || (int) $task->progress_percent >= 100
        ) {
            return redirect()
                ->route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ])
                ->withErrors([
                    'coding_agent_handoff' =>
                        '完了・中止済みTaskはCoding Agentへ新規handoffしません。',
                ]);
        }

        $validated = $request->validate([
            'confirmed' => ['accepted'],
            'available_minutes' => [
                'nullable',
                'integer',
                'min:5',
                'max:1440',
            ],
        ], [
            'confirmed.accepted' =>
                'Coding Agentへ渡す範囲を確認してください。',
        ]);

        $adaptive = $developmentActions->evaluate($plan);
        $action = $adaptive->primaryAction();
        $actionTaskId = (int) data_get(
            $action?->metadata,
            'target_task_id',
            0,
        );

        if (
            $actionTaskId > 0
            && $actionTaskId !== (int) $task->id
        ) {
            throw ValidationException::withMessages([
                'coding_agent_handoff' =>
                    '現在のDevelopment Actionが対象にしているTaskからhandoffしてください。',
            ]);
        }

        $context = $executionContext->build(
            $plan,
            (int) $task->id,
            $action,
        );

        if (
            ! is_array($context)
            || (int) data_get($context, 'task.id', 0)
                !== (int) $task->id
        ) {
            throw ValidationException::withMessages([
                'coding_agent_handoff' =>
                    '現在TaskのDevelopment Contextを確認できませんでした。',
            ]);
        }

        $brief = $briefs->build($context, $action);

        if (
            ! is_array($brief)
            || (int) data_get($brief, 'target.task_id', 0)
                !== (int) $task->id
        ) {
            throw ValidationException::withMessages([
                'coding_agent_handoff' =>
                    '現在TaskのImplementation Briefを確認できませんでした。',
            ]);
        }

        $availableMinutes = isset($validated['available_minutes'])
            ? (int) $validated['available_minutes']
            : null;

        $handoff->prepare(
            $request,
            $plan,
            $task,
            $brief,
            $availableMinutes,
        );

        return redirect()
            ->route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            )
            ->with(
                'success',
                '確認済みImplementation BriefからCoding Agent向けExecution Contextを準備しました。まだAgent実行やGitHub writeは行っていません。',
            );
    }
}
