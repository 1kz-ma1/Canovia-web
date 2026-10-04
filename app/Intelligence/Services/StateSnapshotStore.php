<?php

namespace App\Intelligence\Services;

use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceStateSnapshot;
use Illuminate\Support\Arr;

final class StateSnapshotStore
{
    public function persist(
        StateSnapshot $state,
        ?int $userId = null,
        ?int $planId = null,
        array $metadata = [],
    ): IntelligenceStateSnapshot {
        $stateReference = IntelligenceFingerprint::stateReference($state);

        return IntelligenceStateSnapshot::query()->firstOrCreate(
            [
                'state_reference' => $stateReference,
            ],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'domain' => $state->domain->value,
                'scope_type' => $state->scopeType,
                'scope_id' => $state->scopeId !== null ? (string) $state->scopeId : null,
                'state_fingerprint' => IntelligenceFingerprint::state($state),
                'captured_at' => $state->capturedAt,
                'metrics' => $state->metrics,
                'facts' => $state->facts,
                'evidence_references' => $state->evidenceReferences,
                'metadata' => Arr::only($metadata, [
                    'schema_version',
                    'builder',
                    'builder_version',
                ]),
            ],
        );
    }

    public function latest(
        IntelligenceDomain $domain,
        string $scopeType,
        string|int|null $scopeId,
    ): ?IntelligenceStateSnapshot {
        $query = IntelligenceStateSnapshot::query()
            ->where('domain', $domain->value)
            ->where('scope_type', $scopeType);

        if ($scopeId === null) {
            $query->whereNull('scope_id');
        } else {
            $query->where('scope_id', (string) $scopeId);
        }

        return $query
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
    }
}
