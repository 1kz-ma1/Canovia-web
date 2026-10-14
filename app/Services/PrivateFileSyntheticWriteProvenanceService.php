<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * G3-R23: fixture-only correlation of a durable write attempt, a synthetic
 * PutObject-shaped acknowledgement, and a synthetic later observation.
 *
 * An application HMAC authenticates only its own stored binding, NOT who
 * supplied the response or what actually happened at an object provider.
 * Even a fully consistent candidate is NOT an attested generation.
 * No provider SDK, Storage I/O, writes, retry, or deletion.
 */
final class PrivateFileSyntheticWriteProvenanceService
{
    private const RECEIPT_FIELDS = [
        'origin', 'operation', 'status_code', 'attempt_id', 'request_token',
        'disk', 'bucket', 'key', 'version_id', 'request_id', 'received_at',
    ];

    private const CLAIM_FIELDS = [
        'origin', 'provider', 'versioning', 'response_binding',
        'external_writers', 'conditional_generation',
    ];

    public function __construct(
        private readonly PrivateFileProviderReconciliationService $reconciliation,
    ) {}

    /** @return array<string,bool|string> */
    private function result(string $state): array
    {
        return [
            'state' => $state,
            'provider_attested' => false,
            'write_receipt_authenticated' => false,
            'generation_verified' => false,
            'external_writers_excluded' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
            'network_requests' => false,
            'storage_mutations' => false,
        ];
    }

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_synthetic_write_provenance_enabled', false) === true
            && config('canovia.private_file_provider_reconciliation_enabled', false) === true
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('filesystems.default') === 's3'
            && config('filesystems.disks.s3.driver') === 's3';
    }

    private function sameFields(array $candidate, array $expected): bool
    {
        $keys = array_keys($candidate);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    private function validVersion(mixed $version): bool
    {
        return is_string($version)
            && trim($version) !== ''
            && strlen($version) <= 1024
            && strtolower($version) !== 'null'
            && ! str_contains($version, "\0");
    }

    /**
     * Every supplied field is UNTRUSTED: never reinterpret a payload, SDK-like
     * shape, request ID, VersionId or claimed capability as provider proof.
     *
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $observation An R21 synthetic probe payload.
     * @param array<string,mixed> $claims
     * @return array<string,bool|string>
     */
    public function inspect(
        int $intentId,
        array $receipt,
        array $observation,
        array $claims,
    ): array {
        if (! $this->permitted() || $intentId < 1) {
            return $this->result('blocked');
        }

        if (! $this->sameFields($receipt, self::RECEIPT_FIELDS)
            || ($receipt['origin'] ?? null) !== 'synthetic_put_reply_v1'
            || ($receipt['operation'] ?? null) !== 'PutObject'
            || ($receipt['status_code'] ?? null) !== 200
            || ! is_int($receipt['attempt_id'] ?? null)
            || ! is_string($receipt['request_token'] ?? null)
            || ! preg_match('/^[a-f0-9]{48}$/D', $receipt['request_token'])
            || ($receipt['disk'] ?? null) !== 's3'
            || ! is_string($receipt['bucket'] ?? null)
            || ! is_string($receipt['key'] ?? null)
            || ! $this->validVersion($receipt['version_id'] ?? null)
            || ! is_string($receipt['request_id'] ?? null)
            || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $receipt['request_id'])
            || ! is_int($receipt['received_at'] ?? null)) {
            return $this->result('invalid_synthetic_receipt');
        }

        if (! $this->sameFields($claims, self::CLAIM_FIELDS)
            || ($claims['origin'] ?? null) !== 'synthetic_capability_claims_v1'
            || ($claims['provider'] ?? null) !== 's3_claimed'
            || ! in_array($claims['versioning'], ['enabled', 'suspended', 'disabled', 'unknown'], true)
            || ! in_array($claims['response_binding'], ['claimed_bound', 'unavailable', 'unknown'], true)
            || ! in_array($claims['external_writers'], ['claimed_excluded', 'possible', 'unknown'], true)
            || ! in_array($claims['conditional_generation'], ['claimed_supported', 'unsupported', 'unknown'], true)) {
            return $this->result('invalid_synthetic_claims');
        }

        // The receipt and the observation share the same transient request
        // identity, but that alone cannot authenticate their origin.
        if (! is_int($observation['attempt_id'] ?? null)
            || ! is_string($observation['request_token'] ?? null)
            || ! is_string($observation['bucket'] ?? null)
            || ! is_string($observation['key'] ?? null)
            || ! is_int($observation['observed_at'] ?? null)
            || $receipt['attempt_id'] !== $observation['attempt_id']
            || ! hash_equals($receipt['request_token'], $observation['request_token'])
            || ! hash_equals($receipt['bucket'], $observation['bucket'])
            || ! hash_equals($receipt['key'], $observation['key'])) {
            return $this->result('receipt_observation_binding_mismatch');
        }

        $now = now()->timestamp;
        if ($receipt['received_at'] < $now - 300
            || $receipt['received_at'] > $now + 5
            || $receipt['received_at'] > $observation['observed_at']) {
            return $this->result('receipt_timeline_unverifiable');
        }

        try {
            // Existing R21 independently checks durable R20 request-token
            // HMAC, epoch, bucket, encrypted key, intent and ack VersionId.
            $observed = $this->reconciliation->inspect($intentId, $observation);
            $state = $observed['state'];
            if ($state === 'blocked' || $state === 'schema_unavailable'
                || ! in_array($state, [
                    'version_match_unattested', 'version_mismatch_unattested',
                    'present_without_durable_ack',
                ], true)) {
                return $this->result('observation_unverifiable');
            }

            $attempt = DB::table('private_file_write_attempts')
                ->where('upload_intent_id', $intentId)
                ->first(['id', 'status', 'created_at']);
            if ($attempt === null
                || (int) $attempt->id !== $receipt['attempt_id']) {
                return $this->result('receipt_attempt_unverifiable');
            }
            $reservationTime = strtotime((string) $attempt->created_at);
            if ($reservationTime === false
                || $receipt['received_at'] < $reservationTime) {
                return $this->result('receipt_timeline_unverifiable');
            }

            if ($state === 'present_without_durable_ack') {
                return $this->result('durable_ack_missing');
            }

            // R21 does not expose private VersionIds; only this read-only
            // comparison of two untrusted fixture values is needed.
            if (! $this->validVersion($observation['observed_version_id'] ?? null)
                || ! hash_equals($receipt['version_id'], $observation['observed_version_id'])) {
                return $this->result('receipt_observation_version_mismatch');
            }
            if ($state !== 'version_match_unattested'
                || $attempt->status !== 'synthetic_ack_recorded') {
                return $this->result('durable_ack_generation_conflict');
            }

            if ($claims['versioning'] !== 'enabled') {
                return $this->result('versioning_unattested_or_unavailable');
            }
            if ($claims['response_binding'] !== 'claimed_bound') {
                return $this->result('response_provenance_unavailable');
            }
            if ($claims['external_writers'] !== 'claimed_excluded') {
                return $this->result('external_writers_unexcluded');
            }
            if ($claims['conditional_generation'] !== 'claimed_supported') {
                return $this->result('conditional_generation_unproven');
            }

            // All four "good" claims are self-asserted by the fixture.
            // No authenticity, exclusivity, linearizability, or permission.
            return $this->result('synthetic_chain_consistent_unattested');
        } catch (Throwable) {
            return $this->result('unverifiable');
        }
    }
}
