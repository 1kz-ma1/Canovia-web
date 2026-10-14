<?php

namespace Tests\Feature;

use App\Services\PrivateFileCleanupHealthService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileCleanupHealthG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_empty_and_newly_due_queue_is_healthy_without_touching_storage(): void
    {
        $health = app(PrivateFileCleanupHealthService::class);
        $this->assertSame('healthy', $health->report()['health']);

        $path = 'inbox/owner/pending.png';
        Storage::put($path, 'not an authorized file deletion');
        $this->insertEntry($path, 'pending', now(), now()->subMinute(), 0);

        $report = $health->report();
        $this->assertSame('ready', $report['schema']);
        $this->assertSame('healthy', $report['health']);
        $this->assertSame(1, $report['pending']);
        $this->assertSame(1, $report['pending_due']);
        $this->assertSame(0, $report['pending_stale_24h']);
        $this->assertSame(0, $report['pending_high_retry_5']);
        $this->assertSame(0, Artisan::call('canovia:private-file-cleanup-health',
            ['--strict' => true]));
        Storage::assertExists($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 1);
    }

    public function test_stale_high_retry_no_due_date_and_quarantine_trigger_attention(): void
    {
        $this->insertEntry('inbox/old/job.png', 'pending', now()->subDays(2),
            now()->subDay(), 5);
        $this->insertEntry('inbox/stuck/job.png', 'pending', now()->subDay(),
            null, 0);
        $this->insertEntry('inbox/quarantine/job.png', 'quarantined',
            now()->subDays(3), null, 1, 'file_bytes_changed');
        $this->insertEntry('inbox/untrusted/reason.png', 'quarantined',
            now()->subDay(), null, 0, 'sensitive_please_leak_this_path');

        $report = app(PrivateFileCleanupHealthService::class)->report();
        $this->assertSame('attention', $report['health']);
        $this->assertSame(2, $report['pending']);
        $this->assertSame(1, $report['pending_due']);
        $this->assertSame(2, $report['pending_stale_24h']);
        $this->assertSame(1, $report['pending_high_retry_5']);
        $this->assertSame(1, $report['pending_without_retry_date']);
        $this->assertSame(2, $report['quarantined']);
        $this->assertSame(1, $report['quarantined_reasons']['file_bytes_changed']);
        $this->assertSame(1, $report['quarantined_reasons']['other']);
        $this->assertArrayNotHasKey('sensitive_please_leak_this_path',
            $report['quarantined_reasons']);

        $this->assertSame(2, Artisan::call('canovia:private-file-cleanup-health',
            ['--strict' => true, '--json' => true]));
        $json = Artisan::output();
        $this->assertSame('attention', json_decode($json, true, 512,
            JSON_THROW_ON_ERROR)['health']);
        $this->assertStringNotContainsString('inbox/', $json);
        $this->assertStringNotContainsString('sensitive_please_leak_this_path', $json);
        $this->assertStringNotContainsString('"encrypted_path":', $json);
        $this->assertSame(0, Artisan::call('canovia:private-file-cleanup-health',
            ['--json' => true]));
        $this->assertDatabaseCount('private_file_cleanup_entries', 4);
    }

    public function test_unknown_outbox_status_is_not_reported_healthy(): void
    {
        $this->insertEntry('career-captures/orphan/unknown.png', 'unexpected',
            now(), null, 0);

        $report = app(PrivateFileCleanupHealthService::class)->report();
        $this->assertSame(1, $report['unknown_status']);
        $this->assertSame('attention', $report['health']);
        $this->assertSame(2, Artisan::call('canovia:private-file-cleanup-health',
            ['--strict' => true]));
    }

    public function test_unavailable_schema_fails_closed_and_never_prints_db_errors(): void
    {
        $real = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->once()
            ->with('private_file_cleanup_entries')->andReturn(false);
        try {
            $report = app(PrivateFileCleanupHealthService::class)->report();
            $this->assertSame('unavailable', $report['schema']);
            $this->assertSame('unavailable', $report['health']);
        } finally {
            Schema::swap($real);
        }

        Schema::shouldReceive('hasTable')->once()
            ->with('private_file_cleanup_entries')
            ->andThrow(new RuntimeException('do-not-log-private-host-or-path'));
        try {
            $this->assertSame(1, Artisan::call('canovia:private-file-cleanup-health',
                ['--json' => true]));
            $out = Artisan::output();
            $this->assertStringContainsString('"health":"unavailable"', $out);
            $this->assertStringNotContainsString('do-not-log-private-host-or-path', $out);
        } finally {
            Schema::swap($real);
        }
    }

    public function test_report_is_aggregate_only_with_stable_public_keys(): void
    {
        $path = 'study-recall-sources/secret-user-id/secret-source.pdf';
        $this->insertEntry($path, 'quarantined', now()->subDays(2),
            null, 1, 'source_unavailable');

        $json = json_encode(app(PrivateFileCleanupHealthService::class)->report(),
            JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-user-id', $json);
        $this->assertStringNotContainsString('secret-source.pdf', $json);
        $this->assertStringNotContainsString('"encrypted_path":', $json);
        $this->assertStringNotContainsString('disk', $json);
        $this->assertStringNotContainsString('expected_sha256', $json);
    }

    public function test_partial_outbox_schema_is_unavailable_not_a_healthy_empty_queue(): void
    {
        $schema = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->once()
            ->with('private_file_cleanup_entries')->andReturn(true);
        Schema::shouldReceive('hasColumn')->once()
            ->with('private_file_cleanup_entries', 'encrypted_path')->andReturn(true);
        Schema::shouldReceive('hasColumn')->once()
            ->with('private_file_cleanup_entries', 'disk')->andReturn(false);
        try {
            $report = app(PrivateFileCleanupHealthService::class)->report();
        } finally {
            Schema::swap($schema);
        }

        $this->assertSame('unavailable', $report['schema']);
        $this->assertSame('unavailable', $report['health']);
    }

    public function test_disk_mismatch_quarantine_reason_is_counted_without_exposing_disk_names(): void
    {
        $path = 'inbox/private-owner/disk-mismatch.png';
        $this->insertEntry($path, 'quarantined', now(), null, 0, 'storage_disk_mismatch');

        $report = app(PrivateFileCleanupHealthService::class)->report();
        $this->assertSame(1, $report['quarantined_reasons']['storage_disk_mismatch']);
        $this->assertSame('attention', $report['health']);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-owner', $json);
        $this->assertStringNotContainsString('local', $json);
        $this->assertStringNotContainsString('"disk":', $json);
    }

    private function insertEntry(
        string $path,
        string $status,
        \Carbon\CarbonInterface $created,
        ?\Carbon\CarbonInterface $due,
        int $attempts,
        ?string $reason = null,
    ): void {
        DB::table('private_file_cleanup_entries')->insert([
            'encrypted_path' => Crypt::encryptString($path),
            'disk' => (string) config('filesystems.default'),
            'expected_sha256' => hash('sha256', 'private data'),
            'status' => $status,
            'attempt_count' => $attempts,
            'last_reason' => $reason,
            'next_attempt_at' => $due,
            'created_at' => $created,
            'updated_at' => $created,
        ]);
    }
}
