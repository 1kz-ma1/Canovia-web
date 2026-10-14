<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileUploadReconciliationPreviewService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class PrivateFileUploadReconciliationPreviewG3Test extends TestCase
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
        ]);
    }

    private function journal(string $path, string $state = 'needs_review'): int
    {
        $disk = (string) config('filesystems.default');
        $digest = app(PrivateFilePathFenceService::class)->fingerprint($disk, $path);
        $id = app(PrivateFileUploadIntentService::class)
            ->prepare($disk, [$digest => $path])[0];
        if ($state === 'needs_review') {
            app(PrivateFileUploadIntentService::class)->settle([$id], $state);
        } elseif ($state === 'stale_prepared') {
            DB::table('private_file_upload_intents')->where('id', $id)
                ->update(['created_at' => now()->subDays(2)]);
        }
        return $id;
    }

    private function preview(int $limit = 10): array
    {
        return app(PrivateFileUploadReconciliationPreviewService::class)->preview($limit);
    }

    public function test_four_reference_and_object_combinations_are_only_observations(): void
    {
        $pathA = 'inbox/synthetic/both.png';
        $pathB = 'inbox/synthetic/owner-only.png';
        $pathC = 'study-recall-sources/synthetic/object-only.png';
        $pathD = 'inbox/synthetic/neither.png';
        foreach ([$pathA, $pathB, $pathC, $pathD] as $path) {
            $this->journal($path);
        }
        foreach ([$pathA, $pathB] as $path) {
            InboxItem::query()->create([
                'source_type' => 'image', 'status' => 'new', 'storage_path' => $path,
            ]);
        }
        Storage::put($pathA, 'referenced and present');
        Storage::put($pathC, 'unreferenced at observation');

        $before = DB::table('private_file_upload_intents')->orderBy('id')->get()->toArray();
        $result = $this->preview();
        $this->assertSame('ready', $result['schema']);
        $this->assertSame('observations_only', $result['outcome']);
        $this->assertSame(4, $result['eligible']);
        $this->assertSame(4, $result['observed']);
        $this->assertSame([
            'reference_and_object_observed' => 1,
            'reference_only_observed' => 1,
            'object_without_reference_observed' => 1,
            'neither_observed' => 1,
            'unverifiable' => 0,
        ], $result['categories']);
        $this->assertSame(0, $result['destructive_actions']);
        $this->assertSame(0, $result['fence_row_observed']);
        $this->assertSame(4, $result['fence_row_not_observed']);
        $this->assertEquals($before, DB::table('private_file_upload_intents')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        Storage::assertExists($pathA);
        Storage::assertExists($pathC);
        $this->assertArrayNotHasKey('path', $result);
        $this->assertStringNotContainsString('synthetic', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('inbox/', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_existing_fence_is_observed_without_creating_a_new_row(): void
    {
        $p = 'inbox/synthetic/already-locked.png';
        $this->journal($p);
        $fence = app(PrivateFilePathFenceService::class);
        DB::transaction(fn () => $fence->acquireInTransaction(
            (string) config('filesystems.default'), $p,
        ));

        $result = $this->preview();
        $this->assertSame(1, $result['fence_row_observed']);
        $this->assertSame(0, $result['fence_row_not_observed']);
        $this->assertDatabaseCount('private_file_path_fences', 1);
    }

    public function test_recent_prepared_and_committed_records_are_not_probed(): void
    {
        $fresh = 'inbox/synthetic/in-progress.png';
        $done = 'study-recall-sources/synthetic/finished.png';
        $stale = 'inbox/synthetic/old.png';
        $this->journal($fresh, 'prepared');
        $committed = $this->journal($done, 'prepared');
        app(PrivateFileUploadIntentService::class)->settle([$committed], 'committed');
        $this->journal($stale, 'stale_prepared');

        $result = $this->preview();
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['observed']);
        $this->assertSame(1, $result['categories']['neither_observed']);
        Storage::assertMissing($fresh);
        Storage::assertMissing($done);
    }

    public function test_eligibility_is_bounded_and_does_not_enumerate_unselected_objects(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->journal('inbox/synthetic/batch-'.$i.'.pdf');
        }
        $result = $this->preview(2);
        $this->assertSame(2, $result['observed']);
        $this->assertSame(5, $result['eligible']);
        $this->assertSame(2, array_sum($result['categories']));
        $this->assertSame(2, $result['limit']);
        $this->assertDatabaseCount('private_file_upload_intents', 5);
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_mismatched_digest_and_corrupted_ciphertext_fail_closed(): void
    {
        $idA = $this->journal('inbox/synthetic/digest.png');
        $idB = $this->journal('inbox/synthetic/cipher.png');
        DB::table('private_file_upload_intents')->where('id', $idA)
            ->update(['path_digest' => str_repeat('f', 64)]);
        DB::table('private_file_upload_intents')->where('id', $idB)
            ->update(['encrypted_path' => 'not-authenticated-ciphertext']);

        $result = $this->preview();
        $this->assertSame('attention', $result['outcome']);
        $this->assertSame(2, $result['categories']['unverifiable']);
        $this->assertSame(0, $result['fence_row_observed']);
        $this->assertSame(0, $result['fence_row_not_observed']);
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertStringNotContainsString('not-authenticated', json_encode($result));
        $this->assertStringNotContainsString(str_repeat('f', 64), json_encode($result));
    }

    public function test_production_environment_always_blocks_even_when_all_flags_are_enabled(): void
    {
        $path = 'inbox/synthetic/production-denial.png';
        $this->journal($path);
        Storage::put($path, 'must remain unchanged');
        $originalEnv = app()['env'];

        app()->instance('env', 'production');
        try {
            $result = $this->preview();
        } finally {
            app()->instance('env', $originalEnv);
        }

        $this->assertSame('blocked', $result['outcome']);
        $this->assertSame('not_checked', $result['schema']);
        $this->assertSame(0, $result['eligible']);
        $this->assertSame(0, $result['observed']);
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertSame('must remain unchanged', Storage::get($path));
    }

    public function test_disabled_mode_and_missing_schema_never_probe_objects(): void
    {
        $path = 'inbox/synthetic/dont-look.png';
        $this->journal($path);
        config(['canovia.private_file_upload_reconcile_preview_enabled' => false]);
        $off = $this->preview();
        $this->assertSame('blocked', $off['outcome']);
        $this->assertSame('not_checked', $off['schema']);
        $this->assertSame(0, $off['observed']);

        config(['canovia.private_file_upload_reconcile_preview_enabled' => true]);
        $schema = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->once()
            ->with('private_file_upload_intents')->andReturn(false);
        try {
            $missing = $this->preview();
        } finally {
            Schema::swap($schema);
        }
        $this->assertSame('unavailable', $missing['outcome']);
        $this->assertSame(0, $missing['eligible']);
        Storage::assertMissing($path);
    }

    public function test_invalid_limit_is_rejected_before_any_query(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preview(21);
    }

    public function test_operator_command_prints_only_redacted_aggregate_output(): void
    {
        $path = 'study-recall-sources/synthetic/secret-name.png';
        $this->journal($path, 'stale_prepared');
        $this->artisan('canovia:private-upload-reconcile-preview', [
            '--json' => true, '--limit' => 1,
        ])->assertSuccessful()
            ->expectsOutputToContain('"destructive_actions":0')
            ->doesntExpectOutputToContain('secret-name')
            ->doesntExpectOutputToContain('study-recall-sources/');
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }
}
