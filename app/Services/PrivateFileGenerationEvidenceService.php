<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * G3-R17. An intentionally SYNTHETIC, storage-independent evidence envelope.
 *
 * It never captures provider responses, makes requests to S3, or authorizes
 * deletion. This pilot is limited to disposable PHP testing environments.
 */
final class PrivateFileGenerationEvidenceService
{
    private const TABLE = 'private_file_generation_evidence';
    private const INTENTS = 'private_file_upload_intents';

    private const OWNER_PATHS = [
        'inbox' => ['inbox_items', 'storage_path'],
        'recall' => ['study_recall_sources', 'storage_path'],
        'career' => ['career_captures', 'screenshot_path'],
    ];

    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
    ) {}

    private function permitted(): bool
    {
        return ! app()->environment('production')
            && config('canovia.private_file_generation_evidence_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true;
    }

    private function epoch(): string
    {
        $epoch = (string) config('canovia.private_file_generation_key_epoch', '');
        if ($epoch === '' || strlen($epoch) > 48
            || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $epoch)) {
            throw new RuntimeException('Generation evidence key epoch unavailable.');
        }

        return $epoch;
    }

    private function bucketDigest(): string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket', '');
        $key = (string) config('app.key', '');
        if ($bucket === '' || strlen($bucket) > 255 || $key === '') {
            throw new RuntimeException('Generation evidence bucket context unavailable.');
        }

        // The digest is only an identifier, not proof of bucket versioning
        // or IAM. Its key must be rotated with the legacy path HMAC key.
        return hash_hmac('sha256', "canovia-synthetic-generation-bucket-v1\0".$bucket, $key);
    }

    private function versionValid(string $version): bool
    {
        return trim($version) !== ''
            && strlen($version) <= 1024
            && strtolower($version) !== 'null'
            && ! str_contains($version, "\0");
    }

    private function schemaReady(): bool
    {
        foreach ([
            self::TABLE => [
                'id', 'upload_intent_id', 'path_digest', 'bucket_digest',
                'provider', 'owner_kind', 'owner_id', 'encrypted_version_id',
                'key_epoch', 'evidence_origin',
            ],
            self::INTENTS => ['id', 'status', 'disk', 'path_digest', 'encrypted_path'],
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

    /**
     * Create an isolated fixture linking a committed upload journal row to
     * the exact current owning DB row and one encrypted *synthetic* VersionId.
     *
     * @return int The inserted fixture row ID (not a provider generation).
     */
    public function recordSyntheticCandidate(
        int $intentId,
        string $ownerKind,
        int $ownerId,
        string $syntheticVersionId,
    ): int {
        if (! $this->permitted() || ! app()->environment('testing')) {
            throw new RuntimeException('Synthetic generation evidence recording disabled.');
        }
        if ($intentId < 1 || $ownerId < 1
            || ! isset(self::OWNER_PATHS[$ownerKind])
            || ! $this->versionValid($syntheticVersionId)
            || (string) config('filesystems.default') !== 's3'
            || (string) config('filesystems.disks.s3.driver') !== 's3'
            || DB::transactionLevel() !== 0
            || ! $this->schemaReady()) {
            throw new RuntimeException('Synthetic generation evidence inputs unavailable.');
        }

        [$table, $column] = self::OWNER_PATHS[$ownerKind];
        $epoch = $this->epoch();
        $bucket = $this->bucketDigest();

        try {
            return DB::transaction(function () use (
                $intentId, $ownerKind, $ownerId, $syntheticVersionId,
                $table, $column, $epoch, $bucket,
            ): int {
                $intent = DB::table(self::INTENTS)->where('id', $intentId)
                    ->lockForUpdate()->first(['status', 'disk', 'path_digest', 'encrypted_path']);
                if ($intent === null || $intent->status !== 'committed'
                    || $intent->disk !== 's3') {
                    throw new RuntimeException('Synthetic generation evidence intent invalid.');
                }
                $path = Crypt::decryptString((string) $intent->encrypted_path);
                $digest = $this->fence->fingerprint('s3', $path);
                if (! hash_equals($digest, (string) $intent->path_digest)) {
                    throw new RuntimeException('Synthetic generation evidence path identity invalid.');
                }

                $owner = DB::table($table)->where('id', $ownerId)
                    ->lockForUpdate()->first([$column]);
                if ($owner === null || $owner->{$column} !== $path) {
                    throw new RuntimeException('Synthetic generation evidence owner mismatch.');
                }

                return (int) DB::table(self::TABLE)->insertGetId([
                    'upload_intent_id' => $intentId,
                    'path_digest' => $digest,
                    'bucket_digest' => $bucket,
                    'provider' => 's3',
                    'owner_kind' => $ownerKind,
                    'owner_id' => $ownerId,
                    'encrypted_version_id' => Crypt::encryptString($syntheticVersionId),
                    'key_epoch' => $epoch,
                    'evidence_origin' => 'synthetic_fixture',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, 1);
        } catch (Throwable) {
            // Never expose a raw path, VersionId, ciphertext or DB exception
            // (which may contain encrypted binding parameters).
            throw new RuntimeException('Synthetic generation evidence recording unavailable.');
        }
    }

    /**
     * Read-only, fixed-label internal identity check. Even a successful
     * synthetic binding always remains NOT provider-verified.
     *
     * @return array{state:string,generation_verified:bool,delete_authorized:bool}
     */
    public function inspect(int $intentId): array
    {
        $state = 'blocked';
        $result = static fn (string $state): array => [
            'state' => $state,
            'generation_verified' => false,
            'delete_authorized' => false,
        ];

        if (! $this->permitted() || $intentId < 1) {
            return $result($state);
        }

        try {
            if (! $this->schemaReady()) {
                return $result('schema_unavailable');
            }
            $evidence = DB::table(self::TABLE)->where('upload_intent_id', $intentId)
                ->first([
                    'owner_kind', 'owner_id', 'provider', 'path_digest',
                    'bucket_digest', 'encrypted_version_id', 'key_epoch',
                    'evidence_origin',
                ]);
            if ($evidence === null) {
                return $result('legacy_or_missing_evidence');
            }
            if ($evidence->evidence_origin !== 'synthetic_fixture'
                || $evidence->provider !== 's3') {
                return $result('unsupported_evidence_origin');
            }
            if ($evidence->key_epoch !== $this->epoch()) {
                return $result('key_epoch_mismatch');
            }
            if (! hash_equals($this->bucketDigest(), (string) $evidence->bucket_digest)) {
                return $result('bucket_identity_mismatch');
            }

            $intent = DB::table(self::INTENTS)->where('id', $intentId)
                ->first(['status', 'disk', 'path_digest', 'encrypted_path']);
            if ($intent === null || $intent->status !== 'committed'
                || $intent->disk !== 's3') {
                return $result('intent_not_committed');
            }
            $path = Crypt::decryptString((string) $intent->encrypted_path);
            $digest = $this->fence->fingerprint('s3', $path);
            if (! hash_equals($digest, (string) $intent->path_digest)
                || ! hash_equals($digest, (string) $evidence->path_digest)) {
                return $result('path_identity_mismatch');
            }
            $version = Crypt::decryptString((string) $evidence->encrypted_version_id);
            if (! $this->versionValid($version)) {
                return $result('version_invalid');
            }

            if (! isset(self::OWNER_PATHS[$evidence->owner_kind])) {
                return $result('owner_identity_invalid');
            }
            [$table, $column] = self::OWNER_PATHS[$evidence->owner_kind];
            $owner = DB::table($table)->where('id', $evidence->owner_id)
                ->first([$column]);
            if ($owner === null || $owner->{$column} !== $path) {
                return $result('owner_reference_changed');
            }

            return $result('synthetic_binding_not_provider_verified');
        } catch (Throwable) {
            return $result('identity_unverifiable');
        }
    }

    /** No private identifiers or decrypted payloads in aggregate output. */
    public function report(): array
    {
        $result = [
            'schema' => 'not_checked',
            'status' => 'blocked',
            'synthetic_count' => 0,
            'generation_verified' => false,
            'delete_authorized' => false,
        ];
        if (! $this->permitted()) {
            return $result;
        }
        try {
            if (! $this->schemaReady()) {
                return [...$result, 'schema' => 'unavailable', 'status' => 'unavailable'];
            }
            return [
                ...$result,
                'schema' => 'ready',
                'status' => 'synthetic_only',
                'synthetic_count' => DB::table(self::TABLE)
                    ->where('evidence_origin', 'synthetic_fixture')->count(),
            ];
        } catch (Throwable) {
            return [...$result, 'schema' => 'unavailable', 'status' => 'unavailable'];
        }
    }
}
