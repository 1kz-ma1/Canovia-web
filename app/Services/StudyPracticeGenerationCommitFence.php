<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;

/**
 * G3-R1 Native/Hybrid AI question-generation commit fence.
 * Does not duplicate the separately integrated assessment fence (#499).
 */
final class StudyPracticeGenerationCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}

    public function capture(Plan $plan, Task $task, int $userId): string
    {
        $livePlan = Plan::query()->find($plan->id);
        $liveTask = Task::query()->find($task->id);
        if ($livePlan === null || $liveTask === null) {
            $this->deny('native_ai_target_changed', null);
        }

        return $this->fingerprint($livePlan, $liveTask, $userId);
    }

    /** Run inside the transaction committing Session + Demand + Candidates. */
    public function assertCanCommit(
        Plan $plan,
        Task $task,
        int $userId,
        string $expected,
        ?int $runId = null,
    ): void {
        $currentPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $currentTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();

        if (
            $currentPlan === null || $currentTask === null
            || $userId <= 0
            || (int) $currentTask->plan_id !== (int) $plan->id
            || $this->categoryProfiles->forPlan($currentPlan)->key !== 'study'
            || in_array((string) $currentTask->status, ['done', 'cancelled'], true)
            || (int) $currentTask->progress_percent >= 100
        ) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $canEdit = (int) $currentPlan->user_id === $userId
            || ($currentPlan->is_collaborative && PlanMember::query()
                ->where('plan_id', $plan->id)
                ->where('user_id', $userId)
                ->where('role', PlanMember::ROLE_EDITOR)
                ->exists());
        if (!$canEdit) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $failure = $this->purposeGuard->failure(
            $userId, (int) $plan->id, (int) $task->id,
            FeatureKey::AutomaticAiExecution,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }

        if ($expected === ''
            || ! hash_equals($expected, $this->fingerprint($currentPlan, $currentTask, $userId))) {
            $this->deny('native_ai_source_changed', $runId);
        }
    }

    private function fingerprint(Plan $plan, Task $task, int $userId): string
    {
        // Learning strategy consumes prior Attempts. A newly completed
        // exercise or a revision to existing results changes this signature.
        $attempts = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (StudyPracticeAttempt $item) => $item->getAttributes())
            ->all();

        return hash('sha256', json_encode([
            'plan' => $plan->getAttributes(),
            'task' => $task->getAttributes(),
            'attempts' => $attempts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            '問題生成中に学習の現在地・履歴・権限が変わりました。最新の状態から問題を準備してください。',
            $code, $runId,
        );
    }
}
