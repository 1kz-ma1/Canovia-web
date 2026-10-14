<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * G3-R20. Test-only write-ahead attempt envelope.
 *
 * A successful durable reservation proves ONLY that an attempt was planned.
 * Losing the process after provider write and before receipt persistence is
 * irreducibly ambiguous without separate provider-side verification.
 * No Storage API, real provider response, retry, or delete is implemented.
 */
final class PrivateFileWriteAttemptService
{
    private const TABLE = 'private_file_write_attempts';

    private const JOURNAL = 'private_file_upload_intents';

    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
    ) {}

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_write_attempt_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && (string) config('filesystems.default') === 's3'
            && (string) config('filesystems.disks.s3.driver') === 's3';
    }

    private function schemaReady(): bool
    {
        foreach ([
            self::TABLE => [
                'id', 'upload_intent_id', 'path_digest', 'bucket_digest',
                'request_token_digest', 'key_epoch', 'status',
                'encrypted_synthetic_version_id',
            ],
            self::JOURNAL => ['id', 'status', 'disk', 'path_digest', 'encrypted_path'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function context(): array
    {
        $key = (string) config('app.key', '');
        $bucket = (string) config('filesystems.disks.s3.bucket', '');
        $epoch = (string) config('canovia.private_file_generation_key_epoch', '');

        if ($key === '' || $bucket === '' || strlen($bucket) > 255
            || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,47}$/D', $epoch)) {
            throw new RuntimeException('Write-attempt context unavailable.');
        }

        return [
            $key,
            hash_hmac('sha256', "canovia-r20-synthetic-bucket-v1\0".$bucket, $key),
            $epoch,
        ];
    }

    private function digest(string $key, string $token): string
    {
        return hash_hmac('sha256', "canovia-r20-synthetic-request-v1\0".$token, $key);
    }

    private function versionValid(string $version): bool
    {
        return trim($version) !== ''
            && strlen($version) <= 1024
            && strtolower($version) !== 'null'
            && ! str_contains($version, "\0");
    }

    /**
     * A committed intent MUST exist BEFORE this method. The method commits
     * the attempt row independently, BEFORE any hypothetical provider call.
     * The opaque token is process-local; it is never printed or stored raw.
     *
     * @return array{attempt_id:int,request_token:string}
     */
    public function reserveSynthetic(int $intentId): array
    {
        if (! $this->permitted() || $intentId < 1
            || DB::transactionLevel() !== 0 || ! $this->schemaReady()) {
            throw new RuntimeException('Synthetic write-attempt reservation unavailable.');
        }

        try {
            [$key, $bucketDigest, $epoch] = $this->context();
            $token = bin2hex(random_bytes(24));
            $id = DB::transaction(function () use (
                $intentId, $bucketDigest, $epoch, $key, $token,
            ): int {
                $intent = DB::table(self::JOURNAL)->where('id', $intentId)
                    ->lockForUpdate()->first([
                        'status', 'disk', 'encrypted_path', 'path_digest',
                    ]);
                if ($intent === null || $intent->status !== 'prepared'
                    || $intent->disk !== 's3') {
                    throw new RuntimeException('Synthetic write-attempt journal invalid.');
                }
                $path = Crypt::decryptString((string) $intent->encrypted_path);
                $digest = $this->fence->fingerprint('s3', $path);
                if (! hash_equals($digest, (string) $intent->path_digest)) {
                    throw new RuntimeException('Synthetic write-attempt identity invalid.');
                }

                // Unique upload_intent_id is a durable lifetime tombstone:
                // no new attempt for an ambiguous key after a crash.
                return (int) DB::table(self::TABLE)->insertGetId([
                    'upload_intent_id' => $intentId,
                    'path_digest' => $digest,
                    'bucket_digest' => $bucketDigest,
                    'request_token_digest' => $this->digest($key, $token),
                    'key_epoch' => $epoch,
                    'status' => 'reserved',
                    'encrypted_synthetic_version_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, 1);

            return ['attempt_id' => $id, 'request_token' => $token];
        } catch (Throwable) {
            // Never expose request tokens, raw path, bucket, or DB messages.
            throw new RuntimeException('Synthetic write-attempt reservation unavailable.');
        }
    }

    /**
     * Simulate independently persisting an acknowledgement BEFORE owner
     * creation or journal settlement. Does not prove a provider returned it.
     *
     * @return array{state:string,provider_attested:bool,
     *     retry_authorized:bool,delete_authorized:bool}
     */
    public function recordSyntheticAck(
        int $attemptId,
        string $requestToken,
        string $syntheticVersionId,
    ): array {
        $result = static fn (string $state): array => [
            'state' => $state,
            'provider_attested' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
        ];

        if (! $this->permitted() || $attemptId < 1
            || ! preg_match('/^[a-f0-9]{48}$/D', $requestToken)
            || ! $this->versionValid($syntheticVersionId)
            || DB::transactionLevel() !== 0) {
            return $result('blocked_or_invalid');
        }

        try {
            if (! $this->schemaReady()) {
                return $result('schema_unavailable');
            }
            [$key, $bucketDigest, $epoch] = $this->context();

            return DB::transaction(function () use (
                $attemptId, $requestToken, $syntheticVersionId,
                $key, $bucketDigest, $epoch, $result,
            ): array {
                $attempt = DB::table(self::TABLE)->where('id', $attemptId)
                    ->lockForUpdate()->first([
                        'upload_intent_id', 'path_digest', 'bucket_digest',
                        'request_token_digest', 'key_epoch', 'status',
                    ]);
                if ($attempt === null || $attempt->status !== 'reserved') {
                    return $result('not_reserved_or_missing');
                }
                if (! hash_equals((string) $attempt->request_token_digest,
                        $this->digest($key, $requestToken))
                    || $attempt->key_epoch !== $epoch
                    || ! hash_equals((string) $attempt->bucket_digest, $bucketDigest)) {
                    return $result('binding_unverifiable');
                }

                $intent = DB::table(self::JOURNAL)
                    ->where('id', $attempt->upload_intent_id)
                    ->lockForUpdate()->first([
                        'status', 'disk', 'encrypted_path', 'path_digest',
                    ]);
                if ($intent === null || $intent->status !== 'prepared'
                    || $intent->disk !== 's3') {
                    return $result('journal_unavailable');
                }
                $path = Crypt::decryptString((string) $intent->encrypted_path);
                $digest = $this->fence->fingerprint('s3', $path);
                if (! hash_equals($digest, (string) $intent->path_digest)
                    || ! hash_equals($digest, (string) $attempt->path_digest)) {
                    return $result('binding_unverifiable');
                }

                $affected = DB::table(self::TABLE)->where('id', $attemptId)
                    ->where('status', 'reserved')->update([
                        'status' => 'synthetic_ack_recorded',
                        'encrypted_synthetic_version_id' => Crypt::encryptString($syntheticVersionId),
                        'updated_at' => now(),
                    ]);
                return $result($affected === 1
                    ? 'synthetic_ack_durable_not_attested' : 'not_reserved_or_missing');
            }, 1);
        } catch (Throwable) {
            return $result('unverifiable');
        }
    }

    /**
     * Redacted, SELECT-only review. A prepared attempt can mean "never sent"
     * OR "provider succeeded and process died before receipt commit".
     *
     * @return array{state:string,provider_attested:bool,
     *     retry_authorized:bool,delete_authorized:bool}
     */
    public function review(int $intentId): array
    {
        $result = static fn (string $state): array => [
            'state' => $state,
            'provider_attested' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
        ];

        if (! $this->permitted() || $intentId < 1) {
            return $result('blocked');
        }

        try {
            if (! $this->schemaReady()) {
                return $result('schema_unavailable');
            }
            [$key, $bucketDigest, $epoch] = $this->context();
            $attempt = DB::table(self::TABLE)
                ->where('upload_intent_id', $intentId)
                ->first([
                    'status', 'path_digest', 'bucket_digest',
                    'request_token_digest', 'key_epoch',
                    'encrypted_synthetic_version_id',
                ]);
            if ($attempt === null) {
                return $result('legacy_or_missing_attempt');
            }
            if ($attempt->key_epoch !== $epoch
                || ! hash_equals($bucketDigest, (string) $attempt->bucket_digest)
                || ! preg_match('/^[a-f0-9]{64}$/D', (string) $attempt->request_token_digest)) {
                return $result('binding_unverifiable');
            }
            $intent = DB::table(self::JOURNAL)->where('id', $intentId)
                ->first(['status', 'disk', 'encrypted_path', 'path_digest']);
            if ($intent === null || $intent->disk !== 's3'
                || ! in_array($intent->status, ['prepared', 'committed', 'needs_review'], true)) {
                return $result('journal_unverifiable');
            }
            $path = Crypt::decryptString((string) $intent->encrypted_path);
            $digest = $this->fence->fingerprint('s3', $path);
            if (! hash_equals($digest, (string) $intent->path_digest)
                || ! hash_equals($digest, (string) $attempt->path_digest)) {
                return $result('binding_unverifiable');
            }

            if ($attempt->status === 'reserved'
                && $attempt->encrypted_synthetic_version_id === null) {
                return $result('reserved_outcome_unknown');
            }
            if ($attempt->status === 'synthetic_ack_recorded'
                && is_string($attempt->encrypted_synthetic_version_id)
                && $this->versionValid(Crypt::decryptString(
                    $attempt->encrypted_synthetic_version_id,
                ))) {
                return $result('synthetic_ack_durable_not_attested');
            }
            return $result('attempt_state_unverifiable');
        } catch (Throwable) {
            return $result('unverifiable');
        }
    }

    /** Only aggregate fixed counts, no tenant, file or attempt identifiers. */
    public function report(): array
    {
        $base = [
            'status' => 'blocked',
            'schema' => 'not_checked',
            'reserved' => 0,
            'synthetic_ack_recorded' => 0,
            'provider_attested' => false,
            'retry_authorized' => false,
            'delete_authorized' => false,
        ];
        if (! $this->permitted()) {
            return $base;
        }
        try {
            if (! $this->schemaReady()) {
                return [...$base, 'status' => 'unavailable', 'schema' => 'unavailable'];
            }
            return [
                ...$base,
                'status' => 'synthetic_only',
                'schema' => 'ready',
                'reserved' => DB::table(self::TABLE)->where('status', 'reserved')->count(),
                'synthetic_ack_recorded' => DB::table(self::TABLE)
                    ->where('status', 'synthetic_ack_recorded')->count(),
            ];
        } catch (Throwable) {
            return [...$base, 'status' => 'unavailable', 'schema' => 'unavailable'];
        }
    }
}
