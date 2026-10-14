<?php

namespace App\Services;

use App\Models\InboxItem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * G3-R19: deterministic crash-point matrix using already durable R13/R17
 * tables and synthetic R18 receipt handoff.
 *
 * This is a TEST-ONLY DB fixture driver. The "write ack" is a synthetic
 * marker: this class NEVER writes, reads, lists or deletes Storage bytes,
 * calls an SDK, or pretends that a real object was uploaded.
 */
final class PrivateFileSyntheticCrashMatrixService
{
    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
        private readonly PrivateFileUploadIntentService $intents,
        private readonly PrivateFileWriteReceiptHandoffService $handoff,
    ) {}

    private const STOPS = [
        'halt_after_prepare',
        'halt_after_synthetic_write_ack',
        'halt_after_owner_commit',
        'halt_after_journal_settle',
        'complete',
    ];

    private function permitted(): bool
    {
        return app()->environment('testing')
            && config('canovia.private_file_synthetic_crash_matrix_enabled', false) === true
            && config('canovia.private_file_path_fence_enabled', false) === true
            && config('canovia.private_file_upload_journal_enabled', false) === true
            && config('canovia.private_file_generation_evidence_enabled', false) === true
            && config('canovia.private_file_write_receipt_handoff_enabled', false) === true
            && (string) config('filesystems.default') === 's3'
            && (string) config('filesystems.disks.s3.driver') === 's3'
            && DB::transactionLevel() === 0;
    }

    /**
     * Simulate an interruption at each transaction boundary.
     *
     * The caller-provided path and VersionId are *untrusted synthetic test
     * values*. Never interpret the returned state as provider proof.
     *
     * @return array{state:string,object_write_verified:bool,
     *     generation_verified:bool,delete_authorized:bool,
     *     retry_authorized:bool,storage_mutations:int}
     */
    public function simulate(string $path, string $syntheticVersionId, string $stop): array
    {
        $result = static fn (string $state): array => [
            'state' => $state,
            'object_write_verified' => false,
            'generation_verified' => false,
            'delete_authorized' => false,
            'retry_authorized' => false,
            'storage_mutations' => 0,
        ];

        if (! $this->permitted() || ! in_array($stop, self::STOPS, true)
            || strlen($syntheticVersionId) < 1
            || strlen($syntheticVersionId) > 1024
            || strtolower($syntheticVersionId) === 'null'
            || str_contains($syntheticVersionId, "\0")) {
            return $result('blocked');
        }

        // PREPARE is independently committed, before even a simulated
        // write acknowledgement. Duplicate-key attempts fail closed; do
        // not silently reuse/replace an intent after a prior crash.
        try {
            $disk = 's3';
            $digest = $this->fence->fingerprint($disk, $path);
            $intentId = $this->intents->prepare($disk, [$digest => $path])[0];
        } catch (Throwable) {
            return $result('prepare_rejected_or_already_claimed');
        }
        if ($stop === 'halt_after_prepare') {
            return $result('prepared_unresolved');
        }

        // Synthetic "write acknowledgement" only. NO Storage::put and
        // no actual write receipt. Both boundaries below have the same
        // durable observable state, so any automatic replay is unsafe.
        if ($stop === 'halt_after_synthetic_write_ack') {
            return $result('prepared_unresolved');
        }

        try {
            // Synthetic owner insert is its own atomic DB commit, not part
            // of the prewrite ledger transaction.
            $owner = DB::transaction(fn () => InboxItem::query()->create([
                'source_type' => 'image',
                'status' => 'new',
                'storage_path' => $path,
            ]), 1);
        } catch (Throwable) {
            return $result('owner_commit_unverifiable');
        }
        if ($stop === 'halt_after_owner_commit') {
            return $result('owner_committed_journal_prepared');
        }

        try {
            $this->intents->settle([$intentId], 'committed');
        } catch (Throwable) {
            return $result('journal_settle_unverifiable');
        }
        if ($stop === 'halt_after_journal_settle') {
            return $result('journal_committed_evidence_missing');
        }

        // R18 will recheck the durable journal and current owner under
        // the R17 exact path owner lock. The fixture origin remains
        // synthetic, with NO generation attestation or delete authority.
        $handoff = $this->handoff->handoffSynthetic(
            $intentId,
            'inbox',
            (int) $owner->id,
            [
                'origin' => 'synthetic_adapter_v1',
                'operation' => 'PutObject',
                'status_code' => 200,
                'intent_id' => $intentId,
                'owner_kind' => 'inbox',
                'owner_id' => (int) $owner->id,
                'disk' => 's3',
                'bucket' => (string) config('filesystems.disks.s3.bucket'),
                'key' => $path,
                'version_id' => $syntheticVersionId,
                'request_id' => 'synthetic_R19_request',
            ],
        );
        return $result($handoff['status'] === 'synthetic_handoff_recorded_not_attested'
            ? 'synthetic_complete_unattested'
            : 'receipt_handoff_unverifiable');
    }

    /**
     * Read-only, non-atomic, redacted review of one synthetic fixture.
     *
     * Identical ledger/owner observations cannot distinguish an object
     * write from a crash just before writing; NEVER infer object existence.
     *
     * @return array{state:string,object_write_verified:bool,
     *     generation_verified:bool,delete_authorized:bool,
     *     retry_authorized:bool,storage_mutations:int}
     */
    public function review(int $intentId): array
    {
        $result = static fn (string $state): array => [
            'state' => $state,
            'object_write_verified' => false,
            'generation_verified' => false,
            'delete_authorized' => false,
            'retry_authorized' => false,
            'storage_mutations' => 0,
        ];

        if (! $this->permitted() || $intentId < 1) {
            return $result('blocked');
        }

        try {
            $intent = DB::table('private_file_upload_intents')->where('id', $intentId)
                ->first(['encrypted_path', 'path_digest', 'status', 'disk']);
            if ($intent === null || $intent->disk !== 's3') {
                return $result('unverifiable');
            }
            $path = Crypt::decryptString((string) $intent->encrypted_path);
            if (! hash_equals(
                $this->fence->fingerprint('s3', $path),
                (string) $intent->path_digest,
            )) {
                return $result('unverifiable');
            }
            $owners = DB::table('inbox_items')
                ->where('storage_path', $path)->count();
            if ($owners > 1) {
                return $result('multiple_owner_references_unverifiable');
            }
            if ($intent->status === 'prepared') {
                return $result($owners === 0
                    ? 'prepared_no_owner_ambiguous'
                    : 'prepared_with_owner_ambiguous');
            }
            if ($intent->status !== 'committed') {
                return $result('unverifiable');
            }

            if ($owners !== 1) {
                return $result('committed_owner_missing_unverifiable');
            }
            $evidence = DB::table('private_file_generation_evidence')
                ->where('upload_intent_id', $intentId)->exists();
            return $result($evidence
                ? 'synthetic_evidence_present_unattested'
                : 'committed_owner_without_evidence');
        } catch (Throwable) {
            return $result('unverifiable');
        }
    }
}
