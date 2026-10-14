<?php

namespace Tests\Feature;

use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileSyntheticWriteProvenanceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileSyntheticWriteProvenanceG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r23-disposable-private-bucket';

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
            'canovia.private_file_synthetic_write_provenance_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function fixture(string $suffix, bool $ack = true): array
    {
        $path = 'inbox/synthetic/r23-'.$suffix.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        $attempt = app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent);

        if ($ack) {
            $saved = app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], 'fixture-generation-vA',
            );
            $this->assertSame('synthetic_ack_durable_not_attested', $saved['state']);
        }

        return [$intent, $attempt, $path];
    }

    private function receipt(array $attempt, string $path): array
    {
        return [
            'origin' => 'synthetic_put_reply_v1',
            'operation' => 'PutObject',
            'status_code' => 200,
            'attempt_id' => $attempt['attempt_id'],
            'request_token' => $attempt['request_token'],
            'disk' => 's3',
            'bucket' => self::BUCKET,
            'key' => $path,
            'version_id' => 'fixture-generation-vA',
            'request_id' => 'synthetic-request-r23',
            'received_at' => now()->timestamp,
        ];
    }

    private function observation(array $attempt, string $path, ?string $version = 'fixture-generation-vA'): array
    {
        return app(PrivateFileSyntheticProviderProbeAdapter::class)->observation(
            $attempt['attempt_id'], $attempt['request_token'],
            self::BUCKET, $path, 'present', $version, now()->timestamp,
        );
    }

    private function claims(): array
    {
        return [
            'origin' => 'synthetic_capability_claims_v1',
            'provider' => 's3_claimed',
            'versioning' => 'enabled',
            'response_binding' => 'claimed_bound',
            'external_writers' => 'claimed_excluded',
            'conditional_generation' => 'claimed_supported',
        ];
    }

    private function inspect(int $intent, array $receipt, array $observation, array $claims): array
    {
        return app(PrivateFileSyntheticWriteProvenanceService::class)
            ->inspect($intent, $receipt, $observation, $claims);
    }

    private function assertDenied(array $result): void
    {
        $this->assertEqualsCanonicalizing([
            'state', 'provider_attested', 'write_receipt_authenticated',
            'generation_verified', 'external_writers_excluded',
            'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ], array_keys($result));
        foreach ([
            'provider_attested', 'write_receipt_authenticated',
            'generation_verified', 'external_writers_excluded',
            'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ] as $key) {
            $this->assertFalse($result[$key], $key);
        }
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'fixture-generation', 'synthetic-request-r23', 'app-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_all_synthetic_evidence_matches_but_cannot_attest_provider_or_authorize_actions(): void
    {
        [$intent, $attempt, $path] = $this->fixture('matching');
        $result = $this->inspect($intent, $this->receipt($attempt, $path),
            $this->observation($attempt, $path), $this->claims());
        $this->assertSame('synthetic_chain_consistent_unattested', $result['state']);
        $this->assertDenied($result);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
    }

    public function test_missing_persisted_ack_does_not_turn_simulated_write_success_into_provider_proof(): void
    {
        [$intent, $attempt, $path] = $this->fixture('missing-ack', false);
        $result = $this->inspect($intent, $this->receipt($attempt, $path),
            $this->observation($attempt, $path), $this->claims());
        $this->assertSame('durable_ack_missing', $result['state']);
        $this->assertDenied($result);
        $this->assertSame('reserved', DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $intent)->value('status'));
    }

    public function test_receipt_observation_and_durable_version_conflicts_are_distinct(): void
    {
        [$intent, $attempt, $path] = $this->fixture('version');
        $receipt = $this->receipt($attempt, $path);
        $observation = $this->observation($attempt, $path);

        $mismatch = $this->inspect($intent, array_replace($receipt, [
            'version_id' => 'fixture-other-generation',
        ]), $observation, $this->claims());
        $this->assertSame('receipt_observation_version_mismatch', $mismatch['state']);
        $this->assertDenied($mismatch);

        $observedDifferent = $this->observation($attempt, $path, 'fixture-other-generation');
        $durableConflict = $this->inspect($intent, array_replace($receipt, [
            'version_id' => 'fixture-other-generation',
        ]), $observedDifferent, $this->claims());
        $this->assertSame('durable_ack_generation_conflict', $durableConflict['state']);
        $this->assertDenied($durableConflict);
    }

    public function test_wrong_attempt_token_key_bucket_and_spoofed_response_are_denied(): void
    {
        [$intent, $attempt, $path] = $this->fixture('scope');
        [$another, $otherAttempt, $otherPath] = $this->fixture('other');
        $receipt = $this->receipt($attempt, $path);
        $observation = $this->observation($attempt, $path);

        foreach ([
            ['attempt_id' => $otherAttempt['attempt_id']],
            ['request_token' => $otherAttempt['request_token']],
            ['bucket' => 'wrong-private-bucket'],
            ['key' => $otherPath],
        ] as $change) {
            $result = $this->inspect($intent,
                array_replace($receipt, $change), $observation, $this->claims());
            $this->assertSame('receipt_observation_binding_mismatch', $result['state']);
            $this->assertDenied($result);
        }
        $crossIntent = $this->inspect($another, $receipt, $observation, $this->claims());
        $this->assertSame('observation_unverifiable', $crossIntent['state']);
        $this->assertDenied($crossIntent);

        foreach ([
            ['origin' => 'aws_live_sdk'],
            ['operation' => 'DeleteObject'],
            ['status_code' => '200'],
            ['status_code' => 500],
            ['version_id' => 'null'],
            ['version_id' => ''],
            ['version_id' => str_repeat('x', 1025)],
            ['version_id' => "bad\0v"],
            ['request_id' => "bad\nidentifier"],
            ['delete_authorized' => true],
        ] as $change) {
            $result = $this->inspect($intent,
                array_replace($receipt, $change), $observation, $this->claims());
            $this->assertSame('invalid_synthetic_receipt', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_unverifiable_provider_capability_claims_never_upgrade_to_attestation(): void
    {
        [$intent, $attempt, $path] = $this->fixture('claims');
        $receipt = $this->receipt($attempt, $path);
        $observed = $this->observation($attempt, $path);

        foreach ([
            ['versioning' => 'suspended', 'expected' => 'versioning_unattested_or_unavailable'],
            ['versioning' => 'disabled', 'expected' => 'versioning_unattested_or_unavailable'],
            ['versioning' => 'unknown', 'expected' => 'versioning_unattested_or_unavailable'],
            ['response_binding' => 'unavailable', 'expected' => 'response_provenance_unavailable'],
            ['external_writers' => 'possible', 'expected' => 'external_writers_unexcluded'],
            ['external_writers' => 'unknown', 'expected' => 'external_writers_unexcluded'],
            ['conditional_generation' => 'unsupported', 'expected' => 'conditional_generation_unproven'],
        ] as $entry) {
            $expected = $entry['expected'];
            unset($entry['expected']);
            $result = $this->inspect($intent, $receipt, $observed, array_replace($this->claims(), $entry));
            $this->assertSame($expected, $result['state']);
            $this->assertDenied($result);
        }
        foreach ([
            ['provider_attested' => true],
            ['provider' => 's3_authenticated'],
            ['versioning' => true],
            ['response_binding' => 'signed'],
        ] as $change) {
            $result = $this->inspect($intent, $receipt, $observed, array_replace($this->claims(), $change));
            $this->assertSame('invalid_synthetic_claims', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_stale_future_and_pre_reservation_receipts_are_not_reused(): void
    {
        [$intent, $attempt, $path] = $this->fixture('timeline');
        $receipt = $this->receipt($attempt, $path);
        $observed = $this->observation($attempt, $path);
        foreach ([
            ['received_at' => now()->subMinutes(7)->timestamp],
            ['received_at' => now()->addMinute()->timestamp],
            ['received_at' => now()->timestamp + 2],
            ['received_at' => 'now'],
        ] as $change) {
            $result = $this->inspect($intent, array_replace($receipt, $change),
                $observed, $this->claims());
            $this->assertSame(is_string($change['received_at'])
                ? 'invalid_synthetic_receipt' : 'receipt_timeline_unverifiable', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_corrupted_ciphertext_epoch_rotation_and_app_key_changes_fail_closed(): void
    {
        [$intent, $attempt, $path] = $this->fixture('crypt');
        $receipt = $this->receipt($attempt, $path);
        $observed = $this->observation($attempt, $path);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('observation_unverifiable',
            $this->inspect($intent, $receipt, $observed, $this->claims())['state']);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        $key = config('app.key');
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->assertSame('observation_unverifiable',
            $this->inspect($intent, $receipt, $observed, $this->claims())['state']);
        config(['app.key' => $key]);

        DB::table('private_file_write_attempts')->where('upload_intent_id', $intent)
            ->update(['encrypted_synthetic_version_id' => 'tampered']);
        $this->assertSame('observation_unverifiable',
            $this->inspect($intent, $receipt, $observed, $this->claims())['state']);
    }

    public function test_default_off_production_and_local_configuration_are_blocked(): void
    {
        [$intent, $attempt, $path] = $this->fixture('gates');
        $receipt = $this->receipt($attempt, $path);
        $observation = $this->observation($attempt, $path);

        config(['canovia.private_file_synthetic_write_provenance_enabled' => false]);
        $this->assertSame('blocked',
            $this->inspect($intent, $receipt, $observation, $this->claims())['state']);
        config(['canovia.private_file_synthetic_write_provenance_enabled' => true]);
        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked',
            $this->inspect($intent, $receipt, $observation, $this->claims())['state']);
        config(['filesystems.default' => 's3']);

        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked',
                $this->inspect($intent, $receipt, $observation, $this->claims())['state']);
        } finally {
            app()->instance('env', $previous);
        }
    }

    public function test_all_inspection_is_read_only_and_does_not_touch_bytes_or_evidence(): void
    {
        [$intent, $attempt, $path] = $this->fixture('read-only');
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/retain.png', 'keep-this');
        $before = DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $intent)->first();

        $result = $this->inspect($intent, $this->receipt($attempt, $path),
            $this->observation($attempt, $path), $this->claims());
        $this->assertDenied($result);
        $this->assertEquals($before, DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $intent)->first());
        $this->assertSame('keep-this', Storage::disk('local')->get('inbox/synthetic/retain.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }
}
