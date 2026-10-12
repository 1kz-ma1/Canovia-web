<?php

namespace App\Intelligence\Services;

use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceStateSnapshot;
use App\Models\Plan;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class StateSnapshotStore
{
    public function __construct(
        private readonly PlanOwnershipService $ownership,
    ) {}

    public function persist(
        StateSnapshot $state,
        ?int $userId = null,
        ?int $planId = null,
        array $metadata = [],
    ): IntelligenceStateSnapshot {
        $stateReference = IntelligenceFingerprint::stateReference($state);

        $snapshot = IntelligenceStateSnapshot::query()->firstOrCreate(
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

        // The reference is globally unique but does not encode actor identity.
        // Never return another actor's persisted data when a reference collides.
        // Actor-scoped Plan-free snapshot identity is deferred to the G6 contract.
        if (
            ($snapshot->user_id === null ? null : (int) $snapshot->user_id) !== $userId
            || ($snapshot->plan_id === null ? null : (int) $snapshot->plan_id) !== $planId
            || $snapshot->domain->value !== $state->domain->value
            || $snapshot->scope_type !== $state->scopeType
            || $snapshot->scope_id !== ($state->scopeId !== null ? (string) $state->scopeId : null)
        ) {
            throw new InvalidArgumentException('State Snapshot reference belongs to a different scope.');
        }

        return $snapshot;
    }

    /**
     * Read only the selected Plan's State through its current actor policy.
     * Caller-supplied scope IDs are deliberately not accepted.
     */
    public function latestForPlan(
        Request $request,
        Plan $plan,
        IntelligenceDomain $domain,
        string $scopeType,
    ): ?IntelligenceStateSnapshot {
        $this->ownership->authorizeView($request, $plan);

        return IntelligenceStateSnapshot::query()
            ->where('plan_id', $plan->id)
            ->where('domain', $domain->value)
            ->where('scope_type', $scopeType)
            ->where('scope_id', (string) $plan->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
    }
}
