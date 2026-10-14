<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * G3-R18: strictly synthetic write-receipt handoff into the existing
 * synthetic-only owner/evidence pilot.
 *
 * Absolutely no S3 SDK, remote object calls, storage writes, deletes,
 * upload adapter registrations, or real provider provenance claims.
 */
final class PrivateFileWriteReceiptHandoffService
{
    private const ORIGIN = 'synthetic_adapter_v1';

    private const FIELDS = [
        'origin', 'operation', 'status_code', 'intent_id', 'owner_kind',
        'owner_id', 'disk', 'bucket', 'key', 'version_id', 'request_id',
    ];

    public function __construct(
        private readonly PrivateFileGenerationEvidenceService $evidence,
        private readonly PrivateFilePathFenceService $fence,
    ) {}

    /**
     * This method intentionally has no live/production access path.
     *
     * All receipt fields are fixture-supplied and UNTRUSTED. Even a
     * successfully recorded row has evidence_origin=synthetic_fixture
     * and may NEVER be treated as authenticated provider proof.
     *
     * @param array<string,mixed> $receipt
     * @return array{status:string,provider_receipt_authenticated:bool,
     *     generation_verified:bool,delete_authorized:bool}
     */
    public function handoffSynthetic(
        int $intentId,
        string $ownerKind,
        int $ownerId,
        array $receipt,
    ): array {
        $result = static fn (string $status): array => [
            'status' => $status,
            'provider_receipt_authenticated' => false,
            'generation_verified' => false,
            'delete_authorized' => false,
        ];

        if (! app()->environment('testing')
            || config('canovia.private_file_write_receipt_handoff_enabled', false) !== true
            || config('canovia.private_file_generation_evidence_enabled', false) !== true
            || config('canovia.private_file_path_fence_enabled', false) !== true
            || config('canovia.private_file_upload_journal_enabled', false) !== true
            || $intentId < 1 || $ownerId < 1
            || ! in_array($ownerKind, ['inbox', 'recall', 'career'], true)) {
            return $result('blocked');
        }

        // Reject unknown fields rather than allowing accidental SDK response
        // keys (including user claims about authorization) into the pilot.
        $keys = array_keys($receipt);
        sort($keys, SORT_STRING);
        $allowed = self::FIELDS;
        sort($allowed, SORT_STRING);
        if ($keys !== $allowed
            || ($receipt['origin'] ?? null) !== self::ORIGIN
            || ($receipt['operation'] ?? null) !== 'PutObject'
            || ($receipt['status_code'] ?? null) !== 200
            || ($receipt['intent_id'] ?? null) !== $intentId
            || ($receipt['owner_kind'] ?? null) !== $ownerKind
            || ($receipt['owner_id'] ?? null) !== $ownerId
            || ($receipt['disk'] ?? null) !== 's3'
            || ! is_string($receipt['bucket'] ?? null)
            || ! is_string($receipt['key'] ?? null)
            || ! is_string($receipt['version_id'] ?? null)
            || ! is_string($receipt['request_id'] ?? null)
            || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $receipt['request_id'])
            || strlen($receipt['version_id']) > 1024
            || trim($receipt['version_id']) === ''
            || strtolower($receipt['version_id']) === 'null'
            || str_contains($receipt['version_id'], "\0")) {
            return $result('receipt_shape_rejected');
        }

        try {
            if ((string) config('filesystems.default') !== 's3'
                || (string) config('filesystems.disks.s3.driver') !== 's3') {
                return $result('provider_configuration_unavailable');
            }

            $bucket = (string) config('filesystems.disks.s3.bucket', '');
            if ($bucket === ''
                || ! hash_equals($bucket, $receipt['bucket'])) {
                return $result('request_context_mismatch');
            }

            // Bind the simulated PutObject request key back to the durable
            // prewrite journal, not to an unverified callback parameter.
            $intent = DB::table('private_file_upload_intents')
                ->where('id', $intentId)
                ->first(['status', 'disk', 'encrypted_path', 'path_digest']);
            if ($intent === null || $intent->status !== 'committed'
                || $intent->disk !== 's3') {
                return $result('journal_not_committed');
            }
            $path = Crypt::decryptString((string) $intent->encrypted_path);
            $digest = $this->fence->fingerprint('s3', $path);
            if (! hash_equals($digest, (string) $intent->path_digest)
                || ! hash_equals($path, $receipt['key'])) {
                return $result('request_context_mismatch');
            }

            // R17 independently rechecks the durable journal and locks the
            // current owner reference before inserting a UNIQUE synthetic
            // evidence row. No provider or storage call happens here.
            $this->evidence->recordSyntheticCandidate(
                $intentId,
                $ownerKind,
                $ownerId,
                $receipt['version_id'],
            );
            return $result('synthetic_handoff_recorded_not_attested');
        } catch (RuntimeException) {
            // Do not leak decrypted paths, VersionIds, SQL exceptions or
            // other sensitive receipt content to callers.
            return $result('synthetic_evidence_rejected');
        } catch (Throwable) {
            return $result('receipt_unverifiable');
        }
    }
}
