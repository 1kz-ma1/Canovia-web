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
use Illuminate\Support\Facades\DB;
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

    /**
     * A reflection is only a suggestion until its author explicitly confirms
     * it as the current Task's next action. No progress or Evidence mutation.
     */
    public function confirmNextAction(
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
            'expected_next_action_hash' => ['required', 'regex:/\\A[a-f0-9]{64}\\z/'],
        ]);

        DB::transaction(function () use ($request, $plan, $task, $guidedExecution, $identity, $guided, $validated): void {
            // All source-visible Task mutations must serialize behind the
            // parent Plan lock (same order as Learning Context Watch capture).
            // The route-bound Plan/Task and prior authorization can be stale.
            $lockedPlan = Plan::query()->whereKey($plan->id)
                ->lockForUpdate()->firstOrFail();
            $currentTask = Task::query()->where('plan_id', $lockedPlan->id)
                ->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $currentTask->setRelation('plan', $lockedPlan);

            // Re-read owner/editor rights rather than reusing the service's
            // request-local role cache from the initial route authorization.
            $this->authorizeTask(
                $request, $lockedPlan, $currentTask, new PlanOwnershipService(),
            );
            $latest = $guided->latestCompletedFor(
                $currentTask,
                $request->user()?->id,
                $identity->resolve($request),
            );

            // A stale page or someone else's reflection must never replace
            // the current action, even if a real Evidence row exists.
            abort_unless($latest && (int) $latest->id === (int) $guidedExecution->id, 409);
            abort_unless(
                $latest->status === GuidedExecution::STATUS_COMPLETED
                && $latest->task_evidence_id !== null
                && $latest->evidence !== null
                && (int) $latest->evidence->task_id === (int) $currentTask->id
                && $latest->evidence->type === 'guided_execution_reflected',
                409,
            );

            $nextAction = trim((string) ($latest->next_adjustment ?? ''));
            abort_unless($nextAction !== '' && mb_strlen($nextAction) <= 1000, 422);
            abort_if(
                in_array($currentTask->status, ['done', 'cancelled'], true)
                || (int) $currentTask->progress_percent >= 100,
                409,
            );

            // An exact retry can succeed without modifying an already-set
            // action. A modified Task fails closed rather than overwriting it.
            if ((string) ($currentTask->next_action_note ?? '') === $nextAction) {
                return;
            }

            abort_unless(
                hash_equals(
                    hash('sha256', (string) ($currentTask->next_action_note ?? '')),
                    $validated['expected_next_action_hash'],
                ),
                409,
            );

            $currentTask->update(['next_action_note' => $nextAction]);
        });

        return redirect()
            ->route('plans.tasks.guided_execution.show', [$plan, $task])
            ->with('success', '振り返りで決めた次の行動をTaskへ反映しました。進捗率は変更していません。');
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
