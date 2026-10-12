<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * G3-R1: Native Execution Packets are session projections. Provider success
 * is not authority to retain or reuse a Packet after its inputs change.
 */
final class ExecutionPacketCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly ExecutionOrchestrationContextService $contexts,
    ) {}

    /** @param array<string,mixed> $expectedContext */
    public function assertFresh(
        Request $request,
        Plan $plan,
        Task $task,
        array $expectedContext,
        ?int $runId = null,
    ): void {
        DB::transaction(function () use ($request, $plan, $task, $expectedContext, $runId): void {
            $currentPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $currentTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();
            $userId = $request->user()?->id;
            $planId = (int) $plan->id;
            $taskId = (int) $task->id;

            if (
                $currentPlan === null || $currentTask === null
                || (int) $currentTask->plan_id !== $planId
                || (int) data_get($expectedContext, 'plan.id') !== $planId
                || (int) data_get($expectedContext, 'selected_node.id') !== $taskId
                || $userId === null || $userId <= 0
            ) {
                $this->deny('native_ai_target_changed', $runId);
            }

            $canEdit = (int) $currentPlan->user_id === (int) $userId
                || ($currentPlan->is_collaborative && PlanMember::query()
                    ->where('plan_id', $planId)
                    ->where('user_id', $userId)
                    ->where('role', PlanMember::ROLE_EDITOR)
                    ->exists());

            if (! $canEdit
                || in_array($currentTask->status, ['done', 'cancelled'], true)
                || (int) $currentTask->progress_percent >= 100
            ) {
                $this->deny('native_ai_target_changed', $runId);
            }

            $failure = $this->purposeGuard->failure(
                $userId, $planId, $taskId, FeatureKey::AutomaticAiExecution,
            );
            if ($failure !== null) {
                $this->deny($failure, $runId);
            }

            $expected = (string) ($expectedContext['context_fingerprint'] ?? '');
            if ($expected === '') {
                $this->deny('native_ai_source_changed', $runId);
            }

            try {
                $liveContext = $this->contexts->freshSnapshot($request, $currentPlan, $currentTask);
            } catch (Throwable) {
                $this->deny('native_ai_target_changed', $runId);
            }

            $actual = (string) ($liveContext['context_fingerprint'] ?? '');
            if ($actual === '' || ! hash_equals($expected, $actual)) {
                $this->deny('native_ai_source_changed', $runId);
            }
        }, 3);
    }

    /** @param array<string,mixed> $expectedContext */
    public function isFresh(
        Request $request,
        Plan $plan,
        Task $task,
        array $expectedContext,
    ): bool {
        try {
            $this->assertFresh($request, $plan, $task, $expectedContext);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            'Execution Packet生成中に権限またはPlan / Taskの現在地が変わりました。現在のContextを再確認してください。',
            $code,
            $runId,
        );
    }
}
