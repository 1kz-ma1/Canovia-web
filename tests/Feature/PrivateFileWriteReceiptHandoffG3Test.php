<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFileGenerationEvidenceService;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteReceiptHandoffService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileWriteReceiptHandoffG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'r18-disposable-fixture-bucket',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_generation_evidence_enabled' => true,
            'canovia.private_file_write_receipt_handoff_enabled' => true,
        ]);
    }

    /** @return array{0:int,1:int,2:string} */
    private function fixture(bool $committed = true): array
    {
        $path = 'inbox/synthetic/r18-owner.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)->prepare(
            's3', [$digest => $path],
        )[0];
        if ($committed) {
            app(PrivateFileUploadIntentService::class)->settle([$intent], 'committed');
        }
        $owner = InboxItem::query()->create([
            'source_type' => 'image', 'status' => 'new', 'storage_path' => $path,
        ]);
        return [$intent, (int) $owner->id, $path];
    }

    /** @return array<string,mixed> */
    private function receipt(int $intent, int $owner, string $path): array
    {
        return [
            'origin' => 'synthetic_adapter_v1',
            'operation' => 'PutObject',
            'status_code' => 200,
            'intent_id' => $intent,
            'owner_kind' => 'inbox',
            'owner_id' => $owner,
            'disk' => 's3',
            'bucket' => 'r18-disposable-fixture-bucket',
            'key' => $path,
            'version_id' => 'sensitive-synthetic-version-id-22',
            'request_id' => 'simulated_request_1',
        ];
    }

    private function handoff(int $intent, int $owner, array $receipt): array
    {
        return app(PrivateFileWriteReceiptHandoffService::class)->handoffSynthetic(
            $intent, 'inbox', $owner, $receipt,
        );
    }

    public function test_matching_synthetic_put_response_is_saved_without_provider_attestation(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        $result = $this->handoff($intent, $owner, $this->receipt($intent, $owner, $path));

        $this->assertSame([
            'status' => 'synthetic_handoff_recorded_not_attested',
            'provider_receipt_authenticated' => false,
            'generation_verified' => false,
            'delete_authorized' => false,
        ], $result);
        $this->assertDatabaseCount('private_file_generation_evidence', 1);
        $row = DB::table('private_file_generation_evidence')->first();
        $this->assertSame('synthetic_fixture', $row->evidence_origin);
        $this->assertSame(
            'sensitive-synthetic-version-id-22',
            Crypt::decryptString($row->encrypted_version_id),
        );
        $this->assertStringNotContainsString($path, json_encode($result));
        $this->assertStringNotContainsString('sensitive-synthetic-version', json_encode($result));
        $this->assertSame(
            'synthetic_binding_not_provider_verified',
            app(PrivateFileGenerationEvidenceService::class)->inspect($intent)['state'],
        );
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_errors_and_untrusted_provider_claims_fail_without_persisting_evidence(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        $base = $this->receipt($intent, $owner, $path);

        $invalid = [
            ['origin' => 'aws_sdk_live'],
            ['origin' => 'production_provider_response'],
            ['operation' => 'GetObject'],
            ['operation' => 'DeleteObject'],
            ['status_code' => 500],
            ['status_code' => 204],
            ['status_code' => '200'],
            ['version_id' => null],
            ['version_id' => 'null'],
            ['version_id' => 'NULL'],
            ['version_id' => ''],
            ['version_id' => str_repeat('v', 1025)],
            ['version_id' => "bad\0token"],
            ['request_id' => ''],
            ['request_id' => "bad\nid"],
            ['disk' => 'local'],
            ['bucket' => 'wrong-bucket'],
            ['key' => 'inbox/synthetic/other.png'],
            ['intent_id' => $intent + 1],
            ['owner_id' => $owner + 1],
            ['owner_kind' => 'career'],
            ['authorization' => true],
        ];
        foreach ($invalid as $changes) {
            $res = $this->handoff($intent, $owner, array_replace($base, $changes));
            $this->assertNotSame('synthetic_handoff_recorded_not_attested', $res['status']);
            $this->assertFalse($res['provider_receipt_authenticated']);
            $this->assertFalse($res['generation_verified']);
            $this->assertFalse($res['delete_authorized']);
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_etag_only_and_partial_receipt_cannot_become_version_proof(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        $base = $this->receipt($intent, $owner, $path);

        unset($base['version_id']);
        $base['etag'] = 'same-content-hash';
        $res = $this->handoff($intent, $owner, $base);
        $this->assertSame('receipt_shape_rejected', $res['status']);
        $this->assertFalse($res['delete_authorized']);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_prepared_intent_is_rejected_without_claiming_upload_success(): void
    {
        [$intent, $owner, $path] = $this->fixture(false);
        $res = $this->handoff($intent, $owner, $this->receipt($intent, $owner, $path));
        $this->assertSame('journal_not_committed', $res['status']);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_owner_change_between_receipt_and_recording_fails_closed(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        InboxItem::query()->whereKey($owner)->update([
            'storage_path' => 'inbox/synthetic/changed-owner.png',
        ]);
        $res = $this->handoff($intent, $owner, $this->receipt($intent, $owner, $path));
        $this->assertSame('synthetic_evidence_rejected', $res['status']);
        $this->assertFalse($res['delete_authorized']);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_duplicate_receipt_does_not_replace_original_evidence(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        $first = $this->receipt($intent, $owner, $path);
        $this->assertSame(
            'synthetic_handoff_recorded_not_attested',
            $this->handoff($intent, $owner, $first)['status'],
        );
        $second = array_replace($first, ['version_id' => 'sensitive-different-token']);
        $this->assertSame(
            'synthetic_evidence_rejected',
            $this->handoff($intent, $owner, $second)['status'],
        );
        $this->assertDatabaseCount('private_file_generation_evidence', 1);
        $row = DB::table('private_file_generation_evidence')->first();
        $this->assertSame(
            'sensitive-synthetic-version-id-22',
            Crypt::decryptString($row->encrypted_version_id),
        );
    }

    public function test_disabled_and_production_paths_are_rejected_before_any_receipt_work(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        $receipt = $this->receipt($intent, $owner, $path);
        config(['canovia.private_file_write_receipt_handoff_enabled' => false]);
        $this->assertSame('blocked', $this->handoff($intent, $owner, $receipt)['status']);

        config(['canovia.private_file_write_receipt_handoff_enabled' => true]);
        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->handoff($intent, $owner, $receipt)['status']);
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_local_storage_bytes_are_untouched_and_no_object_delete_is_triggered(): void
    {
        [$intent, $owner, $path] = $this->fixture();
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/keep.png', 'existing-bytes');
        $this->handoff($intent, $owner, $this->receipt($intent, $owner, $path));
        $this->assertSame('existing-bytes',
            Storage::disk('local')->get('inbox/synthetic/keep.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }
}
