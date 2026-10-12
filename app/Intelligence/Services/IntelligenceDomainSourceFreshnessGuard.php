<?php

namespace App\Intelligence\Services;

use App\Intelligence\Career\CareerPlanIntelligenceService;
use App\Intelligence\Development\DevelopmentPlanIntelligenceService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use App\Models\IntelligenceStateSnapshot;
use App\Models\Plan;
use Throwable;

/**
 * G3 source fence for official Plan-scoped Career/Development V53 snapshots.
 *
 * Unlike TaskEvidence signatures, Career applications, captures, selection
 * events and interview reviews are projected directly by the V53 builder.
 * Recompute that exact bounded projection at the historical capture instant
 * (without persisting it) before exposing the materialized state or its
 * Decision / Action dependants.
 */
final class IntelligenceDomainSourceFreshnessGuard
{
    /** @var array<string,string|null> */
    private array $currentStates = [];

    public function isCurrent(IntelligenceStateSnapshot $snapshot): bool
    {
        $domain = $snapshot->domain;
        $scope = $snapshot->scope_type;
        if (! (
            ($domain === IntelligenceDomain::Career && $scope === 'career_plan')
            || ($domain === IntelligenceDomain::Development && $scope === 'development_plan')
        )) {
            // Other source families require their own bounded adapters.
            return true;
        }

        try {
            $planId = (int) ($snapshot->plan_id ?? 0);
            if ($planId <= 0 || $snapshot->scope_id !== (string) $planId
                || $snapshot->captured_at === null) {
                return false;
            }

            $plan = Plan::query()->find($planId);
            if ($plan === null || ($plan->user_id === null ? null : (int) $plan->user_id)
                    !== ($snapshot->user_id === null ? null : (int) $snapshot->user_id)) {
                // A Plan moved owners since the persisted analysis was made.
                return false;
            }

            $instant = $snapshot->captured_at->toDateTimeImmutable();
            $key = implode(':', [
                $domain->value,
                $planId,
                $snapshot->captured_at->format('Y-m-d\TH:i:s.uP'),
            ]);

            if (! array_key_exists($key, $this->currentStates)) {
                $evaluated = $domain === IntelligenceDomain::Career
                    ? app(CareerPlanIntelligenceService::class)->evaluate($plan, $instant)
                    : app(DevelopmentPlanIntelligenceService::class)->evaluate($plan, $instant);

                $this->currentStates[$key] = IntelligenceFingerprint::state(
                    $evaluated->state,
                );
            }

            $actual = $this->currentStates[$key];

            return is_string($actual)
                && hash_equals((string) $snapshot->state_fingerprint, $actual);
        } catch (Throwable) {
            // Failed source reads are not a reason to disclose stale Career
            // insights or old Development readiness assertions.
            return false;
        }
    }
}
