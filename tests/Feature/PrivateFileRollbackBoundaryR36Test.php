<?php

namespace Tests\Feature;

use App\Services\PrivateFileStorageBoundaryService;
use App\Services\PrivateFileUploadService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileRollbackBoundaryR36Test extends TestCase
{
    use DatabaseTruncation;

    public function test_external_upload_without_durable_journal_is_denied_before_storage_io(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => false,
        ]);
        $real = Storage::getFacadeRoot();
        $storage = Mockery::mock();
        $storage->shouldNotReceive('disk');
        Storage::swap($storage);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Private external upload requires durable journal.');
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->create('fixture.txt'),
                 'path' => 'inbox/r36/nonjournal.txt'],
            ], fn () => null);
        } finally {
            Storage::swap($real);
        }
    }

    public function test_remote_failure_preserves_bytes_and_durable_review_intent(): void
    {
        // A fake disk emulates bytes while the declared driver remains S3;
        // no AWS client, credentials, DeleteObject or external I/O is used.
        Storage::fake('s3');
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
        ]);
        $path = 'inbox/r36/preserve-on-rollback.txt';
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->createWithContent('fixture.txt', 'synthetic')],
                 'path' => $path],
            ], function () {
                throw new RuntimeException('synthetic owner insert rejected');
            });
            $this->fail('Owner failure must reject upload.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic owner insert rejected', $e->getMessage());
        }

        Storage::disk('s3')->assertExists($path);
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseHas('private_file_upload_intents', [
            'status' => 'needs_review',
            'disk' => 's3',
        ]);
    }

    public function test_legacy_writer_rejects_external_and_unknown_drivers_without_adapter_access(): void
    {
        $policy = app(PrivateFileStorageBoundaryService::class);
        foreach (['s3', 'alias_s3', 'unknown'] as $disk) {
            config([
                'filesystems.default' => $disk,
                'filesystems.disks.'.$disk => $disk === 'unknown'
                    ? null : ['driver' => 's3'],
            ]);
            $this->assertFalse($policy->isLocalDefaultDisk());
            try {
                $policy->requireLocalLegacyWriter();
                $this->fail('Legacy write must stop without provider I/O.');
            } catch (RuntimeException $e) {
                $this->assertSame('Private legacy file write requires local storage.', $e->getMessage());
            }
        }
    }

    public function test_remote_rollback_delete_never_resolves_an_adapter(): void
    {
        config(['filesystems.default' => 's3', 'filesystems.disks.s3.driver' => 's3']);
        $real = Storage::getFacadeRoot();
        $storage = Mockery::mock();
        $storage->shouldNotReceive('delete');
        $storage->shouldNotReceive('disk');
        Storage::swap($storage);
        try {
            app(PrivateFileStorageBoundaryService::class)->deleteRollbackForLocal(
                ['inbox/r36/synthetic.txt', 'inbox/r36/other.txt'],
            );
        } finally {
            Storage::swap($real);
        }
    }

    public function test_local_failure_still_removes_uncommitted_bytes(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        config([
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
        ]);
        $policy = app(PrivateFileStorageBoundaryService::class);
        $policy->requireLocalLegacyWriter();
        $path = 'inbox/r36/local-rollback.txt';
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->createWithContent('fixture.txt', 'local')],
                 'path' => $path],
            ], function () {
                throw new RuntimeException('local owner reject');
            });
            $this->fail('Expected owner rejection.');
        } catch (RuntimeException $e) {
            $this->assertSame('local owner reject', $e->getMessage());
        }
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('private_file_upload_intents', ['status' => 'needs_review']);
    }
}
