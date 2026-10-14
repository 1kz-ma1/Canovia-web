<?php

namespace App\Services;

use App\Contracts\PrivateFileIndependentProviderVerifier;

/**
 * G3-R26: strict synthetic trust-epoch/time/replay preflight; deny-only.
 *
 * Caller-controlled roots and previous observations are NOT a trust store,
 * persistent replay ledger, provider signature, or a runtime verifier.
 * No new provider integration, Storage I/O, DB writes, retry or deletion.
 */
final class PrivateFileTrustReplayPreflightService
{
    private const FIELDS = [
        'origin', 'issuer_claim', 'trust_root_claim', 'root_epoch',
        'attempt_id', 'request_id', 'version_id', 'nonce', 'sequence',
        'issued_at', 'expires_at',
    ];

    private const PREVIOUS_FIELDS = [
        'origin', 'issuer_claim', 'trust_root_claim', 'root_epoch',
        'attempt_id', 'nonce', 'sequence', 'issued_at',
    ];

    private function result(string $state, bool $local = false): array
    {
        return [
            'state' => $state,
            'local_binding_consistent' => $local,
            'trusted_root_verified' => false,
            'replay_protection_verified' => false,
            'independent_verifier_available' => false,
            'provider_authenticated' => false,
            'generation_write_bound' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
            'network_requests' => false,
            'storage_mutations' => false,
        ];
    }

    private function enabled(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_trust_replay_preflight_enabled', false) === true
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

    private function exact(array $values, array $fields): bool
    {
        $a = array_keys($values);
        sort($a, SORT_STRING);
        sort($fields, SORT_STRING);

        return $a === $fields;
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $value) === 1;
    }

    private function validEnvelope(array $e): bool
    {
        return $this->exact($e, self::FIELDS)
            && ($e['origin'] ?? null) === 'synthetic_trust_replay_envelope_v1'
            && ($e['issuer_claim'] ?? null) === 's3_claimed'
            && $this->identifier($e['trust_root_claim'] ?? null)
            && $this->identifier($e['root_epoch'] ?? null)
            && is_int($e['attempt_id'] ?? null) && $e['attempt_id'] > 0
            && $this->identifier($e['request_id'] ?? null)
            && $this->identifier($e['version_id'] ?? null)
            && $this->identifier($e['nonce'] ?? null)
            && is_int($e['sequence'] ?? null) && $e['sequence'] > 0
            && is_int($e['issued_at'] ?? null)
            && is_int($e['expires_at'] ?? null);
    }

    private function validPrevious(array $p): bool
    {
        return $this->exact($p, self::PREVIOUS_FIELDS)
            && ($p['origin'] ?? null) === 'synthetic_prior_untrusted_v1'
            && ($p['issuer_claim'] ?? null) === 's3_claimed'
            && $this->identifier($p['trust_root_claim'] ?? null)
            && $this->identifier($p['root_epoch'] ?? null)
            && is_int($p['attempt_id'] ?? null) && $p['attempt_id'] > 0
            && $this->identifier($p['nonce'] ?? null)
            && is_int($p['sequence'] ?? null) && $p['sequence'] > 0
            && is_int($p['issued_at'] ?? null);
    }

    /**
     * R25 is resolved only AFTER checking app-container substitution, never
     * injected as a replaceable interface and never granted a success path.
     *
     * @param array<string,mixed> $r25Receipt
     * @param array<string,mixed> $r21Observation
     * @param array<string,mixed> $r23Capabilities
     * @param array<string,mixed> $r24Assertion
     * @param array<string,mixed> $r25Candidate
     * @param array<string,mixed> $envelope Untrusted synthetic root/time claim
     * @param array<string,mixed>|null $previous Untrusted caller-provided history
     */
    public function inspect(
        int $intentId,
        array $r25Receipt,
        array $r21Observation,
        array $r23Capabilities,
        array $r24Assertion,
        array $r25Candidate,
        array $envelope,
        ?array $previous,
    ): array {
        if (! $this->enabled() || $intentId < 1) {
            return $this->result('blocked');
        }

        // Detect obvious DI rebinding before any resolution. This is NOT
        // protection against code execution or container compromise; any such
        // modification still requires a reviewed release boundary.
        if (app()->bound(PrivateFileIndependentProviderVerifier::class)
            || app()->bound(PrivateFileUnavailableProviderVerifier::class)
            || app()->bound(PrivateFileProviderEvidenceAdmissionService::class)) {
            return $this->result('verifier_override_detected');
        }

        if (! $this->validEnvelope($envelope)) {
            return $this->result('invalid_trust_envelope');
        }

        if (! is_int($r25Receipt['attempt_id'] ?? null)
            || ! is_string($r25Receipt['request_id'] ?? null)
            || ! is_string($r25Receipt['version_id'] ?? null)
            || $envelope['attempt_id'] !== $r25Receipt['attempt_id']
            || ! hash_equals($envelope['request_id'], $r25Receipt['request_id'])
            || ! hash_equals($envelope['version_id'], $r25Receipt['version_id'])) {
            return $this->result('trust_envelope_scope_unverifiable');
        }

        $now = now()->timestamp;
        // Tight bounded fixture TTL; an untrusted timestamp cannot prove a
        // provider issued anything at the declared time.
        if ($envelope['issued_at'] < $now - 300
            || $envelope['issued_at'] > $now + 5
            || $envelope['expires_at'] <= $now
            || $envelope['expires_at'] - $envelope['issued_at'] > 60
            || $envelope['expires_at'] <= $envelope['issued_at']) {
            return $this->result('untrusted_envelope_expired_or_future');
        }

        if ($previous === null) {
            return $this->result('replay_history_unavailable');
        }
        if (! $this->validPrevious($previous)) {
            return $this->result('invalid_previous_fixture');
        }
        if ($previous['attempt_id'] !== $envelope['attempt_id']
            || ! hash_equals($previous['issuer_claim'], $envelope['issuer_claim'])
            || ! hash_equals($previous['trust_root_claim'], $envelope['trust_root_claim'])
            || ! hash_equals($previous['root_epoch'], $envelope['root_epoch'])) {
            return $this->result('untrusted_root_or_history_changed');
        }
        if (hash_equals($previous['nonce'], $envelope['nonce'])) {
            return $this->result('duplicate_nonce_observed_untrusted');
        }
        if ($previous['sequence'] >= $envelope['sequence']
            || $previous['issued_at'] >= $envelope['issued_at']) {
            return $this->result('out_of_order_fixture');
        }

        $local = app(PrivateFileProviderEvidenceAdmissionService::class)
            ->review($intentId, $r25Receipt, $r21Observation,
                $r23Capabilities, $r24Assertion, $r25Candidate);

        if (($local['state'] ?? null) !== 'independent_verifier_unavailable'
            || ($local['local_binding_consistent'] ?? null) !== true
            || ($local['provider_authenticated'] ?? null) !== false
            || ($local['retry_authorized'] ?? null) !== false
            || ($local['delete_authorized'] ?? null) !== false) {
            return $this->result('prior_local_chain_unverifiable');
        }

        // Neither root nor history is independently verified/persistent.
        // Even a non-duplicate synthetic sequence cannot prove replay safety.
        return $this->result('untrusted_history_not_replay_proof', true);
    }
}
