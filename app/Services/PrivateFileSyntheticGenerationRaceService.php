<?php

namespace App\Services;

/**
 * G3-R22: read-only, synthetic multi-observation race matrix.
 *
 * A sequence of HEAD-like samples is never a provider-authenticated history.
 * Even A -> A can conceal A -> B -> A between samples. Claimed provider
 * capabilities and writer exclusivity are NOT attestations.
 *
 * No Storage, SDK, network, DB write, conditional operation or deletion.
 */
final class PrivateFileSyntheticGenerationRaceService
{
    private const CLAIM_KEYS = [
        'versioning_mode', 'writer_coordination', 'conditional_generation',
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
            'generation_verified' => false,
            'writer_exclusivity_verified' => false,
            'race_free' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
            'network_requests' => false,
            'storage_mutations' => false,
        ];
    }

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_synthetic_race_matrix_enabled', false) === true
            && config('canovia.private_file_provider_reconciliation_enabled', false) === true
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('filesystems.default') === 's3'
            && config('filesystems.disks.s3.driver') === 's3';
    }

    /**
     * Inspect two to four deliberately supplied, ordered R21 observations.
     * The claim names below are UNTRUSTED fixture assertions, not provider
     * configuration or authentication. Never turn them into permissions.
     *
     * @param list<array<string,mixed>> $observations
     * @param array<string,mixed> $claims
     * @return array<string,bool|string>
     */
    public function assess(int $intentId, array $observations, array $claims): array
    {
        if (! $this->permitted() || $intentId < 1) {
            return $this->result('blocked');
        }

        $keys = array_keys($claims);
        $expected = self::CLAIM_KEYS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if (! array_is_list($observations)
            || count($observations) < 2 || count($observations) > 4
            || $keys !== $expected
            || ! in_array($claims['versioning_mode'], [
                'enabled', 'suspended', 'disabled', 'unknown',
            ], true)
            || ! in_array($claims['writer_coordination'], [
                'claimed_exclusive', 'external_possible', 'unknown',
            ], true)
            || ! in_array($claims['conditional_generation'], [
                'claimed_supported', 'unsupported', 'unknown',
            ], true)) {
            return $this->result('invalid_race_fixture');
        }

        $lastTime = null;
        $states = [];
        $versions = [];
        $presence = [];

        foreach ($observations as $observation) {
            if (! is_array($observation)
                || ! is_int($observation['observed_at'] ?? null)
                || ($lastTime !== null && $observation['observed_at'] <= $lastTime)) {
                return $this->result('invalid_race_fixture');
            }
            $lastTime = $observation['observed_at'];

            // R21 authenticates only journal-to-attempt bindings; it does not
            // authenticate the supplied object-store observation.
            $inspection = $this->reconciliation->inspect($intentId, $observation);
            $state = $inspection['state'];

            if (in_array($state, [
                'transport_outcome_unknown', 'provider_outcome_unknown',
            ], true)) {
                return $this->result('observation_gap_unresolved');
            }
            if (! in_array($state, [
                'version_match_unattested', 'version_mismatch_unattested',
                'present_without_durable_ack', 'present_generation_unknown',
                'absent_not_replay_safe',
            ], true)) {
                return $this->result('observation_binding_unverifiable');
            }
            $states[] = $state;
            $presence[] = $observation['outcome'];
            $versions[] = $observation['observed_version_id'];
        }

        // A claimed versioning mode cannot establish bucket versioning,
        // immutable generations or a provider-enforced conditional operation.
        if ($claims['versioning_mode'] !== 'enabled') {
            return $this->result('versioning_unverified_or_unsupported');
        }

        if (count(array_unique($presence)) > 1) {
            return $this->result('presence_transition_untrusted');
        }
        if (in_array('absent', $presence, true)) {
            return $this->result('absence_not_replay_safe');
        }
        if (in_array('present_generation_unknown', $states, true)) {
            return $this->result('generation_unknown');
        }

        // A -> B -> A is observable, but a two-point A -> A result does
        // not prove no intermediate write. All versions remain UNATTESTED.
        $first = $versions[0];
        if (count($versions) >= 3 && $first === $versions[count($versions) - 1]
            && count(array_unique($versions)) > 1) {
            return $this->result('aba_pattern_observed_untrusted');
        }
        if (count(array_unique($versions)) > 1) {
            return $this->result('generation_transition_untrusted');
        }

        if ($claims['writer_coordination'] === 'external_possible') {
            return $this->result('external_writer_unexcluded');
        }
        if ($claims['conditional_generation'] === 'unsupported') {
            return $this->result('conditional_generation_unavailable');
        }

        // This also covers claimed_exclusive + claimed_supported: both are
        // caller assertions and neither excludes unobserved ABA.
        return $this->result('equal_snapshots_aba_not_excluded');
    }
}
