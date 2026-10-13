<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Human approval is a new durable mutation, not an automatic continuation of
 * an old model response. Keep Plan -> Task -> member(s) -> Source(s) ->
 * Candidate(s) locked while checking original provenance and writing a Deck.
 */
final class StudyRecallCandidateReviewFence
{
    public function __construct(
        private readonly CompanionMembershipCommitGuard $membership,
        private readonly StudyRecallExtractionCommitFence $sourceFence,
    ) {}

    /** @param array<string,mixed> $originalAuthorization */
    public function provenance(StudyRecallSource $readySource, array $originalAuthorization): array
    {
        $sourceHash = $this->sourceFence->snapshot($readySource);
        if ($readySource->status !== 'ready' || ! is_string($sourceHash) || $sourceHash === '') {
            throw new LogicException('Recall provenance requires the committed ready source.');
        }

        return [
            'version' => 1,
            'source_hash' => $sourceHash,
            'origin_user_id' => (int) $readySource->user_id,
            'authorization_hash' => hash('sha256', json_encode(
                $originalAuthorization, JSON_THROW_ON_ERROR,
            )),
        ];
    }

    /**
     * Must run INSIDE the same transaction as promotion/rejection. Return
     * locked candidate objects, never the prefetched request-local models.
     *
     * @param Collection<int,StudyRecallCandidate> $prefetched
     * @return Collection<int,StudyRecallCandidate>
     */
    public function lockReview(
        Plan $selectedPlan,
        Task $selectedTask,
        User $reviewer,
        Collection $prefetched,
        bool $promote,
        bool $acknowledgeLegacy,
    ): Collection {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Recall candidate review requires a transaction.');
        }

        $plan = Plan::query()->whereKey($selectedPlan->id)->lockForUpdate()->first();
        $task = Task::query()->whereKey($selectedTask->id)->lockForUpdate()->first();
        if ($plan === null || $task === null || (int) $task->plan_id !== (int) $plan->id) {
            $this->reject('対象PlanまたはTaskが変更されました。再読み込みしてください。');
        }

        // Resolve the original authors only as a tentative lock ordering map.
        // Recheck every association after acquiring Source/Candidate locks.
        $preSources = StudyRecallSource::query()->whereIn(
            'id', $prefetched->pluck('study_recall_source_id')->all(),
        )->get()->keyBy('id');

        $actorIds = [$reviewer->id];
        if ($promote) {
            foreach ($prefetched as $candidate) {
                if (is_array($candidate->review_provenance)) {
                    $source = $preSources->get((int) $candidate->study_recall_source_id);
                    if ($source?->user_id) {
                        $actorIds[] = (int) $source->user_id;
                    }
                }
            }
        }

        $actorIds = array_values(array_unique(array_map('intval', $actorIds)));
        sort($actorIds, SORT_NUMERIC);
        $proofHashes = [];
        foreach ($actorIds as $actorId) {
            $actor = User::query()->find($actorId);
            if ($actor === null || ! $this->membership->canAccess($actor, $plan, true)) {
                $this->reject('Recall候補の閲覧・生成時の権限が変更されました。');
            }
            $proofHashes[$actorId] = hash('sha256', json_encode(
                $this->membership->capture($actor, $plan), JSON_THROW_ON_ERROR,
            ));
        }

        $sources = StudyRecallSource::query()->whereIn(
            'id', $prefetched->pluck('study_recall_source_id')->all(),
        )->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $candidateIds = $prefetched->pluck('id')->all();
        $lockedCandidates = StudyRecallCandidate::query()->whereIn('id', $candidateIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($lockedCandidates->count() !== $prefetched->count()) {
            $this->reject('候補の状態が変わりました。再読み込みしてください。');
        }

        foreach ($prefetched as $before) {
            $candidate = $lockedCandidates->get($before->id);
            $source = $sources->get((int) $candidate?->study_recall_source_id);

            if ($candidate === null
                || $candidate->status !== 'pending'
                || (int) $candidate->plan_id !== (int) $plan->id
                || (int) $candidate->task_id !== (int) $task->id
                || (int) $candidate->study_recall_source_id !== (int) $before->study_recall_source_id
                || $source === null
                || (int) $source->plan_id !== (int) $plan->id
                || (int) $source->task_id !== (int) $task->id) {
                $this->reject('候補と元教材の状態が変わりました。再読み込みしてください。');
            }

            if (! $promote) {
                continue; // Rejection creates no authoritative Deck item.
            }

            if ($source->status !== 'ready' || ! $source->hasStoredMaterial()) {
                $this->reject('元教材を確認できません。再取り込みしてください。');
            }

            $proof = $candidate->review_provenance;
            if ($proof === null) {
                if (! $acknowledgeLegacy) {
                    $this->reject('以前の候補には生成時の証明がありません。元教材を自分で再確認し、手動採用に同意してください。');
                }
                continue; // Explicit human adoption, never a fabricated epoch.
            }
            if (! is_array($proof)
                || ($proof['version'] ?? null) !== 1
                || (int) ($proof['origin_user_id'] ?? 0) !== (int) $source->user_id
                || ! is_string($proof['source_hash'] ?? null)
                || ! is_string($proof['authorization_hash'] ?? null)
                || ! hash_equals((string) $proof['source_hash'], $this->sourceFence->snapshot($source) ?? '')
                || ! isset($proofHashes[(int) $source->user_id])
                || ! hash_equals((string) $proof['authorization_hash'], $proofHashes[(int) $source->user_id])) {
                $this->reject('AI候補の元教材または生成時の共有権限が失効しました。再抽出してください。');
            }
        }

        return $lockedCandidates;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['candidates' => $message]);
    }
}
