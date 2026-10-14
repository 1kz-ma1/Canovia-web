<?php

namespace App\Services;

/**
 * G3-R24: no caller-controlled claim can cross into provider attestation.
 *
 * Purely synthetic, read-only classification of a purported provider
 * provenance envelope AFTER R23's local correlation. A provider SDK HTTP
 * header, application HMAC, claimed signature or TLS connection is not a
 * provider-signed, write-bound and independently verified generation proof.
 *
 * This class contains NO trusted verifier implementation or allow-list that
 * could promote a fixture to authority. No SDK, network or Storage calls.
 */
final class PrivateFileProviderAttestationBoundaryService
{
    private const FIELDS = [
        'origin', 'evidence_kind', 'issuer_claim', 'authenticity_claim',
        'attempt_id', 'request_token', 'bucket', 'key', 'version_id',
        'request_id', 'issued_at',
    ];

    private const EVIDENCE_KINDS = [
        'application_hmac', 'sdk_response_headers',
        'tls_transport_metadata', 'claimed_provider_signature',
    ];

    public function __construct(
        private readonly PrivateFileSyntheticWriteProvenanceService $provenance,
    ) {}

    /** @return array<string,bool|string> */
    private function result(string $state): array
    {
        return [
            'state' => $state,
            'local_binding_consistent' => false,
            'independent_provider_verifier_available' => false,
            'provider_identity_verified' => false,
            'write_response_authenticated' => false,
            'generation_write_bound' => false,
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
            && config('canovia.private_file_provider_attestation_boundary_enabled', false) === true
            && config('canovia.private_file_synthetic_write_provenance_enabled', false) === true
            && config('canovia.private_file_provider_reconciliation_enabled', false) === true
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('filesystems.default') === 's3'
            && config('filesystems.disks.s3.driver') === 's3';
    }

    private function sameFields(array $payload, array $required): bool
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);

        return $keys === $required;
    }

    /**
     * The "evidence" is an untrusted caller-supplied synthetic CLAIM.
     * None of these inputs can authenticate a real cloud provider.
     *
     * @param array<string,mixed> $receipt R23's synthetic PutObject response
     * @param array<string,mixed> $observation R21's synthetic HEAD observation
     * @param array<string,mixed> $capabilities R23's untrusted claims
     * @param array<string,mixed> $assertion R24's untrusted provenance claim
     * @return array<string,bool|string>
     */
    public function classify(
        int $intentId,
        array $receipt,
        array $observation,
        array $capabilities,
        array $assertion,
    ): array {
        if (! $this->permitted() || $intentId < 1) {
            return $this->result('blocked');
        }

        // Fail closed on even one unknown field (especially fake
        // "verified"/"authorized" flags) or unexpected proof mechanism.
        if (! $this->sameFields($assertion, self::FIELDS)
            || ($assertion['origin'] ?? null) !== 'synthetic_provenance_claim_v1'
            || ! in_array($assertion['evidence_kind'] ?? null, self::EVIDENCE_KINDS, true)
            || ! in_array($assertion['issuer_claim'] ?? null, [
                'aws_s3', 'local_application', 'unknown',
            ], true)
            || ! in_array($assertion['authenticity_claim'] ?? null, [
                'none', 'sdk_trusted', 'tls_authenticated', 'claimed_signed',
            ], true)
            || ! is_int($assertion['attempt_id'] ?? null)
            || ! is_string($assertion['request_token'] ?? null)
            || ! preg_match('/^[a-f0-9]{48}$/D', $assertion['request_token'])
            || ! is_string($assertion['bucket'] ?? null)
            || ! is_string($assertion['key'] ?? null)
            || ! is_string($assertion['version_id'] ?? null)
            || ! is_string($assertion['request_id'] ?? null)
            || ! is_int($assertion['issued_at'] ?? null)) {
            return $this->result('invalid_provenance_claim');
        }

        if (! is_int($receipt['attempt_id'] ?? null)
            || ! is_string($receipt['request_token'] ?? null)
            || ! is_string($receipt['bucket'] ?? null)
            || ! is_string($receipt['key'] ?? null)
            || ! is_string($receipt['version_id'] ?? null)
            || ! is_string($receipt['request_id'] ?? null)
            || $assertion['attempt_id'] !== $receipt['attempt_id']
            || ! hash_equals($assertion['request_token'], $receipt['request_token'])
            || ! hash_equals($assertion['bucket'], $receipt['bucket'])
            || ! hash_equals($assertion['key'], $receipt['key'])
            || ! hash_equals($assertion['version_id'], $receipt['version_id'])
            || ! hash_equals($assertion['request_id'], $receipt['request_id'])) {
            return $this->result('provenance_claim_binding_mismatch');
        }

        // No signature, independent counter, storage nonce or time source is
        // authenticated. This check only rejects obvious stale/future claims.
        $now = now()->timestamp;
        if (! is_int($receipt['received_at'] ?? null)
            || $assertion['issued_at'] < $now - 300
            || $assertion['issued_at'] > $now + 5
            || $assertion['issued_at'] > $receipt['received_at']) {
            return $this->result('provenance_claim_timing_unverifiable');
        }

        // Re-check R23's durable bindings. An internally consistent HMAC /
        // encrypted receipt chain is not independent provider testimony.
        $local = $this->provenance->inspect(
            $intentId, $receipt, $observation, $capabilities,
        );
        if (($local['state'] ?? null) !== 'synthetic_chain_consistent_unattested') {
            return $this->result('durable_write_chain_unverifiable');
        }

        $class = match ($assertion['evidence_kind']) {
            'application_hmac' => 'application_hmac_not_provider_proof',
            'sdk_response_headers' => 'sdk_headers_not_provider_attestation',
            'tls_transport_metadata' => 'tls_transport_not_write_attestation',
            'claimed_provider_signature' => 'claimed_signature_without_trusted_verifier',
        };

        // Local correlation can be known to agree, but no remote attestation
        // is available. The other boolean flags intentionally remain false.
        return [
            ...$this->result($class),
            'local_binding_consistent' => true,
        ];
    }
}
