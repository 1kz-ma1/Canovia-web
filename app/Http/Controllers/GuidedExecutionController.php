<?php

namespace App\Http\Controllers;

use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\GuidedExecutionPolicyService;
use App\Services\GuidedExecutionService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuidedExecutionController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        GuidedExecutionService $guided,
        GuidedExecutionPolicyService $policy,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $actorToken = $identity->resolve($request);
        $userId = $request->user()?->id;

        return view('guided_execution.show', [
            'plan' => $plan,
            'task' => $task,
            'assessment' => $policy->assess($plan, $task),
            'activeExecution' => $guided->activeFor($task, $userId, $actorToken),
            'latestCompleted' => $guided->latestCompletedFor($task, $userId, $actorToken),
            'prepareRequestId' => (string) Str::uuid(),
            'reflectionRequestId' => (string) Str::uuid(),
        ]);
    }

    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        GuidedExecutionService $guided,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        if (in_array($task->status, ['done', 'cancelled'], true) || (int) $task->progress_percent >= 100) {
            throw ValidationException::withMessages([
                'intent' => '完了または中止済みのTaskでは新しい実行方針を作れません。',
            ]);
        }

        $validated = $request->validate([
            'intent' => ['required', 'string', 'min:2', 'max:1000'],
            'focus_points' => ['nullable', 'string', 'max:4000'],
            'observation_points' => ['nullable', 'string', 'max:4000'],
            'success_signal' => ['nullable', 'string', 'max:1000'],
            'prepare_request_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);

        $guided->prepare(
            $task,
            $validated,
            $request->user()?->id,
            $identity->resolve($request),
        );

        return redirect()
            ->route('plans.tasks.guided_execution.show', [$plan, $task])
            ->with('success', '今回の方針を決めました。時間を測らず、そのまま現実で実行して大丈夫です。');
    }

    public function reflect(
        Request $request,
        Plan $plan,
        Task $task,
        GuidedExecution $guidedExecution,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        GuidedExecutionService $guided,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        abort_unless(
            (int) $guidedExecution->plan_id === (int) $plan->id
            && (int) $guidedExecution->task_id === (int) $task->id,
            404,
        );

        $validated = $request->validate([
            'outcome_rating' => ['required', Rule::in(GuidedExecution::OUTCOME_RATINGS)],
            'actual_outcome' => ['required', 'string', 'min:2', 'max:4000'],
            'observations' => ['nullable', 'string', 'max:4000'],
            'discoveries' => ['nullable', 'string', 'max:4000'],
            'next_adjustment' => ['nullable', 'string', 'max:4000'],
            'reflection_request_id' => ['nullable', 'uuid'],
        ]);

        $guided->reflect(
            $guidedExecution,
            $validated,
            $request->user()?->id,
            $identity->resolve($request),
        );

        return redirect()
            ->route('plans.tasks.guided_execution.show', [$plan, $task])
            ->with('success', '振り返りをEvidenceとして保存しました。Task進捗は自動変更していません。');
    }

    public function cancel(
        Request $request,
        Plan $plan,
        Task $task,
        GuidedExecution $guidedExecution,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        GuidedExecutionService $guided,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        abort_unless(
            (int) $guidedExecution->plan_id === (int) $plan->id
            && (int) $guidedExecution->task_id === (int) $task->id,
            404,
        );

        $guided->cancel(
            $guidedExecution,
            $request->user()?->id,
            $identity->resolve($request),
        );

        return redirect()
            ->route('plans.tasks.guided_execution.show', [$plan, $task])
            ->with('status', '今回の方針を取り消しました。');
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ): void {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
    }
}
