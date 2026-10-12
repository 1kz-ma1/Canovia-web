<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\TaskEvidence;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * G3-R2: new Companion derivatives retain exact GitHub evidence lineage.
 * Historical assistant prose remains visible; it is not an authority to
 * propose a new mutation after its signed evidence loses current eligibility.
 */
final class CompanionDerivedEvidenceSourceGuard
{
    public function __construct(
        private readonly GitHubTaskEvidenceReuseGuard $evidenceGuard,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @return array{version:int,github_evidence:list<array{id:int,revision:string}>}
     */
    public function capture(array $snapshot): array
    {
        $refs = collect(data_get($snapshot, 'recent_evidence', []))
            ->filter(fn ($row) => is_array($row) && ($row['source'] ?? null) === EvidenceSource::GitHub->value)
            ->map(fn (array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'revision' => (string) ($row['source_revision'] ?? ''),
            ])
            ->values()->all();

        return ['version' => 1, 'github_evidence' => $refs];
    }

    /**
     * Absence of provenance on pre-G3 legacy messages means unknown origin,
     * not an assertion that their original context is fresh.
     *
     * @param mixed $provenance
     */
    public function isCurrent(?Plan $plan, mixed $provenance): bool
    {
        if ($provenance === null) {
            // A pre-G3 AI derivative has no verifiable source lineage. It
            // cannot be promoted as *current* within a GitHub-linked Plan;
            // preserve the history, but require a newly signed proposal.
            // Other Plans keep their established legacy behavior because
            // this bounded GitHub gate cannot infer unrelated sources.
            return $plan === null || ! PlanArtifact::query()
                ->where('plan_id', $plan->id)
                ->where('provider', 'github')
                ->where('artifact_type', 'repository')
                ->exists();
        }

        if (! is_array($provenance)
            || ($provenance['version'] ?? null) !== 1
            || ! is_array($provenance['github_evidence'] ?? null)) {
            return false;
        }

        $refs = $provenance['github_evidence'];
        if (count($refs) > 6) {
            return false;
        }

        if ($refs === []) {
            return true;
        }

        if ($plan === null) {
            return false;
        }

        $seen = [];
        foreach ($refs as $ref) {
            if (! is_array($ref) || ! is_int($ref['id'] ?? null)
                || $ref['id'] <= 0 || ! is_string($ref['revision'] ?? null)
                || strlen($ref['revision']) !== 64 || isset($seen[$ref['id']])) {
                return false;
            }

            $seen[$ref['id']] = true;
            $evidence = TaskEvidence::query()->find($ref['id']);
            if (! $evidence || $evidence->source !== EvidenceSource::GitHub
                || ! $this->evidenceGuard->eligible($plan, $evidence)
                || ! hash_equals($ref['revision'], $this->revision($evidence))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transaction-scoped authority check for a persisted AI derivative.
     *
     * Source checks performed before an AI call or in a read-only preview
     * cannot protect a later database commit. Serialize with GitHub root
     * status/epoch writes and source Evidence updates until this caller's
     * transaction commits. Call after locking the Plan and Task, in that
     * order, and before creating any derived Memory, Candidate or mutation.
     *
     * @param mixed $provenance
     */
    public function isCurrentForCommit(?Plan $plan, mixed $provenance): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Companion source commit validation requires an open transaction.');
        }

        if ($plan === null) {
            return $this->isCurrent(null, $provenance);
        }

        // All GitHub connection-status writers update/lock the repository
        // artifact. A regular SELECT at READ COMMITTED leaves a gap between
        // validation and commit; FOR UPDATE closes that gap.
        PlanArtifact::query()
            ->where('plan_id', $plan->id)
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $refs = is_array($provenance) ? ($provenance['github_evidence'] ?? null) : null;
        if (is_array($refs) && $refs !== []) {
            // Row-level locks also serialize same-epoch content revision
            // updates that do not modify the repository root metadata.
            // Invalid ref shapes are rejected by isCurrent() afterwards.
            $ids = collect($refs)
                ->filter(fn ($ref) => is_array($ref) && is_int($ref['id'] ?? null) && $ref['id'] > 0)
                ->pluck('id')
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($ids !== []) {
                TaskEvidence::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }
        }

        // Re-read both tables while the row locks are held. Never validate
        // cached Eloquent metadata from the original AI context snapshot.
        return $this->isCurrent($plan, $provenance);
    }

    public function revision(TaskEvidence $row): string
    {
        return hash('sha256', (string) json_encode([
            'id' => (int) $row->id,
            'plan_id' => (int) $row->plan_id,
            'task_id' => (int) $row->task_id,
            'type' => (string) $row->type,
            'external_key' => (string) $row->external_key,
            'metadata' => $row->metadata,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
