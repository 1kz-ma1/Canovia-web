<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bounded G3-R1 Native study commit fence. No user content or AI answer is
 * logged: only an in-process digest is compared to freshly locked rows.
 */
final class StudyPracticeNativeCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}

    /**
     * Take a snapshot immediately before the provider request.
     *
     * @return array{fingerprint:string,session_id:int|null}
     */
    public function capture(
        Plan $plan,
        Task $task,
        int $userId,
        ?StudyPracticeSession $session = null,
    ): array {
        $currentPlan = Plan::query()->find($plan->id);
        $currentTask = Task::query()->find($task->id);
        $currentSession = $session
            ? StudyPracticeSession::query()->find($session->id)
            : null;

        if (! $currentPlan || ! $currentTask || ($session && ! $currentSession)) {
            $this->deny('native_ai_target_changed', null);
        }

        return [
            'fingerprint' => $this->fingerprint($currentPlan, $currentTask, $userId, $currentSession),
            'session_id' => $session?->id,
        ];
    }

    /**
     * Revalidate inside the same DB transaction as the derived Session/Attempt
     * write; callers must never use the earlier controller authorization.
     *
     * @param array{fingerprint:string,session_id:int|null} $snapshot
     */
    public function assertCanCommit(
        Plan $plan,
        Task $task,
        int $userId,
        array $snapshot,
        ?int $runId = null,
    ): void {
        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();
        $sessionId = $snapshot['session_id'] ?? null;
        $liveSession = $sessionId !== null
            ? StudyPracticeSession::query()->whereKey($sessionId)->lockForUpdate()->first()
            : null;

        if (
            !$livePlan || !$liveTask
            || ($sessionId !== null && !$liveSession)
            || (int) $liveTask->plan_id !== (int) $livePlan->id
            || $this->categoryProfiles->forPlan($livePlan)->key !== 'study'
            || in_array((string) $liveTask->status, ['done', 'cancelled'], true)
            || (int) $liveTask->progress_percent >= 100
        ) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $canEdit = (int) $livePlan->user_id === $userId
            || ($livePlan->is_collaborative && PlanMember::query()
                ->where('plan_id', $livePlan->id)
                ->where('user_id', $userId)
                ->where('role', PlanMember::ROLE_EDITOR)
                ->exists());
        if (!$canEdit) {
            $this->deny('native_ai_target_changed', $runId);
        }

        if ($liveSession) {
            if (
                (int) $liveSession->plan_id !== (int) $livePlan->id
                || (int) $liveSession->task_id !== (int) $liveTask->id
                || (int) $liveSession->user_id !== $userId
                || in_array((string) $liveSession->status, [
                    StudyPracticeSession::STATUS_ASSESSED,
                    StudyPracticeSession::STATUS_COMPLETED,
                    StudyPracticeSession::STATUS_ABANDONED,
                ], true)
            ) {
                $this->deny('native_ai_target_changed', $runId);
            }
        }

        $failure = $this->purposeGuard->failure(
            $userId, (int) $plan->id, (int) $task->id,
            FeatureKey::AutomaticAiExecution,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }

        try {
            $actual = $this->fingerprint($livePlan, $liveTask, $userId, $liveSession);
        } catch (Throwable) {
            $this->deny('native_ai_source_changed', $runId);
        }

        if (!hash_equals((string) $snapshot['fingerprint'], $actual)) {
            $this->deny('native_ai_source_changed', $runId);
        }
    }

    /**
     * @param array{fingerprint:string,session_id:int|null} $snapshot
     */
    public function isCurrent(
        Plan $plan,
        Task $task,
        int $userId,
        array $snapshot,
        ?int $runId = null,
    ): bool {
        try {
            DB::transaction(function () use ($plan, $task, $userId, $snapshot, $runId) {
                $this->assertCanCommit($plan, $task, $userId, $snapshot, $runId);
            }, 3);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function fingerprint(
        Plan $plan,
        Task $task,
        int $userId,
        ?StudyPracticeSession $session,
    ): string {
        // Generation strategy depends on prior attempts; a newly submitted
        // attempt during the provider call invalidates the old weak-point mix.
        $attemptVersion = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('user_id', $userId)
            ->selectRaw('COUNT(*) as total_attempts, MAX(id) as last_attempt_id')
            ->first();

        return hash('sha256', json_encode([
            'plan' => $plan->getAttributes(),
            'task' => $task->getAttributes(),
            'session' => $session ? [
                'id' => $session->id,
                'plan_id' => $session->plan_id,
                'task_id' => $session->task_id,
                'user_id' => $session->user_id,
                'actor_token' => $session->actor_token,
                'prepare_request_id' => $session->prepare_request_id,
                'session_token' => $session->session_token,
                'status' => $session->status,
                'question_provider' => $session->question_provider,
                'questions_snapshot' => $session->questions_snapshot,
                'selected_questions' => $session->selected_questions,
                'draft_answers' => $session->draft_answers,
                'draft_saved_at' => $session->draft_saved_at?->toIso8601String(),
            ] : null,
            'attempt_count' => (int) ($attemptVersion?->total_attempts ?? 0),
            'attempt_latest_id' => (int) ($attemptVersion?->last_attempt_id ?? 0),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            'AI応答中に学習の現在地・回答・利用権限が更新されました。最新の演習から再実行してください。',
            $code, $runId,
        );
    }
}
