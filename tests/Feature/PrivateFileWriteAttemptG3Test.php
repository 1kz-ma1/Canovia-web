<?php

namespace Tests\Feature;

use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileWriteAttemptG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => 'r20-disposable-fixture-bucket',
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_write_attempt_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    private function service(): PrivateFileWriteAttemptService
    {
        return app(PrivateFileWriteAttemptService::class);
    }

    private function journal(string $label, bool $committed = false): int
    {
        $path = 'inbox/synthetic/r20-'.$label.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];

        if ($committed) {
            app(PrivateFileUploadIntentService::class)->settle([$intent], 'committed');
        }
        return $intent;
    }

    private function assertDenied(array $result): void
    {
        $this->assertFalse($result['provider_attested']);
        $this->assertFalse($result['retry_authorized']);
        $this->assertFalse($result['delete_authorized']);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('r20-disposable-fixture-bucket', $serialized);
        $this->assertStringNotContainsString('inbox/', $serialized);
    }

    public function test_reservation_is_durable_before_any_simulated_storage_ack(): void
    {
        $intent = $this->journal('prewrite');
        $reserved = $this->service()->reserveSynthetic($intent);
        $this->assertGreaterThan(0, $reserved['attempt_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/D', $reserved['request_token']);
        $row = DB::table('private_file_write_attempts')->first();
        $this->assertSame($intent, (int) $row->upload_intent_id);
        $this->assertSame('reserved', $row->status);
        $this->assertNull($row->encrypted_synthetic_version_id);
        $this->assertNotSame($reserved['request_token'], $row->request_token_digest);
        $this->assertSame(64, strlen($row->request_token_digest));
        $this->assertSame('prepared', DB::table('private_file_upload_intents')
            ->where('id', $intent)->value('status'));

        // A newly constructed service has access to only committed facts.
        $result = $this->service()->review($intent);
        $this->assertSame('reserved_outcome_unknown', $result['state']);
        $this->assertDenied($result);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_crash_before_and_after_simulated_provider_ack_are_indistinguishable_without_persisted_receipt(): void
    {
        $one = $this->journal('before-ack');
        $this->service()->reserveSynthetic($one);
        $before = $this->service()->review($one);

        $two = $this->journal('after-ack');
        $this->service()->reserveSynthetic($two);
        // Simulate bytes acknowledged in memory, followed by process death
        // BEFORE recordSyntheticAck(). Nothing is written to storage.
        $syntheticAckInLostProcessMemory = 'synthetic-success-not-durable';
        $after = $this->service()->review($two);
        unset($syntheticAckInLostProcessMemory);

        $this->assertSame($before, $after);
        $this->assertSame('reserved_outcome_unknown', $after['state']);
        $this->assertDenied($after);
        $this->assertDatabaseCount('private_file_write_attempts', 2);
        $this->assertSame(2, DB::table('private_file_write_attempts')
            ->where('status', 'reserved')->count());
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_synthetic_ack_can_be_durable_before_owner_exists_but_is_never_attested(): void
    {
        $intent = $this->journal('ack');
        $attempt = $this->service()->reserveSynthetic($intent);
        $version = 'private-synthetic-version-token';

        $saved = $this->service()->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], $version,
        );
        $this->assertSame('synthetic_ack_durable_not_attested', $saved['state']);
        $this->assertDenied($saved);
        $stored = DB::table('private_file_write_attempts')->first();
        $this->assertSame('synthetic_ack_recorded', $stored->status);
        $this->assertStringNotContainsString($version, $stored->encrypted_synthetic_version_id);
        $this->assertSame($version,
            \Illuminate\Support\Facades\Crypt::decryptString(
                $stored->encrypted_synthetic_version_id,
            ));

        $review = $this->service()->review($intent);
        $this->assertSame('synthetic_ack_durable_not_attested', $review['state']);
        $this->assertDenied($review);
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
        $report = $this->service()->report();
        $this->assertSame(0, $report['reserved']);
        $this->assertSame(1, $report['synthetic_ack_recorded']);
        $this->assertFalse($report['provider_attested']);
        $this->assertFalse($report['retry_authorized']);
        $this->assertFalse($report['delete_authorized']);
    }

    public function test_duplicate_attempt_never_reuses_tombstoned_journal_identity(): void
    {
        $intent = $this->journal('duplicate');
        $original = $this->service()->reserveSynthetic($intent);
        try {
            $this->service()->reserveSynthetic($intent);
            $this->fail('An ambiguous attempt cannot be recreated');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic write-attempt reservation unavailable.', $e->getMessage());
        }

        $this->assertDatabaseCount('private_file_write_attempts', 1);
        $this->assertSame($original['attempt_id'], (int) DB::table('private_file_write_attempts')
            ->value('id'));
        $this->assertSame('reserved_outcome_unknown', $this->service()->review($intent)['state']);
    }

    public function test_wrong_or_cross_attempt_token_cannot_forge_or_replace_ack(): void
    {
        $intentA = $this->journal('tokenA');
        $attemptA = $this->service()->reserveSynthetic($intentA);
        $intentB = $this->journal('tokenB');
        $attemptB = $this->service()->reserveSynthetic($intentB);

        foreach ([
            str_repeat('0', 48),
            'invalid-token',
            $attemptB['request_token'],
        ] as $wrongToken) {
            $rejected = $this->service()->recordSyntheticAck(
                $attemptA['attempt_id'], $wrongToken, 'pretend-success',
            );
            $this->assertNotSame('synthetic_ack_durable_not_attested', $rejected['state']);
            $this->assertDenied($rejected);
        }
        $this->assertSame('reserved_outcome_unknown', $this->service()->review($intentA)['state']);
        $this->assertSame('synthetic_ack_durable_not_attested',
            $this->service()->recordSyntheticAck(
                $attemptA['attempt_id'], $attemptA['request_token'], 'first-ack',
            )['state']);
        $this->assertSame('not_reserved_or_missing',
            $this->service()->recordSyntheticAck(
                $attemptA['attempt_id'], $attemptA['request_token'], 'attempted-overwrite',
            )['state']);
        $this->assertSame('first-ack',
            \Illuminate\Support\Facades\Crypt::decryptString(
                DB::table('private_file_write_attempts')
                    ->where('id', $attemptA['attempt_id'])
                    ->value('encrypted_synthetic_version_id'),
            ));
        $this->assertSame('reserved_outcome_unknown', $this->service()->review($intentB)['state']);
    }

    public function test_invalid_version_or_uncommitted_journal_cannot_persist_ack(): void
    {
        $intent = $this->journal('invalid-version');
        $attempt = $this->service()->reserveSynthetic($intent);
        foreach (['', ' ', 'null', 'NULL', str_repeat('v', 1025), "bad\0version"] as $version) {
            $bad = $this->service()->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], $version,
            );
            $this->assertSame('blocked_or_invalid', $bad['state']);
        }
        app(PrivateFileUploadIntentService::class)->settle([$intent], 'committed');
        $bad = $this->service()->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'late-version',
        );
        $this->assertSame('journal_unavailable', $bad['state']);
        $this->assertSame('reserved_outcome_unknown', $this->service()->review($intent)['state']);
        $this->assertNull(DB::table('private_file_write_attempts')
            ->value('encrypted_synthetic_version_id'));
    }

    public function test_rotation_bucket_change_and_corrupt_records_fail_closed(): void
    {
        $intent = $this->journal('rotation');
        $attempt = $this->service()->reserveSynthetic($intent);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('binding_unverifiable', $this->service()->review($intent)['state']);
        $this->assertSame('binding_unverifiable',
            $this->service()->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], 'v1',
            )['state']);

        config([
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
            'filesystems.disks.s3.bucket' => 'changed-bucket',
        ]);
        $this->assertSame('binding_unverifiable', $this->service()->review($intent)['state']);
        config(['filesystems.disks.s3.bucket' => 'r20-disposable-fixture-bucket']);
        DB::table('private_file_upload_intents')->where('id', $intent)
            ->update(['encrypted_path' => 'bad-ciphertext']);
        $this->assertSame('unverifiable', $this->service()->review($intent)['state']);
        $this->assertSame('unverifiable',
            $this->service()->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], 'v1',
            )['state']);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_legacy_missing_attempt_and_non_prepared_intent_are_not_silently_backfilled(): void
    {
        $intent = $this->journal('legacy');
        $this->assertSame('legacy_or_missing_attempt', $this->service()->review($intent)['state']);
        app(PrivateFileUploadIntentService::class)->settle([$intent], 'committed');
        try {
            $this->service()->reserveSynthetic($intent);
            $this->fail('Committed legacy upload cannot receive synthetic prewrite');
        } catch (RuntimeException) {}
        $this->assertDatabaseCount('private_file_write_attempts', 0);
        $this->assertFalse($this->service()->review($intent)['delete_authorized']);
    }

    public function test_default_off_production_local_and_nested_transactions_all_deny_reservation(): void
    {
        $intent = $this->journal('deny');
        config(['canovia.private_file_write_attempt_enabled' => false]);
        $this->assertSame('blocked', $this->service()->review($intent)['state']);
        $this->assertSame('blocked', $this->service()->report()['status']);

        config(['canovia.private_file_write_attempt_enabled' => true]);
        $prior = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->service()->review($intent)['state']);
            try {
                $this->service()->reserveSynthetic($intent);
                $this->fail('Production reservation was allowed');
            } catch (RuntimeException) {}
        } finally {
            app()->instance('env', $prior);
        }

        config(['filesystems.default' => 'local']);
        try {
            $this->service()->reserveSynthetic($intent);
            $this->fail('Local disk reservation was allowed');
        } catch (RuntimeException) {}
        config(['filesystems.default' => 's3']);

        DB::transaction(function () use ($intent): void {
            try {
                $this->service()->reserveSynthetic($intent);
                $this->fail('Nested reservation was allowed');
            } catch (RuntimeException) {}
        });
        $this->assertDatabaseCount('private_file_write_attempts', 0);
    }

    public function test_review_and_redacted_operator_health_do_not_read_or_delete_physical_objects(): void
    {
        $intent = $this->journal('safehealth');
        $this->service()->reserveSynthetic($intent);
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/do-not-delete.png', 'stay-present');

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('reserved_outcome_unknown', $this->service()->review($intent)['state']);
        }

        $this->artisan('canovia:private-file-write-attempt-health', [
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('"reserved":1')
            ->doesntExpectOutputToContain('do-not-delete.png')
            ->doesntExpectOutputToContain('safehealth');

        $this->assertSame('stay-present',
            Storage::disk('local')->get('inbox/synthetic/do-not-delete.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }
}
