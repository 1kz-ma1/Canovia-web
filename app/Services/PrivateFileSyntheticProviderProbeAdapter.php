<?php

namespace App\Services;

use RuntimeException;

/**
 * G3-R21: explicitly synthetic, in-memory HEAD-like probe fixture.
 * It does not read a provider, resolve credentials, or inspect Storage.
 * The generated observation remains untrusted input to reconciliation.
 */
final class PrivateFileSyntheticProviderProbeAdapter
{
    /** @return array<string,mixed> */
    public function observation(
        int $attemptId,
        string $requestToken,
        string $bucket,
        string $key,
        string $outcome,
        ?string $versionId = null,
        ?int $observedAt = null,
    ): array {
        if (! app()->environment('testing')
            || config('canovia.private_file_provider_reconciliation_enabled', false) !== true) {
            throw new RuntimeException('Synthetic provider probe unavailable.');
        }

        return [
            'origin' => 'synthetic_probe_v1',
            'attempt_id' => $attemptId,
            'request_token' => $requestToken,
            'disk' => 's3',
            'bucket' => $bucket,
            'key' => $key,
            'outcome' => $outcome,
            'observed_version_id' => $versionId,
            'observed_at' => $observedAt ?? now()->timestamp,
        ];
    }
}
