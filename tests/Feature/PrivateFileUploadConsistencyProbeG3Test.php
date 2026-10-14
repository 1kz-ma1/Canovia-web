<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileReconciliationSnapshotService;
use App\Services\PrivateFileUploadReconciliationPreviewService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * R15: two weak samples are useful for observed change, NEVER generation
 * proof. All mutations below are deliberate test fixtures, not preview code.
 */
final class PrivateFileUploadConsistencyProbeG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config([
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_upload_reconcile_preview_enabled' => true,
            'canovia.private_file_upload_consistency_probe_enabled' => true,
        ]);
    }

    private function journal(string $path): void
    {
        $disk = (string) config('filesystems.default');
        $digest = app(PrivateFilePathFenceService::class)->fingerprint($disk, $path);
        $id = app(PrivateFileUploadIntentService::class)
            ->prepare($disk, [$digest => $path])[0];
        app(PrivateFileUploadIntentService::class)->settle([$id], 'needs_review');
    }

    private function report(): array
    {
        return app(PrivateFileUploadReconciliationPreviewService::class)->preview(10);
    }

    public function test_stable_weak_metadata_is_explicitly_not_an_immutable_generation(): void
    {
        $path = 'inbox/synthetic/stable-size-mtime.png';
        $this->journal($path);
        InboxItem::query()->create([
            'source_type' => 'image', 'status' => 'new', 'storage_path' => $path,
        ]);
        Storage::put($path, 'original-bytes');

        $report = $this->report();
        $this->assertSame('ready', $report['schema']);
        $this->assertSame('observations_only', $report['outcome']);
        $this->assertSame('weak_metadata_not_generation_bound', $report['consistency_probe']);
        $this->assertSame([
            'weak_pair_unchanged' => 1,
            'change_observed' => 0,
            'unverifiable' => 0,
            'generation_verified' => 0,
        ], $report['consistency']);
        $this->assertSame(1, $report['categories']['reference_and_object_observed']);
        $this->assertSame(0, $report['destructive_actions']);
        Storage::assertExists($path);
        $this->assertSame('original-bytes', Storage::get($path));
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertStringNotContainsString('stable-size-mtime', json_encode($report));
    }

    public function test_change_of_object_metadata_between_samples_is_suspect(): void
    {
        $path = 'study-recall-sources/synthetic/race-object.pdf';
        $this->journal($path);
        Storage::put($path, 'short');
        $real = app(PrivateFileReconciliationSnapshotService::class);
        $calls = 0;
        $mock = Mockery::mock(PrivateFileReconciliationSnapshotService::class)->makePartial();
        $mock->shouldReceive('capture')->twice()->andReturnUsing(
            function (string $disk, string $p, string $digest) use ($real, $path, &$calls): array {
                $sample = $real->capture($disk, $p, $digest);
                if (++$calls === 1) {
                    // Simulate an uncoordinated writer between samples.
                    Storage::put($path, 'this-is-a-different-and-longer-generation');
                }
                return $sample;
            },
        );
        app()->instance(PrivateFileReconciliationSnapshotService::class, $mock);

        $result = $this->report();
        $this->assertSame(2, $calls);
        $this->assertSame('attention', $result['outcome']);
        $this->assertSame(1, $result['consistency']['change_observed']);
        $this->assertSame(1, $result['categories']['unverifiable']);
        $this->assertSame(0, $result['consistency']['generation_verified']);
        $this->assertSame(0, $result['fence_row_observed']);
        $this->assertSame(0, $result['fence_row_not_observed']);
        $this->assertSame('this-is-a-different-and-longer-generation', Storage::get($path));
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_reference_added_between_samples_is_detected_without_locking(): void
    {
        $path = 'inbox/synthetic/race-owner.png';
        $this->journal($path);
        Storage::put($path, 'unchanged');
        $real = app(PrivateFileReconciliationSnapshotService::class);
        $calls = 0;
        $mock = Mockery::mock(PrivateFileReconciliationSnapshotService::class)->makePartial();
        $mock->shouldReceive('capture')->twice()->andReturnUsing(
            function (string $disk, string $p, string $digest) use ($real, $path, &$calls): array {
                $sample = $real->capture($disk, $p, $digest);
                if (++$calls === 1) {
                    InboxItem::query()->create([
                        'source_type' => 'image', 'status' => 'new', 'storage_path' => $path,
                    ]);
                }
                return $sample;
            },
        );
        app()->instance(PrivateFileReconciliationSnapshotService::class, $mock);

        $result = $this->report();
        $this->assertSame(1, $result['consistency']['change_observed']);
        $this->assertSame(1, $result['categories']['unverifiable']);
        $this->assertSame(0, $result['consistency']['generation_verified']);
        $this->assertDatabaseCount('inbox_items', 1);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        Storage::assertExists($path);
    }

    public function test_unchanged_samples_do_not_prove_that_bytes_were_unchanged_aba(): void
    {
        $path = 'inbox/synthetic/aba.png';
        $this->journal($path);
        Storage::put($path, 'AAA');
        $sample = [
            'fence' => false,
            'references' => [false, false, false],
            'exists' => true,
            'size' => 3,
            'last_modified' => 1760000000,
        ];
        $calls = 0;
        $mock = Mockery::mock(PrivateFileReconciliationSnapshotService::class)->makePartial();
        $mock->shouldReceive('capture')->twice()->andReturnUsing(
            function () use (&$calls, $path, $sample): array {
                if (++$calls === 1) {
                    Storage::put($path, 'BBB');
                }
                return $sample;
            },
        );
        app()->instance(PrivateFileReconciliationSnapshotService::class, $mock);

        $report = $this->report();
        $this->assertSame(2, $calls);
        $this->assertSame(1, $report['consistency']['weak_pair_unchanged']);
        $this->assertSame(0, $report['consistency']['generation_verified']);
        $this->assertSame('weak_metadata_not_generation_bound', $report['consistency_probe']);
        $this->assertSame('BBB', Storage::get($path));
        $this->assertSame(0, $report['destructive_actions']);
    }

    public function test_bad_metadata_or_provider_exception_is_unverifiable_not_absence(): void
    {
        $path = 'inbox/synthetic/unavailable.png';
        $this->journal($path);
        $bad = [
            'fence' => false,
            'references' => [false, false, false],
            'exists' => true,
            'size' => null,
            'last_modified' => null,
        ];
        $mock = Mockery::mock(PrivateFileReconciliationSnapshotService::class)->makePartial();
        $mock->shouldReceive('capture')->twice()->andReturn($bad);
        app()->instance(PrivateFileReconciliationSnapshotService::class, $mock);
        $report = $this->report();

        $this->assertSame('attention', $report['outcome']);
        $this->assertSame(1, $report['consistency']['unverifiable']);
        $this->assertSame(1, $report['categories']['unverifiable']);
        $this->assertSame(0, $report['categories']['neither_observed']);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_off_mode_preserves_existing_single_sample_and_new_counts_zero(): void
    {
        $path = 'study-recall-sources/synthetic/compat.png';
        $this->journal($path);
        Storage::put($path, 'kept');
        config(['canovia.private_file_upload_consistency_probe_enabled' => false]);

        $report = $this->report();
        $this->assertSame('disabled', $report['consistency_probe']);
        $this->assertSame(1, $report['categories']['object_without_reference_observed']);
        $this->assertSame(0, $report['consistency']['weak_pair_unchanged']);
        $this->assertSame(0, $report['consistency']['generation_verified']);
        $this->assertSame('kept', Storage::get($path));
    }

    public function test_production_deny_still_precedes_probe_even_when_all_flags_on(): void
    {
        $path = 'inbox/synthetic/production-probe-deny.png';
        $this->journal($path);
        $originalEnv = app()['env'];
        app()->instance('env', 'production');
        try {
            $report = $this->report();
        } finally {
            app()->instance('env', $originalEnv);
        }
        $this->assertSame('blocked', $report['outcome']);
        $this->assertSame(0, $report['observed']);
        $this->assertSame('disabled', $report['consistency_probe']);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_operator_output_contains_only_aggregate_not_private_metadata(): void
    {
        $path = 'inbox/synthetic/secret-weak-metadata.png';
        $this->journal($path);
        Storage::put($path, 'user-secrets-must-not-be-printed');
        $this->artisan('canovia:private-upload-reconcile-preview', [
            '--json' => true,
            '--limit' => 1,
        ])->assertSuccessful()
            ->expectsOutputToContain('"generation_verified":0')
            ->doesntExpectOutputToContain('secret-weak-metadata')
            ->doesntExpectOutputToContain('user-secrets');
        Storage::assertExists($path);
    }
}
