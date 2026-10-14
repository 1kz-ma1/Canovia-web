<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileUploadService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileUploadIntentG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config([
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
        ]);
    }

    private function upload(string $path): InboxItem
    {
        return app(PrivateFileUploadService::class)->storeAndPersist([
            ['file' => UploadedFile::fake()->image('example.png'), 'path' => $path],
        ], fn (array $paths) => InboxItem::query()->create([
            'source_type' => 'image',
            'status' => 'new',
            'storage_path' => $paths[0],
        ]));
    }

    public function test_successful_upload_persists_prewrite_ledger_without_raw_path(): void
    {
        $path = 'inbox/synthetic/intent-success.png';
        $item = $this->upload($path);

        $this->assertSame($path, $item->storage_path);
        Storage::assertExists($path);
        $record = DB::table('private_file_upload_intents')->first();
        $this->assertNotNull($record);
        $this->assertSame('committed', $record->status);
        $this->assertSame(
            app(PrivateFilePathFenceService::class)->fingerprint(
                (string) config('filesystems.default'), $path,
            ),
            $record->path_digest,
        );
        $this->assertStringNotContainsString($path, $record->encrypted_path);
        $this->assertStringNotContainsString('intent-success.png', $record->encrypted_path);
        $this->assertSame('healthy', app(PrivateFileUploadIntentService::class)->report()['health']);
    }

    public function test_failed_owner_write_retains_durable_review_record_after_rollback(): void
    {
        $path = 'inbox/synthetic/owner-failure.png';
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->image('bad.png'), 'path' => $path],
            ], function () {
                throw new RuntimeException('test owner failure');
            });
            $this->fail('The owner callback must abort');
        } catch (RuntimeException $e) {
            $this->assertSame('test owner failure', $e->getMessage());
        }

        Storage::assertMissing($path);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseHas('private_file_upload_intents', [
            'status' => 'needs_review',
        ]);
        $report = app(PrivateFileUploadIntentService::class)->report();
        $this->assertSame(1, $report['needs_review']);
        $this->assertSame('attention', $report['health']);
        $this->assertArrayNotHasKey('encrypted_path', $report);
        $this->assertArrayNotHasKey('path_digest', $report);
    }

    public function test_simulated_crash_after_prepare_leaves_read_only_pending_intent(): void
    {
        $disk = (string) config('filesystems.default');
        $path = 'study-recall-sources/synthetic/crash.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint($disk, $path);
        $intents = app(PrivateFileUploadIntentService::class);
        $ids = $intents->prepare($disk, [$digest => $path]);

        // Simulate a process exit after the prewrite ledger commit and
        // partial object write, without calling the normal settlement path.
        Storage::put($path, 'partial-object-possibly-orphan');
        $this->assertCount(1, $ids);
        $this->assertDatabaseHas('private_file_upload_intents', [
            'id' => $ids[0], 'status' => 'prepared',
        ]);
        $this->assertSame(1, $intents->report()['prepared']);
        $this->assertSame('healthy', $intents->report()['health']);
        Storage::assertExists($path); // reporting never deletes or scans
        DB::table('private_file_upload_intents')->where('id', $ids[0])
            ->update(['created_at' => now()->subDays(2)]);
        $this->assertSame(1, $intents->report()['prepared_stale_24h']);
        $this->assertSame('attention', $intents->report()['health']);
        Storage::assertExists($path);
    }

    public function test_already_reserved_key_cannot_overwrite_even_if_object_is_missing(): void
    {
        $path = 'inbox/synthetic/no-second-claim.png';
        $disk = (string) config('filesystems.default');
        $intent = app(PrivateFileUploadIntentService::class);
        $digest = app(PrivateFilePathFenceService::class)->fingerprint($disk, $path);
        $intent->prepare($disk, [$digest => $path]);

        try {
            $this->upload($path);
            $this->fail('A previously prepared path is never reused.');
        } catch (\Illuminate\Database\QueryException) {
            // A unique-key conflict rejects the reattempt before bytes exist.
        }
        Storage::assertMissing($path);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_upload_intents', 1);
    }

    public function test_forged_path_digest_is_rejected_before_journal_or_bytes(): void
    {
        $disk = (string) config('filesystems.default');
        $path = 'inbox/synthetic/forged-key.png';
        $other = 'inbox/synthetic/not-the-same-key.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint($disk, $other);

        try {
            app(PrivateFileUploadIntentService::class)->prepare($disk, [$digest => $path]);
            $this->fail('A digest of another private path must be denied.');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid private upload journal key.', $e->getMessage());
        }
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_upload_intents', 0);
    }

    public function test_partial_batch_conflict_rolls_back_all_prepared_journal_rows(): void
    {
        $disk = (string) config('filesystems.default');
        $fence = app(PrivateFilePathFenceService::class);
        $journal = app(PrivateFileUploadIntentService::class);
        $already = 'study-recall-sources/synthetic/reserved.pdf';
        $new = 'study-recall-sources/synthetic/new.png';
        $reservedDigest = $fence->fingerprint($disk, $already);
        $freshDigest = $fence->fingerprint($disk, $new);
        $journal->prepare($disk, [$reservedDigest => $already]);

        $batch = [$reservedDigest => $already, $freshDigest => $new];
        ksort($batch, SORT_STRING);
        try {
            $journal->prepare($disk, $batch);
            $this->fail('Duplicate durable claim must abort entire batch');
        } catch (\Illuminate\Database\QueryException) {
            // Unique digest is permanent, so no bytes can be written here.
        }
        $this->assertDatabaseCount('private_file_upload_intents', 1);
        Storage::assertMissing($new);
        Storage::assertMissing($already);
    }

    public function test_parent_transaction_cannot_fake_a_durable_prewrite_commit(): void
    {
        $path = 'inbox/synthetic/nested-rejected.png';
        try {
            DB::transaction(fn () => $this->upload($path));
            $this->fail('A nested uncommitted intent must fail closed');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Private upload journal requires an outermost transaction boundary.',
                $e->getMessage(),
            );
        }
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_upload_intents', 0);
    }

    public function test_disabled_journal_does_not_create_prewrite_records(): void
    {
        config(['canovia.private_file_upload_journal_enabled' => false]);
        $path = 'inbox/synthetic/disabled.png';
        $this->upload($path);
        $this->assertDatabaseCount('private_file_upload_intents', 0);
        Storage::assertExists($path);
    }

    public function test_partial_journal_schema_is_not_reported_healthy(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')
            ->once()->with('private_file_upload_intents')->andReturn(false);
        try {
            $report = app(PrivateFileUploadIntentService::class)->report();
            $this->assertSame('unavailable', $report['schema']);
            $this->assertSame('unavailable', $report['health']);
        } finally {
            \Illuminate\Support\Facades\Schema::swap($schema);
        }
    }
}
