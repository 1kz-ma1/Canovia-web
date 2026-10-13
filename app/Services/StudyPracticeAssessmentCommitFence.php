<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * G3-R1: Native AI assessment may commit only against the same learner
 * answers/questions and currently authorized Study Plan/Task.
 *
 * This checks one durable commit; independent source/grant writers must
 * participate in common locking for a multiworker, fully serializable proof.
 */
final class StudyPracticeAssessmentCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly CompanionMembershipCommitGuard $membershipCommit,
    ) {}

    public function fingerprint(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
    ): string {
        return hash('sha256', json_encode([
            'plan' => [
                'id' => (int) $plan->id,
                'owner_id' => $plan->user_id,
                'category' => $plan->category,
                'title' => $plan->title,
                'description' => $plan->description,
                'deadline' => $plan->deadline?->toDateString(),
                'is_collaborative' => $plan->is_collaborative,
            ],
            'task' => [
                'id' => (int) $task->id,
                'plan_id' => $task->plan_id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'progress_percent' => $task->progress_percent,
                'remaining_minutes' => $task->remaining_minutes,
            ],
            // Owner/member authorization epoch and stable admission ID
            // are part of the original assessment context.
            'membership_provenance' => $this->authorizationProvenance($plan, (int) $session->user_id),
            'session' => [
                'id' => (int) $session->id,
                'plan_id' => $session->plan_id,
                'task_id' => $session->task_id,
                'user_id' => $session->user_id,
                'actor_token' => $session->actor_token,
                'status' => $session->status,
                'question_provider' => $session->question_provider,
                'questions_snapshot' => $session->questions_snapshot,
                'selected_questions' => $session->selected_questions,
                'draft_answers' => $session->draft_answers,
                'draft_saved_at' => $session->draft_saved_at?->toIso8601String(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<int,array<string,mixed>> $questions The questions actually
     *                                                   sent to the AI.
     */
    public function capture(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        array $questions,
        ?int $userId,
    ): string {
        $livePlan = Plan::query()->find($plan->id);
        $liveTask = Task::query()->find($task->id);
        $liveSession = StudyPracticeSession::query()->find($session->id);

        $this->assertScope($liveSession, $livePlan, $liveTask, $userId, null);
        if ($liveSession->questions_snapshot !== $questions) {
            $this->deny('native_ai_source_changed', null);
        }

        return $this->fingerprint($liveSession, $livePlan, $liveTask);
    }

    /**
     * Run only inside the calling write transaction. The caller must lock
     * and recheck the same source for each AI-derived mutation boundary.
     *
     * @param array<int,array<string,mixed>> $questions
     */
    public function assertCanCommit(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        array $questions,
        ?int $userId,
        string $expected,
        ?int $runId = null,
    ): StudyPracticeSession {
        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();
        $liveSession = StudyPracticeSession::query()->whereKey($session->id)->lockForUpdate()->first();

        $this->assertScope($liveSession, $livePlan, $liveTask, $userId, $runId);
        // Plan/Task/Session are already locked; serialize post-provider
        // membership changes with grade and evidence publication.
        $actor = $userId === null ? null : User::query()->find($userId);
        if ($actor === null || ! $this->membershipCommit->canAccess($actor, $livePlan, true)) {
            $this->deny('native_ai_target_changed', $runId);
        }
        if ($liveSession->questions_snapshot !== $questions
            || ! hash_equals($expected, $this->fingerprint($liveSession, $livePlan, $liveTask))) {
            $this->deny('native_ai_source_changed', $runId);
        }

        return $liveSession;
    }

    /**
     * @param callable(StudyPracticeSession):mixed $commit
     */
    public function commit(
        StudyPracticeSession $session,
        Plan $plan,
        Task $task,
        array $questions,
        ?int $userId,
        string $expected,
        ?int $runId,
        callable $commit,
    ): mixed {
        return DB::transaction(function () use (
            $session, $plan, $task, $questions, $userId, $expected, $runId, $commit,
        ) {
            $live = $this->assertCanCommit(
                $session, $plan, $task, $questions, $userId, $expected, $runId,
            );

            return $commit($live);
        }, 3);
    }

    private function assertScope(
        ?StudyPracticeSession $session,
        ?Plan $plan,
        ?Task $task,
        ?int $userId,
        ?int $runId,
    ): void {
        if (
            $session === null || $plan === null || $task === null
            || $userId === null || $userId <= 0
            || (int) $session->user_id !== $userId
            || (int) $session->plan_id !== (int) $plan->id
            || (int) $session->task_id !== (int) $task->id
            || (int) $task->plan_id !== (int) $plan->id
            || $plan->category !== '資格学習'
            || ! in_array($session->status, [
                StudyPracticeSession::STATUS_READY,
                StudyPracticeSession::STATUS_IN_PROGRESS,
                StudyPracticeSession::STATUS_ANSWERED,
            ], true)
            || $task->status === 'cancelled'
        ) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $editor = (int) $plan->user_id === $userId
            || ($plan->is_collaborative && PlanMember::query()
                ->where('plan_id', $plan->id)
                ->where('user_id', $userId)
                ->where('role', PlanMember::ROLE_EDITOR)
                ->exists());
        if (! $editor) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $failure = $this->purposeGuard->failure(
            $userId, (int) $plan->id, (int) $task->id, FeatureKey::AutomaticAiExecution,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }
    }

    private function authorizationProvenance(Plan $plan, int $userId): array
    {
        $actor = User::query()->find($userId);

        return $actor === null
            ? ['scope' => 'unavailable']
            : $this->membershipCommit->capture($actor, $plan);
    }

    private function deny(string $reason, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            'AI採点中に回答・演習・対象Task・利用権限が変更されました。現在の回答を確認して再採点してください。',
            $reason,
            $runId,
        );
    }
}
