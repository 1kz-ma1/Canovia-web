<?php

namespace App\Intelligence\Services;

use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceStateSnapshot;
use App\Models\TaskEvidence;
use Throwable;

/**
 * G3 bounded TaskEvidence lineage fence for persisted V53 State/Decision/Action.
 * Does not change or delete source rows or historical Intelligence records.
 */
final class IntelligenceEvidenceFreshnessGuard
{
    public function __construct(
        private readonly TaskEvidenceAdapter $adapter,
        private readonly IntelligenceDomainSourceFreshnessGuard $domainSources,
    ) {}

    /**
     * Store the normalized source version, never source payloads.
     *
     * @param array<int,mixed> $references
     * @return array<string,string>
     */
    public function signaturesFor(int $planId, array $references): array
    {
        $ids = $this->ids($references);
        if ($ids === [] || $planId <= 0) {
            return [];
        }

        return TaskEvidence::query()
            ->where('plan_id', $planId)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (TaskEvidence $row) => [
                (string) $row->id => IntelligenceFingerprint::evidence(
                    $this->adapter->adapt($row),
                ),
            ])->all();
    }

    /**
     * Check every supported referenced TaskEvidence against the current Plan,
     * normalized facts and historical trace/signature. Errors deny disclosure.
     * Unreferenced domain input is not certified by this first G3 fence.
     */
    public function isCurrent(?IntelligenceStateSnapshot $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        try {
            $refs = $snapshot->evidence_references;
            if (! is_array($refs)) {
                return false;
            }

            $planId = (int) ($snapshot->plan_id ?? 0);
            $ids = $this->ids($refs);
            if ($ids === []) {
                // Career may derive state directly from applications/reviews,
                // even when there is no linked TaskEvidence to verify.
                return $this->domainSources->isCurrent($snapshot);
            }

            if ($planId <= 0) {
                return false;
            }

            $signatures = $this->signaturesFor($planId, $refs);
            $stored = data_get($snapshot->metadata, 'evidence_signatures', []);
            $stored = is_array($stored) ? $stored : [];

            if (count($signatures) !== count($ids)) {
                return false; // Deleted or moved to another Plan.
            }

            foreach ($refs as $ref) {
                $origin = is_array($ref) ? ($ref['origin'] ?? null) : $ref;
                if (! is_string($origin)
                    || ! preg_match('/^task_evidence:([1-9][0-9]*)$/', $origin, $match)) {
                    // Other origin families require separate domain adapters.
                    continue;
                }

                $id = (string) ((int) $match[1]);
                $actual = $signatures[$id] ?? null;
                if (! is_string($actual)) {
                    return false;
                }

                // Study State uses trace hashes from the beginning; validate
                // those historical references even without v0.5 metadata.
                if (is_array($ref)) {
                    $expectedTrace = $ref['trace'] ?? null;
                    if (! is_string($expectedTrace)
                        || ! preg_match('/^evidence:([^:]+):([a-f0-9]{64})$/', $expectedTrace, $parts)
                        || ! hash_equals($parts[2], $actual)) {
                        return false;
                    }
                }

                // New V53 writers capture fingerprints for plain reference
                // formats (Development/Career). Legacy plain strings can
                // prove existence only, not detect content edits.
                if (array_key_exists($id, $stored)
                    && (! is_string($stored[$id])
                        || ! hash_equals($stored[$id], $actual))) {
                    return false;
                }
            }

            // Source families outside TaskEvidence (notably Career
            // applications/reviews) must also match the current V53 projection.
            return $this->domainSources->isCurrent($snapshot);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int,mixed> $refs
     *  @return array<int,int>
     */
    private function ids(array $refs): array
    {
        $ids = [];
        foreach ($refs as $reference) {
            $origin = is_array($reference)
                ? ($reference['origin'] ?? null) : $reference;

            if (! is_string($origin)) {
                continue;
            }

            if (preg_match('/^task_evidence:([1-9][0-9]*)$/', $origin, $match)) {
                $ids[] = (int) $match[1];
            }
        }

        return array_values(array_unique($ids));
    }
}
