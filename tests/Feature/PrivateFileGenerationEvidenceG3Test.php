<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFileGenerationEvidenceService;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileGenerationEvidenceG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'synthetic-test-bucket',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_generation_evidence_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    /** @return array{0:int,1:int} */
    private function fixture(string $path = 'inbox/synthetic/owner.png', bool $settle = true): array
    {
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intentId = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        if ($settle) {
            app(PrivateFileUploadIntentService::class)->settle([$intentId], 'committed');
        }
        $owner = InboxItem::query()->create([
            'source_type' => 'image',
            'status' => 'new',
            'storage_path' => $path,
        ]);
        return [$intentId, (int) $owner->id];
    }

    public function test_synthetic_record_binds_committed_intent_owner_and_encrypted_version(): void
    {
        [$intent, $owner] = $this->fixture();
        $version = 'sensitive-synthetic-version-42';
        $id = app(PrivateFileGenerationEvidenceService::class)
            ->recordSyntheticCandidate($intent, 'inbox', $owner, $version);
        $this->assertGreaterThan(0, $id);

        $row = DB::table('private_file_generation_evidence')->first();
        $this->assertSame('synthetic_fixture', $row->evidence_origin);
        $this->assertSame('s3', $row->provider);
        $this->assertSame('app-key-v1', $row->key_epoch);
        $this->assertSame($intent, (int) $row->upload_intent_id);
        $this->assertSame($owner, (int) $row->owner_id);
        $this->assertStringNotContainsString($version, $row->encrypted_version_id);
        $this->assertSame(
            app(PrivateFilePathFenceService::class)->fingerprint('s3', 'inbox/synthetic/owner.png'),
            $row->path_digest,
        );
        $service = app(PrivateFileGenerationEvidenceService::class);
        $this->assertSame([
            'state' => 'synthetic_binding_not_provider_verified',
            'generation_verified' => false,
            'delete_authorized' => false,
        ], $service->inspect($intent));
        $report = $service->report();
        $this->assertSame(1, $report['synthetic_count']);
        $this->assertFalse($report['generation_verified']);
        $this->assertFalse($report['delete_authorized']);
        $this->assertStringNotContainsString($version, json_encode($report));
        $this->assertStringNotContainsString('synthetic/owner.png', json_encode($report));
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_wrong_owner_file_binding_cannot_be_recorded(): void
    {
        [$intent, $owner] = $this->fixture();
        InboxItem::query()->whereKey($owner)->update([
            'storage_path' => 'inbox/synthetic/different.png',
        ]);
        $this->expectException(RuntimeException::class);
        try {
            app(PrivateFileGenerationEvidenceService::class)
                ->recordSyntheticCandidate($intent, 'inbox', $owner, 'candidate-v1');
        } finally {
            $this->assertDatabaseCount('private_file_generation_evidence', 0);
        }
    }

    public function test_prepared_but_uncommitted_intent_is_rejected(): void
    {
        [$intent, $owner] = $this->fixture('inbox/synthetic/pending.png', false);
        try {
            app(PrivateFileGenerationEvidenceService::class)
                ->recordSyntheticCandidate($intent, 'inbox', $owner, 'candidate-v1');
            $this->fail('Uncommitted intent must not receive ownership evidence');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic generation evidence recording unavailable.', $e->getMessage());
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_only_allowlisted_owner_kinds_and_valid_non_null_versions_are_accepted(): void
    {
        [$intent, $owner] = $this->fixture();
        foreach (['unknown', 'users', '../inbox'] as $kind) {
            try {
                app(PrivateFileGenerationEvidenceService::class)
                    ->recordSyntheticCandidate($intent, $kind, $owner, 'v-1');
                $this->fail('Untrusted owner kind was accepted');
            } catch (RuntimeException) {}
        }
        foreach (['', 'null', 'NULL', str_repeat('a', 1025), "abc\0def"] as $version) {
            try {
                app(PrivateFileGenerationEvidenceService::class)
                    ->recordSyntheticCandidate($intent, 'inbox', $owner, $version);
                $this->fail('Invalid synthetic VersionId was accepted');
            } catch (RuntimeException) {}
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_duplicate_evidence_for_one_write_intent_cannot_be_replaced(): void
    {
        [$intent, $owner] = $this->fixture();
        $service = app(PrivateFileGenerationEvidenceService::class);
        $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'first-synthetic');
        try {
            $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'second-synthetic');
            $this->fail('Duplicate should be blocked by unique journal FK');
        } catch (RuntimeException) {}
        $this->assertDatabaseCount('private_file_generation_evidence', 1);
        $row = DB::table('private_file_generation_evidence')->first();
        $this->assertSame('first-synthetic',
            \Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_version_id));
    }

    public function test_legacy_committed_intent_without_generation_evidence_is_not_verified(): void
    {
        [$intent] = $this->fixture();
        $result = app(PrivateFileGenerationEvidenceService::class)->inspect($intent);
        $this->assertSame('legacy_or_missing_evidence', $result['state']);
        $this->assertFalse($result['generation_verified']);
        $this->assertFalse($result['delete_authorized']);
    }

    public function test_owner_change_after_record_is_inconclusive(): void
    {
        [$intent, $owner] = $this->fixture();
        $service = app(PrivateFileGenerationEvidenceService::class);
        $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
        InboxItem::query()->whereKey($owner)->update([
            'storage_path' => 'inbox/synthetic/replaced.png',
        ]);
        $result = $service->inspect($intent);
        $this->assertSame('owner_reference_changed', $result['state']);
        $this->assertFalse($result['delete_authorized']);
    }

    public function test_bucket_change_and_epoch_change_fail_closed(): void
    {
        [$intent, $owner] = $this->fixture();
        $service = app(PrivateFileGenerationEvidenceService::class);
        $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
        config(['filesystems.disks.s3.bucket' => 'different-bucket']);
        $this->assertSame('bucket_identity_mismatch', $service->inspect($intent)['state']);
        config([
            'filesystems.disks.s3.bucket' => 'synthetic-test-bucket',
            'canovia.private_file_generation_key_epoch' => 'app-key-v2',
        ]);
        $this->assertSame('key_epoch_mismatch', $service->inspect($intent)['state']);
        $this->assertFalse($service->inspect($intent)['delete_authorized']);
    }

    public function test_key_change_or_corrupted_ciphertext_never_falls_back_to_verified(): void
    {
        [$intent, $owner] = $this->fixture();
        $service = app(PrivateFileGenerationEvidenceService::class);
        $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
        DB::table('private_file_generation_evidence')
            ->where('upload_intent_id', $intent)
            ->update(['encrypted_version_id' => 'tampered']);
        $this->assertSame('identity_unverifiable', $service->inspect($intent)['state']);
        config(['app.key' => 'different-test-only-app-key']);
        $this->assertNotSame('synthetic_binding_not_provider_verified',
            $service->inspect($intent)['state']);
        $this->assertFalse($service->inspect($intent)['generation_verified']);
    }

    public function test_disabled_and_production_modes_block_all_actions(): void
    {
        [$intent, $owner] = $this->fixture();
        $service = app(PrivateFileGenerationEvidenceService::class);
        config(['canovia.private_file_generation_evidence_enabled' => false]);
        $this->assertSame('blocked', $service->report()['status']);
        $this->assertSame('blocked', $service->inspect($intent)['state']);
        try {
            $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
            $this->fail('Disabled synthetic writer should never run');
        } catch (RuntimeException) {}
        config(['canovia.private_file_generation_evidence_enabled' => true]);
        $prior = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $service->report()['status']);
            $this->assertSame('blocked', $service->inspect($intent)['state']);
            try {
                $service->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
                $this->fail('Production synthetic evidence writer should never run');
            } catch (RuntimeException) {}
        } finally {
            app()->instance('env', $prior);
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_local_driver_is_rejected_without_issuing_storage_calls(): void
    {
        [$intent, $owner] = $this->fixture();
        config(['filesystems.default' => 'local']);
        try {
            app(PrivateFileGenerationEvidenceService::class)
                ->recordSyntheticCandidate($intent, 'inbox', $owner, 'synthetic');
            $this->fail('Local provider has no S3 generation evidence');
        } catch (RuntimeException) {}
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_read_only_operator_health_is_redacted_and_does_not_delete(): void
    {
        [$intent, $owner] = $this->fixture();
        app(PrivateFileGenerationEvidenceService::class)
            ->recordSyntheticCandidate($intent, 'inbox', $owner, 'do-not-print-version');
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/keep.png', 'must-preserve-bytes');
        $this->artisan('canovia:private-file-generation-evidence-health', [
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('"synthetic_count":1')
            ->doesntExpectOutputToContain('do-not-print-version')
            ->doesntExpectOutputToContain('owner.png');
        $this->assertSame('must-preserve-bytes',
            Storage::disk('local')->get('inbox/synthetic/keep.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }
}
