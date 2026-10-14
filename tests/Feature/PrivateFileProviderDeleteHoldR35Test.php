<?php

namespace Tests\Feature;

use App\Services\PrivateFileLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileProviderDeleteHoldR35Test extends TestCase
{
    use DatabaseTruncation;

    private function pending(string $disk, string $path): void
    {
        DB::table('private_file_cleanup_entries')->insert([
            'encrypted_path' => Crypt::encryptString($path),
            'disk' => $disk,
            'expected_sha256' => hash('sha256', 'test-only original'),
            'status' => 'pending',
            'attempt_count' => 0,
            'last_reason' => null,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertProviderDeleteHeld(string $disk): void
    {
        $path = 'inbox/r35/synthetic-owner/unchanged-content.txt';
        $this->pending($disk, $path);

        // The S3 Flysystem adapter is intentionally NOT installed. Any
        // Storage::disk call would be an unsafe provider operation and fail.
        $realStorage = Storage::getFacadeRoot();
        Storage::shouldNotReceive('disk');
        try {
            $result = app(PrivateFileLifecycleService::class)->retryDue();
        } finally {
            Storage::swap($realStorage);
        }

        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['quarantined']);
        $this->assertSame(0, $result['deleted']);
        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'disk' => $disk,
            'status' => 'quarantined',
            'last_reason' => 'provider_generation_delete_unverified',
            'attempt_count' => 0,
            'next_attempt_at' => null,
        ]);
        $this->assertSame(0, app(PrivateFileLifecycleService::class)->retryDue()['selected']);
    }

    public function test_s3_is_quarantined_without_any_storage_call_even_with_fence_enabled(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
        ]);
        $this->assertProviderDeleteHeld('s3');
    }

    public function test_s3_under_a_custom_disk_name_is_also_quarantined(): void
    {
        config([
            'filesystems.default' => 'private_cloud',
            'filesystems.disks.private_cloud' => ['driver' => 's3'],
        ]);
        $this->assertProviderDeleteHeld('private_cloud');
    }

    public function test_missing_or_unidentified_storage_driver_fails_closed(): void
    {
        config([
            'filesystems.default' => 'unconfigured_private',
            'filesystems.disks.unconfigured_private' => null,
        ]);
        $this->assertProviderDeleteHeld('unconfigured_private');
    }

    public function test_existing_local_disk_cleanup_remains_operational(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        $path = 'inbox/r35/local-owner/old.txt';
        Storage::disk('local')->put($path, 'test-only original');
        $this->pending('local', $path);

        $result = app(PrivateFileLifecycleService::class)->retryDue();
        $this->assertSame(1, $result['deleted']);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }
}
