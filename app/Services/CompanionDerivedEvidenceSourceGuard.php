<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\TaskEvidence;

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
            // No reliable original source association exists on older text.
            // These historical records are separately tracked as unresolved.
            return true;
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
