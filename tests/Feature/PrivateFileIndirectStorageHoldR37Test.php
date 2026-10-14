<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\PrivateFileLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * R37 integration tests: no unjournaled remote Inbox writes and no
 * remote-object HEAD/GET when registering an outbox cleanup entry.
 */
final class PrivateFileIndirectStorageHoldR37Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_s3_legacy_inbox_file_is_rejected_before_storage_adapter_io(): void
    {
        $this->assertInboxFileDenied('s3', ['driver' => 's3']);
    }

    public function test_aliased_s3_legacy_inbox_file_is_rejected_before_adapter_io(): void
    {
        $this->assertInboxFileDenied('private_remote', ['driver' => 's3']);
    }

    public function test_unknown_default_disk_legacy_inbox_file_is_rejected(): void
    {
        $this->assertInboxFileDenied('unconfigured_private', null);
    }

    /** @param array<string,string>|null $driver */
    private function assertInboxFileDenied(string $disk, ?array $driver): void
    {
        $user = User::factory()->create();
        config([
            'filesystems.default' => $disk,
            'filesystems.disks.'.$disk => $driver,
            'canovia.private_file_path_fence_enabled' => false,
            'canovia.private_file_upload_journal_enabled' => false,
        ]);

        $real = Storage::getFacadeRoot();
        $denied = Mockery::mock();
        $denied->shouldNotReceive('disk');
        Storage::swap($denied);

        try {
            $this->withoutExceptionHandling();
            $this->actingAs($user)->post(route('inbox.store'), [
                'source_file' => UploadedFile::fake()->image('synthetic.png'),
                'intake_mode' => 'capture',
            ]);
            $this->fail('An unjournaled remote Inbox upload must be denied.');
        } catch (RuntimeException $e) {
            $this->assertSame('Private legacy file write requires local storage.', $e->getMessage());
        } finally {
            Storage::swap($real);
        }

        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_upload_intents', 0);
    }

    public function test_s3_default_keeps_text_only_inbox_intake_available(): void
    {
        $user = User::factory()->create();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'canovia.private_file_path_fence_enabled' => false,
        ]);
        $this->actingAs($user)->post(route('inbox.store'), [
            'content' => 'R37 synthetic text-only Inbox',
            'intake_mode' => 'capture',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = InboxItem::query()->firstOrFail();
        $this->assertSame('text', $item->source_type);
        $this->assertNull($item->storage_path);
    }

    public function test_local_default_inbox_upload_remains_functional_with_fence_off(): void
    {
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'canovia.private_file_path_fence_enabled' => false,
        ]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('inbox.store'), [
            'source_file' => UploadedFile::fake()->image('synthetic.png'),
            'intake_mode' => 'capture',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = InboxItem::query()->firstOrFail();
        $this->assertSame('image', $item->source_type);
        Storage::disk('local')->assertExists($item->storage_path);
    }

    public function test_outbox_enrollment_on_remote_driver_writes_quarantine_without_head_get_or_delete(): void
    {
        foreach ([
            ['s3', ['driver' => 's3']],
            ['named_provider', ['driver' => 's3']],
            ['unknown', null],
        ] as [$disk, $driver]) {
            config([
                'filesystems.default' => $disk,
                'filesystems.disks.'.$disk => $driver,
            ]);
            $original = Storage::getFacadeRoot();
            $denied = Mockery::mock();
            $denied->shouldNotReceive('disk');
            Storage::swap($denied);
            try {
                DB::transaction(fn () =>
                    app(PrivateFileLifecycleService::class)->deleteAfterCommit(
                        collect(['inbox/r37/probe-'.$disk.'.png'])
                    )
                );
            } finally {
                Storage::swap($original);
            }

            $this->assertDatabaseHas('private_file_cleanup_entries', [
                'disk' => $disk,
                'expected_sha256' => null,
                'status' => 'quarantined',
                'last_reason' => 'provider_generation_delete_unverified',
                'attempt_count' => 0,
                'next_attempt_at' => null,
            ]);
        }

        $this->assertDatabaseCount('private_file_cleanup_entries', 3);
        $this->assertSame(0, app(PrivateFileLifecycleService::class)->retryDue()['selected']);
    }

    public function test_external_cleanup_enrollment_inside_rolled_back_owner_transaction_leaves_no_row(): void
    {
        config(['filesystems.default' => 's3', 'filesystems.disks.s3.driver' => 's3']);
        try {
            DB::transaction(function (): void {
                app(PrivateFileLifecycleService::class)->deleteAfterCommit(
                    collect(['inbox/r37/rollback-only.png'])
                );
                $this->assertDatabaseCount('private_file_cleanup_entries', 1);
                throw new RuntimeException('synthetic transaction rollback');
            });
            $this->fail('Expected rollback.');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic transaction rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }
}
