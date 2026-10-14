<?php

namespace App\Services;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * V5 security boundary for a FUTURE authenticated provider adapter.
 *
 * This service performs no network calls and does not accept browser input.
 * The caller must first establish provider identity and read permission
 * server-side; claims supplied by a user are never provider evidence.
 * A matching service record proves only service metadata visibility.
 */
final class DevelopmentOperationalProviderBoundaryService
{
    private const MAX_OBSERVATION_AGE_SECONDS = 900;
    private const MAX_CLOCK_SKEW_SECONDS = 60;

    /**
     * @param array<string,mixed>|null $grant Server-side verified, owner-approved connection/grant snapshot.
     * @param array<string,mixed>|null $observation Server-side authenticated provider adapter result.
     * @return array{status:string,reason:string,kind:string,observed_at?:string}
     */
    public function assess(
        Plan $plan,
        ?DevelopmentOperationalTarget $target,
        ?array $grant,
        ?array $observation,
        ?DateTimeImmutable $now = null,
    ): array {
        $unknown = static fn (string $reason): array => [
            'status' => 'unknown',
            'reason' => $reason,
            'kind' => 'service_presence',
        ];

        if (! $target || ! $grant || ! $observation) {
            return $unknown('connection_or_observation_missing');
        }

        $planId = (int) $plan->getKey();
        $ownerId = (int) $plan->user_id;
        if ($planId <= 0 || $ownerId <= 0
            || (int) $target->plan_id !== $planId
            || $target->provider !== 'render'
            || ! in_array($target->environment, DevelopmentOperationalTarget::ENVIRONMENTS, true)
            || ! is_string($target->resource_ref)
            || ! preg_match('/\Asrv-[a-zA-Z0-9_-]{4,76}\z/', $target->resource_ref)
            || (int) $target->revision < 1) {
            return $unknown('target_scope_invalid');
        }

        $connectionId = $grant['connection_id'] ?? null;
        $workspaceId = $grant['workspace_id'] ?? null;
        $epoch = $grant['consent_epoch'] ?? null;
        $scopes = is_array($grant['scopes'] ?? null) ? $grant['scopes'] : [];

        // An internal permission ledger must populate these attributes.
        // Never derive them from a form, ChatGPT connector or API-key possession.
        if (($grant['state'] ?? null) !== 'active'
            || ($grant['provider'] ?? null) !== 'render'
            || ($grant['identity_verified'] ?? null) !== true
            || ($grant['revoked_at'] ?? null) !== null
            || ! is_string($connectionId)
            || ! preg_match('/\Aconn-[A-Za-z0-9_-]{4,76}\z/', $connectionId)
            || ! is_string($workspaceId)
            || ! preg_match('/\Atea-[A-Za-z0-9_-]{4,76}\z/', $workspaceId)
            || ! is_int($epoch) || $epoch < 1
            || ! in_array('services.read', $scopes, true)
            || (int) ($grant['owner_id'] ?? 0) !== $ownerId
            || (int) ($grant['plan_id'] ?? 0) !== $planId
            || ($grant['service_id'] ?? null) !== $target->resource_ref
            || (int) ($grant['target_revision'] ?? 0) !== (int) $target->revision) {
            return $unknown('grant_invalid_or_revoked');
        }

        $now ??= new DateTimeImmutable('now');
        $expiresAt = $grant['expires_at'] ?? null;
        if ($expiresAt !== null) {
            $expires = $this->parseTimestamp($expiresAt);
            if ($expires === null || $expires <= $now) {
                return $unknown('grant_expired');
            }
        }

        // Receipt values are compared to the authorization ledger and target.
        // This is a scope guard, NOT proof that the remote provider was queried.
        if (($observation['origin'] ?? null) !== 'authenticated_provider_adapter'
            || ($observation['provider'] ?? null) !== 'render'
            || ($observation['kind'] ?? null) !== 'service_metadata'
            || ($observation['service_exists'] ?? null) !== true
            || ($observation['connection_id'] ?? null) !== $connectionId
            || ($observation['workspace_id'] ?? null) !== $workspaceId
            || ($observation['service_id'] ?? null) !== $target->resource_ref
            || ($observation['consent_epoch'] ?? null) !== $epoch
            || ($observation['target_revision'] ?? null) !== (int) $target->revision) {
            return $unknown('observation_scope_mismatch');
        }

        $at = $this->parseTimestamp($observation['observed_at'] ?? null);
        if ($at === null) {
            return $unknown('observation_time_invalid');
        }
        $age = $now->getTimestamp() - $at->getTimestamp();
        if ($age < -self::MAX_CLOCK_SKEW_SECONDS) {
            return $unknown('observation_from_future');
        }
        if ($age > self::MAX_OBSERVATION_AGE_SECONDS) {
            return [
                'status' => 'stale',
                'reason' => 'observation_expired',
                'kind' => 'service_presence',
            ];
        }

        return [
            'status' => 'observed',
            'reason' => 'service_metadata_scope_matched',
            'kind' => 'service_presence',
            'observed_at' => $at->format(DateTimeInterface::ATOM),
        ];
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/',
            $value,
        )) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
