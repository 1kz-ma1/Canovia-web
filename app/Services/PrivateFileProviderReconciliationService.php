<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * G3-R21: read-only classification of UNTRUSTED synthetic provider observations.
 *
 * Neither a HEAD-like observation nor an equal VersionId attests the original
 * PutObject. External writers, ABA, delete markers and provider inconsistency
 * make automatic retry and deletion unsafe, even after a match.
 *
 * No SDK, Storage facade, provider network traffic, or DB writes.
 */
final class PrivateFileProviderReconciliationService
{
    private const ATTEMPTS = 'private_file_write_attempts';
    private const INTENTS = 'private_file_upload_intents';
    private const ORIGIN = 'synthetic_probe_v1';
    private const REQUIRED = [
        'origin', 'attempt_id', 'request_token', 'disk', 'bucket',
        'key', 'outcome', 'observed_version_id', 'observed_at',
    ];

    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
    ) {}

    /** @return array{state:string,provider_attested:bool,generation_verified:bool,retry_authorized:bool,delete_authorized:bool} */
    private function result(string $state): array
    {
        return [
            'state' => $state,
            'provider_attested' => false,
            'generation_verified' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
        ];
    }

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_provider_reconciliation_enabled', false) === true
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('filesystems.default') === 's3'
            && config('filesystems.disks.s3.driver') === 's3';
    }

    private function versionValid(?string $version): bool
    {
        return is_string($version) && trim($version) !== ''
            && strlen($version) <= 1024
            && strtolower($version) !== 'null'
            && ! str_contains($version, "\0");
    }

    /**
     * Reconcile a disposable fixture only. All payload fields are untrusted.
     * The request token is a transient test value, never a provider proof.
     *
     * @param array<string,mixed> $observation
     */
    public function inspect(int $intentId, array $observation): array
    {
        if (! $this->permitted() || $intentId < 1) {
            return $this->result('blocked');
        }

        $keys = array_keys($observation);
        sort($keys, SORT_STRING);
        $required = self::REQUIRED;
        sort($required, SORT_STRING);
        $outcome = $observation['outcome'] ?? null;
        $version = $observation['observed_version_id'] ?? null;

        if ($keys !== $required || ($observation['origin'] ?? null) !== self::ORIGIN
            || ! is_int($observation['attempt_id'] ?? null)
            || ! is_string($observation['request_token'] ?? null)
            || ! preg_match('/^[a-f0-9]{48}$/D', $observation['request_token'])
            || ($observation['disk'] ?? null) !== 's3'
            || ! is_string($observation['bucket'] ?? null)
            || ! is_string($observation['key'] ?? null)
            || ! in_array($outcome, ['present', 'absent', 'unknown', 'timeout', 'network_error'], true)
            || ! is_int($observation['observed_at'] ?? null)
            || ($outcome !== 'present' && $version !== null)
            || ($outcome === 'present' && $version !== null && ! is_string($version))) {
            return $this->result('invalid_observation');
        }

        // Five minutes maximum age; reject future-dated observations, too.
        // This is necessary but NOT sufficient to make a sample race-free.
        $now = now()->timestamp;
        if ($observation['observed_at'] < $now - 300
            || $observation['observed_at'] > $now + 5) {
            return $this->result('stale_or_future_observation');
        }

        try {
            foreach ([
                self::ATTEMPTS => [
                    'id', 'upload_intent_id', 'path_digest', 'bucket_digest',
                    'request_token_digest', 'key_epoch', 'status',
                    'encrypted_synthetic_version_id', 'created_at',
                ],
                self::INTENTS => ['id', 'disk', 'status', 'encrypted_path', 'path_digest'],
            ] as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    return $this->result('schema_unavailable');
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        return $this->result('schema_unavailable');
                    }
                }
            }

            $key = (string) config('app.key', '');
            $bucket = (string) config('filesystems.disks.s3.bucket', '');
            $epoch = (string) config('canovia.private_file_generation_key_epoch', '');
            if ($key === '' || $bucket === '' || strlen($bucket) > 255
                || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,47}$/D', $epoch)) {
                return $this->result('binding_unverifiable');
            }

            $attempt = DB::table(self::ATTEMPTS)
                ->where('upload_intent_id', $intentId)
                ->first([
                    'id', 'path_digest', 'bucket_digest', 'request_token_digest',
                    'key_epoch', 'status', 'encrypted_synthetic_version_id', 'created_at',
                ]);
            if ($attempt === null) {
                return $this->result('missing_attempt');
            }

            // Reject observations from before reservation, including a
            // pre-recorded response re-used after a later attempt.
            $reservedAt = strtotime((string) $attempt->created_at);
            if ($reservedAt === false
                || $observation['observed_at'] < $reservedAt) {
                return $this->result('stale_or_future_observation');
            }

            $bucketDigest = hash_hmac('sha256',
                "canovia-r20-synthetic-bucket-v1\0".$bucket, $key);
            $tokenDigest = hash_hmac('sha256',
                "canovia-r20-synthetic-request-v1\0".$observation['request_token'], $key);

            if ((int) $attempt->id !== $observation['attempt_id']
                || ! hash_equals($bucket, $observation['bucket'])
                || $attempt->key_epoch !== $epoch
                || ! hash_equals($bucketDigest, (string) $attempt->bucket_digest)
                || ! hash_equals($tokenDigest, (string) $attempt->request_token_digest)) {
                return $this->result('binding_unverifiable');
            }

            $intent = DB::table(self::INTENTS)->where('id', $intentId)
                ->first(['disk', 'status', 'encrypted_path', 'path_digest']);
            if ($intent === null || $intent->disk !== 's3'
                || ! in_array($intent->status, ['prepared', 'committed', 'needs_review'], true)) {
                return $this->result('journal_unverifiable');
            }
            $path = Crypt::decryptString((string) $intent->encrypted_path);
            $digest = $this->fence->fingerprint('s3', $path);
            if (! hash_equals($digest, (string) $intent->path_digest)
                || ! hash_equals($digest, (string) $attempt->path_digest)
                || ! hash_equals($path, $observation['key'])) {
                return $this->result('binding_unverifiable');
            }

            $ackVersion = null;
            if ($attempt->status === 'reserved'
                && $attempt->encrypted_synthetic_version_id === null) {
                // The write might have reached the provider despite no ack.
            } elseif ($attempt->status === 'synthetic_ack_recorded'
                && is_string($attempt->encrypted_synthetic_version_id)) {
                $ackVersion = Crypt::decryptString($attempt->encrypted_synthetic_version_id);
                if (! $this->versionValid($ackVersion)) {
                    return $this->result('attempt_unverifiable');
                }
            } else {
                return $this->result('attempt_unverifiable');
            }

            return match ($outcome) {
                'network_error', 'timeout' => $this->result('transport_outcome_unknown'),
                'unknown' => $this->result('provider_outcome_unknown'),
                'absent' => $this->result('absent_not_replay_safe'),
                'present' => ! $this->versionValid($version)
                    ? $this->result('present_generation_unknown')
                    : ($ackVersion === null
                        ? $this->result('present_without_durable_ack')
                        : $this->result(hash_equals($ackVersion, $version)
                            ? 'version_match_unattested'
                            : 'version_mismatch_unattested')),
            };
        } catch (Throwable) {
            // DB/cipher failures must never echo private locations or tokens.
            return $this->result('unverifiable');
        }
    }
}
