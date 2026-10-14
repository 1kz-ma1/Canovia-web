<?php

namespace Tests\Feature;

use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileProviderReconciliationService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileProviderReconciliationG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r21-private-fixture-bucket';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => self::BUCKET,
            'canovia.private_file_path_fence_enabled' => true,
            'canovia.private_file_upload_journal_enabled' => true,
            'canovia.private_file_write_attempt_enabled' => true,
            'canovia.private_file_provider_reconciliation_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function fixture(string $name): array
    {
        $path = 'inbox/synthetic/r21-'.$name.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        $attempt = app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent);
        return [$intent, $attempt, $path];
    }

    private function probe(array $attempt, string $path, string $outcome = 'present',
        ?string $version = 'version-r21', ?int $at = null): array
    {
        return app(PrivateFileSyntheticProviderProbeAdapter::class)->observation(
            $attempt['attempt_id'], $attempt['request_token'], self::BUCKET,
            $path, $outcome, $version, $at,
        );
    }

    private function inspect(int $intent, array $observation): array
    {
        return app(PrivateFileProviderReconciliationService::class)
            ->inspect($intent, $observation);
    }

    private function assertUnsafe(array $result): void
    {
        $this->assertSame(
            ['state', 'provider_attested', 'generation_verified', 'retry_authorized', 'delete_authorized'],
            array_keys($result),
        );
        foreach (['provider_attested', 'generation_verified', 'retry_authorized', 'delete_authorized'] as $key) {
            $this->assertFalse($result[$key]);
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'version-r21', 'app-key', 'request_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_present_without_receipt_is_not_proof_of_success_or_replay_safety(): void
    {
        [$intent, $attempt, $path] = $this->fixture('without-receipt');
        $result = $this->inspect($intent, $this->probe($attempt, $path));
        $this->assertSame('present_without_durable_ack', $result['state']);
        $this->assertUnsafe($result);
        $this->assertSame('reserved',
            DB::table('private_file_write_attempts')->where('id', $attempt['attempt_id'])->value('status'));
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_same_and_different_versions_still_never_attest_a_generation(): void
    {
        [$intent, $attempt, $path] = $this->fixture('version');
        $receipt = app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'version-r21',
        );
        $this->assertSame('synthetic_ack_durable_not_attested', $receipt['state']);

        $same = $this->inspect($intent, $this->probe($attempt, $path));
        $different = $this->inspect($intent, $this->probe($attempt, $path, 'present', 'another-version'));
        $unknown = $this->inspect($intent, $this->probe($attempt, $path, 'present', null));
        $this->assertSame('version_match_unattested', $same['state']);
        $this->assertSame('version_mismatch_unattested', $different['state']);
        $this->assertSame('present_generation_unknown', $unknown['state']);
        foreach ([$same, $different, $unknown] as $result) {
            $this->assertUnsafe($result);
        }
    }

    public function test_absence_unknown_network_error_timeout_and_aba_are_always_inconclusive(): void
    {
        [$intent, $attempt, $path] = $this->fixture('negative');
        foreach ([
            'absent' => 'absent_not_replay_safe',
            'unknown' => 'provider_outcome_unknown',
            'network_error' => 'transport_outcome_unknown',
            'timeout' => 'transport_outcome_unknown',
        ] as $outcome => $expected) {
            $result = $this->inspect($intent, $this->probe($attempt, $path, $outcome, null));
            $this->assertSame($expected, $result['state']);
            $this->assertUnsafe($result);
        }

        app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'version-r21',
        );
        // A synthetic same-version snapshot cannot exclude A->B->A, or an
        // external writer between observation and any later operation.
        $first = $this->inspect($intent, $this->probe($attempt, $path));
        $afterAba = $this->inspect($intent, $this->probe($attempt, $path));
        $this->assertSame($first, $afterAba);
        $this->assertSame('version_match_unattested', $afterAba['state']);
        $this->assertUnsafe($afterAba);
    }

    public function test_wrong_key_bucket_attempt_token_origin_and_extra_authority_are_rejected(): void
    {
        [$intent, $attempt, $path] = $this->fixture('binding');
        [$otherIntent, $otherAttempt] = $this->fixture('other');
        $base = $this->probe($attempt, $path);

        foreach ([
            ['key' => 'inbox/synthetic/another.png'],
            ['bucket' => 'different-bucket'],
            ['attempt_id' => $otherAttempt['attempt_id']],
            ['request_token' => $otherAttempt['request_token']],
            ['origin' => 'aws_sdk_live'],
            ['provider_attested' => true],
            ['outcome' => 'PutObject'],
            ['disk' => 'local'],
            ['observed_version_id' => 'null'],
            ['observed_version_id' => str_repeat('x', 1025)],
            ['observed_version_id' => "bad\0token"],
            ['attempt_id' => (string) $attempt['attempt_id']],
        ] as $changes) {
            $result = $this->inspect($intent, array_replace($base, $changes));
            $this->assertNotSame('version_match_unattested', $result['state']);
            $this->assertNotSame('present_without_durable_ack', $result['state']);
            $this->assertUnsafe($result);
        }
        $this->assertSame('binding_unverifiable',
            $this->inspect($otherIntent, $base)['state']);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_missing_expired_future_and_impossible_observations_fail_closed(): void
    {
        [$intent, $attempt, $path] = $this->fixture('time');
        $base = $this->probe($attempt, $path);
        $this->assertSame('missing_attempt',
            $this->inspect($intent + 99999, $base)['state']);
        $this->assertSame('stale_or_future_observation',
            $this->inspect($intent, array_replace($base, [
                'observed_at' => now()->subMinutes(6)->timestamp,
            ]))['state']);
        $this->assertSame('stale_or_future_observation',
            $this->inspect($intent, array_replace($base, [
                'observed_at' => now()->addMinute()->timestamp,
            ]))['state']);
        foreach ([
            ['outcome' => 'absent', 'observed_version_id' => 'version-r21'],
            ['outcome' => 'timeout', 'observed_version_id' => 'version-r21'],
            ['observed_at' => 'today'],
        ] as $changes) {
            $this->assertSame('invalid_observation',
                $this->inspect($intent, array_replace($base, $changes))['state']);
        }
        $this->assertUnsafe($this->inspect($intent, $base));
    }

    public function test_epoch_app_key_ciphertext_and_journal_tampering_reject_observations(): void
    {
        [$intent, $attempt, $path] = $this->fixture('rotation');
        $probe = $this->probe($attempt, $path);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('binding_unverifiable', $this->inspect($intent, $probe)['state']);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        $originalKey = config('app.key');
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->assertSame('binding_unverifiable', $this->inspect($intent, $probe)['state']);
        config(['app.key' => $originalKey]);

        DB::table('private_file_upload_intents')->where('id', $intent)
            ->update(['encrypted_path' => 'corrupted']);
        $this->assertSame('unverifiable', $this->inspect($intent, $probe)['state']);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_off_production_and_default_local_are_blocked_and_probe_is_test_only(): void
    {
        [$intent, $attempt, $path] = $this->fixture('gates');
        $probe = $this->probe($attempt, $path);
        config(['canovia.private_file_provider_reconciliation_enabled' => false]);
        $this->assertSame('blocked', $this->inspect($intent, $probe)['state']);
        try {
            app(PrivateFileSyntheticProviderProbeAdapter::class)->observation(
                $attempt['attempt_id'], $attempt['request_token'], self::BUCKET, $path, 'absent',
            );
            $this->fail('Probe should be disabled');
        } catch (RuntimeException) {}
        config(['canovia.private_file_provider_reconciliation_enabled' => true]);

        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked', $this->inspect($intent, $probe)['state']);
        config(['filesystems.default' => 's3']);

        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->inspect($intent, $probe)['state']);
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_probe_does_not_touch_existing_bytes_or_any_database_rows(): void
    {
        [$intent, $attempt, $path] = $this->fixture('no-io');
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/retained.png', 'retain');
        $before = DB::table('private_file_write_attempts')->where('id', $attempt['attempt_id'])->first();
        foreach (['present', 'absent', 'unknown', 'timeout', 'network_error'] as $outcome) {
            $result = $this->inspect($intent,
                $this->probe($attempt, $path, $outcome, $outcome === 'present' ? 'version-r21' : null));
            $this->assertUnsafe($result);
        }
        $this->assertEquals($before,
            DB::table('private_file_write_attempts')->where('id', $attempt['attempt_id'])->first());
        $this->assertSame('retain', Storage::disk('local')->get('inbox/synthetic/retained.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }
}
