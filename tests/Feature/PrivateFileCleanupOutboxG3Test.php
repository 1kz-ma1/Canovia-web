<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFileLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileCleanupOutboxG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    public function test_interrupted_post_commit_cleanup_retries_from_durable_outbox(): void
    {
        $path = 'inbox/test-owner/retry.png';
        Storage::put($path, 'original bytes');
        // Simulate process death after deletion committed, but before
        // the afterCommit worker ran: only the outbox row survived.
        $this->insertPending($path);
        $this->assertSame(0, Artisan::call('canovia:retry-private-file-cleanup',
            ['--dry-run' => true]));
        Storage::assertExists($path);

        $result = app(PrivateFileLifecycleService::class)->retryDue(10);
        $this->assertSame(1, $result['deleted']);
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_existing_other_inbox_reference_keeps_file_and_consumes_obsolete_job(): void
    {
        $path = 'study-recall-sources/other-owner/keep.pdf';
        Storage::put($path, 'shared private bytes');
        $this->insertPending($path);
        InboxItem::query()->create([
            'source_type' => 'pdf', 'status' => 'new',
            'storage_path' => $path,
        ]);

        $result = app(PrivateFileLifecycleService::class)->retryDue();
        $this->assertSame(1, $result['retained']);
        Storage::assertExists($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_same_key_with_changed_bytes_is_quarantined_not_deleted(): void
    {
        $path = 'inbox/owner/reused-image.png';
        Storage::put($path, 'original A');
        $this->insertPending($path);
        Storage::put($path, 'new tenant B');

        $result = app(PrivateFileLifecycleService::class)->retryDue();
        $this->assertSame(1, $result['quarantined']);
        Storage::assertExists($path);
        $this->assertSame('new tenant B', Storage::get($path));
        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'status' => 'quarantined', 'last_reason' => 'file_bytes_changed',
        ]);
        $this->assertSame(0, app(PrivateFileLifecycleService::class)->retryDue()['selected']);
    }

    public function test_transient_storage_delete_failure_is_retried_after_backoff(): void
    {
        $path = 'inbox/owner/transient-failure.png';
        Storage::put($path, 'retry safely');
        $this->insertPending($path);

        $realManager = Storage::getFacadeRoot();
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $disk->shouldReceive('get')->once()->with($path)->andReturn('retry safely');
        $disk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->once()
            ->with((string) config('filesystems.default'))->andReturn($disk);
        try {
            $result = app(PrivateFileLifecycleService::class)->retryDue();
            $this->assertSame(1, $result['retry']);
        } finally {
            Storage::swap($realManager);
        }

        Storage::assertExists($path);
        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'status' => 'pending', 'attempt_count' => 1,
            'last_reason' => 'storage_delete_false',
        ]);
        $this->assertSame(0, app(PrivateFileLifecycleService::class)->retryDue()['selected']);

        DB::table('private_file_cleanup_entries')->update([
            'next_attempt_at' => now()->subMinute(),
        ]);
        $this->assertSame(1,
            app(PrivateFileLifecycleService::class)->retryDue()['deleted']);
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_locked_cleanup_entry_allows_only_one_attempt_and_no_duplicate_unlink(): void
    {
        $path = 'career-captures/one-owner/old.png';
        Storage::put($path, 'old source');
        $this->insertPending($path);

        $this->assertSame(1,
            app(PrivateFileLifecycleService::class)->retryDue()['deleted']);
        $this->assertSame(0,
            app(PrivateFileLifecycleService::class)->retryDue()['selected']);
        Storage::assertMissing($path);
    }

    public function test_enqueue_and_outer_rollback_leave_no_outbox_and_preserve_file(): void
    {
        $path = 'inbox/owner/rolled-back.png';
        Storage::put($path, 'no deletion should happen');
        try {
            DB::transaction(function () use ($path): void {
                app(PrivateFileLifecycleService::class)
                    ->deleteAfterCommit(collect([$path]));
                $this->assertDatabaseCount('private_file_cleanup_entries', 1);
                Storage::assertExists($path);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }

        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        Storage::assertExists($path);
    }

    public function test_unreadable_original_is_quarantined_without_automatic_delete(): void
    {
        $path = 'career-captures/unknown/old.png';
        // Storage mock prevents hash of the originally deleted source.
        $manager = Storage::getFacadeRoot();
        Storage::shouldReceive('disk')->once()
            ->with((string) config('filesystems.default'))
            ->andThrow(new RuntimeException('unavailable'));
        try {
            DB::transaction(fn () => app(PrivateFileLifecycleService::class)
                ->deleteAfterCommit(collect([$path])));
        } finally {
            Storage::swap($manager);
        }

        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'status' => 'quarantined', 'last_reason' => 'source_unavailable',
            'expected_sha256' => null,
        ]);
        $this->assertSame(0,
            app(PrivateFileLifecycleService::class)->retryDue()['selected']);
    }

    public function test_invalid_nonprivate_path_is_ignored_even_if_present(): void
    {
        $path = 'public/unrelated/avatar.png';
        Storage::put($path, 'unrelated file');
        DB::transaction(fn () => app(PrivateFileLifecycleService::class)
            ->deleteAfterCommit(collect([$path, '../inbox/bypass.png'])));

        Storage::assertExists($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    private function insertPending(string $path): void
    {
        DB::table('private_file_cleanup_entries')->insert([
            'encrypted_path' => Crypt::encryptString($path),
            'disk' => (string) config('filesystems.default'),
            'expected_sha256' => hash('sha256', Storage::get($path)),
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
