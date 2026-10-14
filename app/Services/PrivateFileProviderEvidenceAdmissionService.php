<?php

namespace App\Services;

/**
 * G3-R25: deny-only admission for a future independently verified write proof.
 *
 * All inputs are synthetic and caller-controlled. The sole wired verifier is
 * a final unavailable implementation. Passing every claimed capability is
 * NOT provider authentication, generation fencing or action authorization.
 * This service performs no provider, Storage or DB writes.
 */
final class PrivateFileProviderEvidenceAdmissionService
{
    private const REQUIRED = [
        'origin', 'provider', 'attempt_id', 'request_id', 'version_id',
        'bucket', 'key', 'trust_root', 'write_binding', 'anti_replay',
        'bucket_versioning', 'writer_isolation', 'generation_preconditions',
        'observed_at',
    ];

    private const INDEPENDENT_CHECKS = [
        'provider_identity_and_trust_root',
        'response_authenticity',
        'request_and_generation_binding',
        'anti_replay_continuity',
        'bucket_versioning_and_iam',
        'external_writer_fencing',
        'generation_conditional_operations',
    ];

    public function __construct(
        private readonly PrivateFileProviderAttestationBoundaryService $boundary,
        private readonly PrivateFileUnavailableProviderVerifier $unavailableVerifier,
    ) {}

    /**
     * @return array{state:string,missing_independent_checks:list<string>,local_binding_consistent:bool,independent_provider_verifier_available:bool,provider_authenticated:bool,generation_write_bound:bool,writer_exclusivity_verified:bool,retry_authorized:bool,delete_authorized:bool,network_requests:bool,storage_mutations:bool}
     */
    private function result(string $state, bool $localBinding = false): array
    {
        return [
            'state' => $state,
            'missing_independent_checks' => self::INDEPENDENT_CHECKS,
            'local_binding_consistent' => $localBinding,
            'independent_provider_verifier_available' => false,
            'provider_authenticated' => false,
            'generation_write_bound' => false,
            'writer_exclusivity_verified' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
            'network_requests' => false,
            'storage_mutations' => false,
        ];
    }

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_provider_evidence_admission_enabled', false) === true
            && config('canovia.private_file_provider_attestation_boundary_enabled', false) === true
            && config('canovia.private_file_synthetic_write_provenance_enabled', false) === true
            && config('canovia.private_file_provider_reconciliation_enabled', false) === true
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('filesystems.default') === 's3'
            && config('filesystems.disks.s3.driver') === 's3';
    }

    private function validCandidate(array $candidate): bool
    {
        $keys = array_keys($candidate);
        $expected = self::REQUIRED;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected
            && ($candidate['origin'] ?? null) === 'synthetic_verifier_candidate_v1'
            && ($candidate['provider'] ?? null) === 's3_claimed'
            && is_int($candidate['attempt_id'] ?? null)
            && is_string($candidate['request_id'] ?? null)
            && is_string($candidate['version_id'] ?? null)
            && is_string($candidate['bucket'] ?? null)
            && is_string($candidate['key'] ?? null)
            && in_array($candidate['trust_root'], ['claimed', 'missing', 'unknown'], true)
            && in_array($candidate['write_binding'], ['claimed', 'missing', 'unknown'], true)
            && in_array($candidate['anti_replay'], ['claimed', 'missing', 'unknown'], true)
            && in_array($candidate['bucket_versioning'], [
                'enabled_claimed', 'suspended', 'disabled', 'unknown',
            ], true)
            && in_array($candidate['writer_isolation'], ['claimed', 'missing', 'unknown'], true)
            && in_array($candidate['generation_preconditions'], [
                'claimed', 'missing', 'unknown',
            ], true)
            && is_int($candidate['observed_at'] ?? null);
    }

    /**
     * @param array<string,mixed> $receipt R23 synthetic response
     * @param array<string,mixed> $observation R21 synthetic observation
     * @param array<string,mixed> $capabilities R23 untrusted capability claims
     * @param array<string,mixed> $assertion R24 synthetic provenance claim
     * @param array<string,mixed> $candidate Strict R25 untrusted candidate
     */
    public function review(
        int $intentId,
        array $receipt,
        array $observation,
        array $capabilities,
        array $assertion,
        array $candidate,
    ): array {
        if (! $this->permitted() || $intentId < 1) {
            return $this->result('blocked');
        }

        if (! $this->validCandidate($candidate)) {
            return $this->result('invalid_evidence_candidate');
        }

        if (! is_int($receipt['attempt_id'] ?? null)
            || ! is_string($receipt['request_id'] ?? null)
            || ! is_string($receipt['version_id'] ?? null)
            || ! is_string($receipt['bucket'] ?? null)
            || ! is_string($receipt['key'] ?? null)
            || ! is_int($receipt['received_at'] ?? null)
            || $candidate['attempt_id'] !== $receipt['attempt_id']
            || ! hash_equals($candidate['request_id'], $receipt['request_id'])
            || ! hash_equals($candidate['version_id'], $receipt['version_id'])
            || ! hash_equals($candidate['bucket'], $receipt['bucket'])
            || ! hash_equals($candidate['key'], $receipt['key'])) {
            return $this->result('candidate_scope_unverifiable');
        }

        $now = now()->timestamp;
        if ($candidate['observed_at'] < $now - 300
            || $candidate['observed_at'] > $now + 5
            || $candidate['observed_at'] < $receipt['received_at']) {
            return $this->result('candidate_timing_unverifiable');
        }

        $checked = $this->boundary->classify(
            $intentId, $receipt, $observation, $capabilities, $assertion,
        );
        if (($checked['local_binding_consistent'] ?? false) !== true) {
            return $this->result('prior_local_chain_unverifiable');
        }

        foreach (['trust_root', 'write_binding', 'anti_replay',
            'writer_isolation', 'generation_preconditions'] as $field) {
            if ($candidate[$field] !== 'claimed') {
                return $this->result('independent_requirements_unmet', true);
            }
        }
        if ($candidate['bucket_versioning'] !== 'enabled_claimed') {
            return $this->result('independent_requirements_unmet', true);
        }

        // A future independently reviewed verifier must be separately wired,
        // tested and approved. This concrete final verifier cannot authenticate
        // any caller-controlled envelope, including all-claimed fixtures.
        $verification = $this->unavailableVerifier->verify($candidate);
        if ($verification['verdict'] !== 'independent_verifier_unavailable'
            || $verification['provider_authenticated'] !== false
            || $verification['generation_write_bound'] !== false) {
            return $this->result('independent_verifier_contract_violation', true);
        }

        return $this->result('independent_verifier_unavailable', true);
    }
}
