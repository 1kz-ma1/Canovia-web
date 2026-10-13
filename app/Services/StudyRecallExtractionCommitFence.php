<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bounded G3 fence for native-AI Recall candidate extraction.
 *
 * Call snapshot() before submitting the original material and assertCanCommit()
 * inside the SAME transaction as candidate + source-result persistence.
 * Locks do not replace a shared locking protocol for independent source writers.
 */
final class StudyRecallExtractionCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly CompanionMembershipCommitGuard $membershipCommit,
    ) {}

    /**
     * Capture the original Owner or Editor admission before the provider sees
     * private material. Never persist this proof on a Source or AI run.
     *
     * @return array<string,mixed>
     */
    public function captureAuthorization(Plan $plan, ?int $userId): array
    {
        $actor = $userId !== null && $userId > 0
            ? User::query()->find($userId)
            : null;
        if ($actor === null) {
            $this->deny('native_ai_target_changed', 0);
        }

        $proof = $this->membershipCommit->capture($actor, $plan);
        if (
            ! in_array($proof['scope'] ?? null, ['owner', 'member'], true)
            || (($proof['scope'] ?? null) === 'member'
                && ($proof['role'] ?? null) !== PlanMember::ROLE_EDITOR)
        ) {
            $this->deny('native_ai_target_changed', 0);
        }

        return $proof;
    }

    public function snapshot(StudyRecallSource $source): ?string
    {
        try {
            $fileDigest = null;

            if ($source->source_type !== 'text') {
                $path = (string) $source->storage_path;
                if ($path === '' || ! Storage::exists($path)) {
                    return null;
                }

                $fileDigest = hash('sha256', Storage::get($path));
            }

            return hash('sha256', json_encode([
                (int) $source->id,
                (int) $source->plan_id,
                (int) $source->task_id,
                $source->user_id === null ? null : (int) $source->user_id,
                (string) ($source->actor_token ?? ''),
                $source->plan_resource_id === null ? null : (int) $source->plan_resource_id,
                (string) $source->status,
                (string) $source->source_type,
                (string) ($source->original_name ?? ''),
                (string) ($source->mime_type ?? ''),
                (string) ($source->storage_path ?? ''),
                (string) ($source->source_text ?? ''),
                $fileDigest,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,string|null> $expectedSignatures keyed by Source ID
     * @param array<string,mixed> $expectedAuthorization owner/member proof captured before AI
     */
    public function assertCanCommit(
        Plan $plan,
        Task $task,
        ?int $userId,
        array $expectedSignatures,
        array $expectedAuthorization,
        int $runId,
    ): void {
        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();

        if (
            $livePlan === null
            || $liveTask === null
            || (int) $liveTask->plan_id !== (int) $livePlan->id
            || $expectedSignatures === []
        ) {
            $this->deny('native_ai_target_changed', $runId);
        }

        // Plan -> Task are locked. Lock the same member row under the
        // transaction before checking the original authorization epoch;
        // Editor -> Viewer -> Editor or remove/rejoin must fail closed.
        $actor = $userId !== null && $userId > 0
            ? User::query()->find($userId)
            : null;
        if ($actor === null
            || $expectedAuthorization === []
            || ! $this->membershipCommit->canAccess(
                $actor, $livePlan, true, $expectedAuthorization,
            )) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $failure = $this->purposeGuard->failure(
            $userId,
            (int) $plan->id,
            (int) $task->id,
            FeatureKey::AutomaticAiExecution,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }

        ksort($expectedSignatures, SORT_NUMERIC);
        $liveSources = StudyRecallSource::query()
            ->whereIn('id', array_keys($expectedSignatures))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($liveSources->count() !== count($expectedSignatures)) {
            $this->deny('native_ai_source_changed', $runId);
        }

        foreach ($expectedSignatures as $id => $signature) {
            $live = $liveSources->get($id);
            if (
                $live === null
                || (int) $live->plan_id !== (int) $plan->id
                || (int) $live->task_id !== (int) $task->id
                || (int) $live->user_id !== $userId
                || $signature === null
                || ! hash_equals($signature, $this->snapshot($live) ?? '')
            ) {
                $this->deny('native_ai_source_changed', $runId);
            }
        }
    }

    private function deny(string $code, int $runId): never
    {
        throw new NativeAiExecutionException(
            'AIの処理中に教材または利用権限が変更されました。確認して再抽出してください。',
            $code,
            $runId,
        );
    }
}
