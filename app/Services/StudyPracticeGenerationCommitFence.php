<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

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
            || (string) $currentTask->status === 'cancelled'
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

    /**
     * A duplicate prepare_request_id is a read-only replay, never permission to
     * recreate Candidate/Demand records from a possibly stale session.
     * Historical rows without an original source signature remain usable via
     * the normal Study resume/history UI; they cannot be silently re-signed.
     */
    public function assertReplayReadable(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        int $userId,
    ): void {
        DB::transaction(function () use ($session, $plan, $task, $userId): void {
            $expected = data_get($session->selection_context, 'native_generation_source_fingerprint');
            if (! is_string($expected) || $expected === '') {
                $this->deny('native_ai_source_unverifiable', null);
            }

            // Always lock Plan -> Task -> Session (same order as first commit).
            $this->assertCanCommit($plan, $task, $userId, $expected);
            $live = StudyPracticeSession::query()
                ->whereKey($session->id)->lockForUpdate()->first();
            if ($live === null
                || (int) $live->plan_id !== (int) $plan->id
                || (int) $live->task_id !== (int) $task->id
                || (int) $live->user_id !== $userId
                || ! in_array((string) $live->question_provider, ['native_ai', 'hybrid_ai'], true)
                || data_get($live->selection_context, 'native_generation_source_fingerprint') !== $expected) {
                $this->deny('native_ai_target_changed', null);
            }
        }, 3);
    }

    /**
     * The Orchestrator's atomic first commit is not the last write. Bind the
     * controller's normalization and Native run attachment to that SAME
     * pre-provider signature, and never reset an already started exercise.
     *
     * @param array<int,array<string,mixed>> $questions
     */
    public function finalize(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        int $userId,
        array $questions,
        string $title,
        int $runId,
    ): StudyPracticeSession {
        return DB::transaction(function () use (
            $session, $plan, $task, $userId, $questions, $title, $runId,
        ): StudyPracticeSession {
            $expected = data_get($session->selection_context, 'native_generation_source_fingerprint');
            if (! is_string($expected) || $expected === '') {
                $this->deny('native_ai_source_unverifiable', $runId);
            }

            // Do not re-sign a changed Session or invert the lock order.
            $this->assertCanCommit($plan, $task, $userId, $expected, $runId);
            $live = StudyPracticeSession::query()
                ->whereKey($session->id)->lockForUpdate()->first();
            if ($live === null
                || (int) $live->plan_id !== (int) $plan->id
                || (int) $live->task_id !== (int) $task->id
                || (int) $live->user_id !== $userId
                || ! in_array((string) $live->question_provider, ['native_ai', 'hybrid_ai'], true)
                || data_get($live->selection_context, 'native_generation_source_fingerprint') !== $expected) {
                $this->deny('native_ai_target_changed', $runId);
            }

            // Reject concurrent autosave, completion, or different question
            // material rather than rolling back a human's ongoing exercise.
            if ($live->status !== StudyPracticeSession::STATUS_READY
                || $live->draft_answers !== null
                || $live->questions_snapshot !== $session->questions_snapshot) {
                $this->deny('native_ai_session_changed', $runId);
            }

            $live->update([
                'exercise_title' => $title,
                'questions_snapshot' => $questions,
            ]);

            if ($runId > 0) {
                NativeAiRun::query()
                    ->whereKey($runId)
                    ->where('user_id', $userId)
                    ->where('plan_id', $plan->id)
                    ->where('task_id', $task->id)
                    ->update(['study_practice_session_id' => $live->id]);
            }

            return $live;
        }, 3);
    }

    /**
     * Malformed Native output may abandon only the still-unstarted signed
     * Session. Do not overwrite a concurrently completed learner exercise.
     */
    public function abandonInvalid(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        int $userId,
        int $runId,
    ): void {
        DB::transaction(function () use ($session, $plan, $task, $userId, $runId): void {
            $expected = data_get($session->selection_context, 'native_generation_source_fingerprint');
            if (! is_string($expected) || $expected === '') {
                $this->deny('native_ai_source_unverifiable', $runId);
            }
            $this->assertCanCommit($plan, $task, $userId, $expected, $runId);
            $live = StudyPracticeSession::query()
                ->whereKey($session->id)->lockForUpdate()->first();
            if ($live === null
                || (int) $live->user_id !== $userId
                || data_get($live->selection_context, 'native_generation_source_fingerprint') !== $expected
                || $live->status !== StudyPracticeSession::STATUS_READY
                || $live->draft_answers !== null
                || $live->questions_snapshot !== $session->questions_snapshot) {
                $this->deny('native_ai_session_changed', $runId);
            }

            $live->update(['status' => StudyPracticeSession::STATUS_ABANDONED]);
        }, 3);
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
