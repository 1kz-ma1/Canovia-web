<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFileSyntheticCrashMatrixService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileSyntheticCrashMatrixG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'r19-disposable-bucket',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_generation_evidence_enabled' => true,
            'canovia.private_file_write_receipt_handoff_enabled' => true,
            'canovia.private_file_synthetic_crash_matrix_enabled' => true,
        ]);
    }

    private function pilot(): PrivateFileSyntheticCrashMatrixService
    {
        return app(PrivateFileSyntheticCrashMatrixService::class);
    }

    private function runPhase(string $name, string $stop): array
    {
        return $this->pilot()->simulate(
            'inbox/synthetic/'.$name.'.png',
            'R19-synthetic-version-'.$name,
            $stop,
        );
    }

    private function intentId(string $name): int
    {
        return (int) DB::table('private_file_upload_intents')
            ->where('status', 'prepared')
            ->orWhere('status', 'committed')
            ->orderByDesc('id')
            ->value('id');
    }

    private function assertConservative(array $result): void
    {
        $this->assertFalse($result['object_write_verified']);
        $this->assertFalse($result['generation_verified']);
        $this->assertFalse($result['delete_authorized']);
        $this->assertFalse($result['retry_authorized']);
        $this->assertSame(0, $result['storage_mutations']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('inbox/', $json);
        $this->assertStringNotContainsString('r19-disposable-bucket', $json);
        $this->assertStringNotContainsString('synthetic-version', $json);
    }

    public function test_crash_before_and_after_synthetic_write_ack_are_indistinguishable(): void
    {
        $before = $this->runPhase('before', 'halt_after_prepare');
        $this->assertSame('prepared_unresolved', $before['state']);
        $idBefore = $this->intentId('before');
        $viewBefore = $this->pilot()->review($idBefore);
        $this->assertSame('prepared_no_owner_ambiguous', $viewBefore['state']);

        $after = $this->runPhase('after', 'halt_after_synthetic_write_ack');
        $this->assertSame('prepared_unresolved', $after['state']);
        $idAfter = $this->intentId('after');
        $viewAfter = $this->pilot()->review($idAfter);
        $this->assertSame($viewBefore, $viewAfter);

        foreach ([$before, $after, $viewBefore, $viewAfter] as $r) {
            $this->assertConservative($r);
        }
        $this->assertDatabaseCount('private_file_upload_intents', 2);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_crash_after_owner_commit_leaves_prepared_journal_and_no_evidence(): void
    {
        $r = $this->runPhase('owner', 'halt_after_owner_commit');
        $this->assertSame('owner_committed_journal_prepared', $r['state']);
        $id = $this->intentId('owner');
        $view = $this->pilot()->review($id);
        $this->assertSame('prepared_with_owner_ambiguous', $view['state']);
        $this->assertConservative($view);
        $this->assertDatabaseHas('private_file_upload_intents', [
            'id' => $id,
            'status' => 'prepared',
        ]);
        $this->assertDatabaseCount('inbox_items', 1);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_crash_after_journal_settlement_stays_committed_without_evidence(): void
    {
        $r = $this->runPhase('settled', 'halt_after_journal_settle');
        $this->assertSame('journal_committed_evidence_missing', $r['state']);
        $id = $this->intentId('settled');
        $view = $this->pilot()->review($id);
        $this->assertSame('committed_owner_without_evidence', $view['state']);
        $this->assertConservative($view);
        $this->assertDatabaseHas('private_file_upload_intents', [
            'id' => $id, 'status' => 'committed',
        ]);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_complete_synthetic_handoff_does_not_establish_provider_generation(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/preserved.txt', 'leave bytes alone');
        $r = $this->runPhase('complete', 'complete');
        $this->assertSame('synthetic_complete_unattested', $r['state']);
        $id = $this->intentId('complete');
        $view = $this->pilot()->review($id);
        $this->assertSame('synthetic_evidence_present_unattested', $view['state']);
        $this->assertConservative($r);
        $this->assertConservative($view);
        $this->assertDatabaseCount('inbox_items', 1);
        $this->assertDatabaseCount('private_file_generation_evidence', 1);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertSame('leave bytes alone',
            Storage::disk('local')->get('inbox/synthetic/preserved.txt'));
        $evidence = DB::table('private_file_generation_evidence')->first();
        $this->assertSame('synthetic_fixture', $evidence->evidence_origin);
    }

    public function test_duplicate_key_is_blocked_after_crash_without_creating_any_new_owner(): void
    {
        $first = $this->runPhase('retry', 'halt_after_synthetic_write_ack');
        $this->assertSame('prepared_unresolved', $first['state']);
        $retry = $this->runPhase('retry', 'complete');
        $this->assertSame('prepare_rejected_or_already_claimed', $retry['state']);
        $this->assertConservative($retry);
        $this->assertDatabaseCount('private_file_upload_intents', 1);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_mutated_owner_and_evidence_are_not_interpreted_as_valid(): void
    {
        $this->runPhase('ownerchange', 'complete');
        $id = $this->intentId('ownerchange');
        DB::table('inbox_items')->where('storage_path', 'inbox/synthetic/ownerchange.png')
            ->update(['storage_path' => 'inbox/synthetic/unrelated.png']);
        $changed = $this->pilot()->review($id);
        $this->assertSame('committed_owner_missing_unverifiable', $changed['state']);
        $this->assertConservative($changed);

        $this->runPhase('corrupt', 'complete');
        $idCorrupt = $this->intentId('corrupt');
        DB::table('private_file_generation_evidence')
            ->where('upload_intent_id', $idCorrupt)
            ->update(['encrypted_version_id' => 'tampered']);
        $broken = $this->pilot()->review($idCorrupt);
        $this->assertSame('evidence_identity_unverifiable', $broken['state']);
        $this->assertConservative($broken);
    }

    public function test_disabled_wrong_environment_nested_transaction_and_unknown_phase_fail_closed(): void
    {
        config(['canovia.private_file_synthetic_crash_matrix_enabled' => false]);
        $this->assertSame('blocked', $this->runPhase('off', 'complete')['state']);
        config(['canovia.private_file_synthetic_crash_matrix_enabled' => true]);
        $this->assertSame('blocked', $this->runPhase('phase', 'unexpected-phase')['state']);

        $nested = DB::transaction(fn () => $this->runPhase('nested', 'complete'));
        $this->assertSame('blocked', $nested['state']);
        $original = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->runPhase('prod', 'complete')['state']);
            $this->assertSame('blocked', $this->pilot()->review(1)['state']);
        } finally {
            app()->instance('env', $original);
        }
        $this->assertDatabaseCount('private_file_upload_intents', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_unverified_write_can_never_be_automatically_replayed_or_deleted(): void
    {
        $this->runPhase('noautoretry', 'halt_after_owner_commit');
        $id = $this->intentId('noautoretry');
        $before = DB::table('private_file_upload_intents')->first(['status', 'updated_at']);
        for ($i = 0; $i < 3; $i++) {
            $view = $this->pilot()->review($id);
            $this->assertSame('prepared_with_owner_ambiguous', $view['state']);
            $this->assertConservative($view);
        }
        $after = DB::table('private_file_upload_intents')->first(['status', 'updated_at']);
        $this->assertEquals($before, $after);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }
}
