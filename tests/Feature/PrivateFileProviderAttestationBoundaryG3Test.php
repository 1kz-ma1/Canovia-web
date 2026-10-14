<?php

namespace Tests\Feature;

use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileProviderAttestationBoundaryService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileProviderAttestationBoundaryG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r24-isolated-synthetic-bucket';

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
            'canovia.private_file_provider_attestation_boundary_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    private function fixture(string $name, bool $ack = true): array
    {
        $path = 'inbox/synthetic/r24-'.$name.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        $attempt = app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent);
        if ($ack) {
            app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], 'generation-r24-A',
            );
        }
        $receipt = [
            'origin' => 'synthetic_put_reply_v1',
            'operation' => 'PutObject',
            'status_code' => 200,
            'attempt_id' => $attempt['attempt_id'],
            'request_token' => $attempt['request_token'],
            'disk' => 's3',
            'bucket' => self::BUCKET,
            'key' => $path,
            'version_id' => 'generation-r24-A',
            'request_id' => 'synthetic-r24-request',
            'received_at' => now()->timestamp,
        ];
        $observation = app(PrivateFileSyntheticProviderProbeAdapter::class)
            ->observation($attempt['attempt_id'], $attempt['request_token'],
                self::BUCKET, $path, 'present', 'generation-r24-A');
        $claims = [
            'origin' => 'synthetic_capability_claims_v1',
            'provider' => 's3_claimed',
            'versioning' => 'enabled',
            'response_binding' => 'claimed_bound',
            'external_writers' => 'claimed_excluded',
            'conditional_generation' => 'claimed_supported',
        ];
        $assertion = [
            'origin' => 'synthetic_provenance_claim_v1',
            'evidence_kind' => 'claimed_provider_signature',
            'issuer_claim' => 'aws_s3',
            'authenticity_claim' => 'claimed_signed',
            'attempt_id' => $attempt['attempt_id'],
            'request_token' => $attempt['request_token'],
            'bucket' => self::BUCKET,
            'key' => $path,
            'version_id' => 'generation-r24-A',
            'request_id' => 'synthetic-r24-request',
            'issued_at' => now()->timestamp,
        ];

        return [$intent, $receipt, $observation, $claims, $assertion];
    }

    private function classify(int $intent, array $receipt, array $observation, array $claims, array $assertion): array
    {
        return app(PrivateFileProviderAttestationBoundaryService::class)->classify(
            $intent, $receipt, $observation, $claims, $assertion,
        );
    }

    private function assertDenied(array $result, bool $localConsistent = false): void
    {
        $this->assertSame($localConsistent, $result['local_binding_consistent']);
        foreach ([
            'independent_provider_verifier_available', 'provider_identity_verified',
            'write_response_authenticated', 'generation_write_bound',
            'external_writers_excluded', 'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ] as $key) {
            $this->assertFalse($result[$key], $key);
        }
        $this->assertEqualsCanonicalizing([
            'state', 'local_binding_consistent',
            'independent_provider_verifier_available', 'provider_identity_verified',
            'write_response_authenticated', 'generation_write_bound',
            'external_writers_excluded', 'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ], array_keys($result));

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'generation-r24', 'synthetic-r24-request'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_four_purported_evidence_kinds_cannot_be_promoted_to_attestation(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('types');
        foreach ([
            'application_hmac' => 'application_hmac_not_provider_proof',
            'sdk_response_headers' => 'sdk_headers_not_provider_attestation',
            'tls_transport_metadata' => 'tls_transport_not_write_attestation',
            'claimed_provider_signature' => 'claimed_signature_without_trusted_verifier',
        ] as $kind => $expected) {
            // Even claimed provider-issued signed metadata lacks a verifier.
            $result = $this->classify($intent, $receipt, $obs, $claims,
                array_replace($assertion, [
                    'evidence_kind' => $kind,
                    'issuer_claim' => 'aws_s3',
                    'authenticity_claim' => 'claimed_signed',
                ]));
            $this->assertSame($expected, $result['state']);
            $this->assertDenied($result, true);
        }
    }

    public function test_fake_authority_fields_and_unknown_or_malformed_proof_are_rejected(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('shape');
        foreach ([
            ['provider_identity_verified' => true],
            ['retry_authorized' => true],
            ['signature_valid' => true],
            ['evidence_kind' => 'provider_attested'],
            ['origin' => 'real_aws_sdk'],
            ['issuer_claim' => 'verified_aws_s3'],
            ['authenticity_claim' => 'cryptographically_verified'],
            ['attempt_id' => '1'],
            ['request_token' => 2],
            ['issued_at' => 'now'],
            ['request_id' => null],
            ['request_token' => 'replayed'],
        ] as $mutation) {
            $result = $this->classify($intent, $receipt, $obs, $claims,
                array_replace($assertion, $mutation));
            $this->assertSame('invalid_provenance_claim', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_request_version_key_bucket_and_attempt_scope_mismatches_are_denied(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('binding');
        [$otherIntent, $otherReceipt] = $this->fixture('other');
        foreach ([
            ['attempt_id' => $otherReceipt['attempt_id']],
            ['request_token' => $otherReceipt['request_token']],
            ['bucket' => 'other-r24-bucket'],
            ['key' => 'inbox/other.png'],
            ['version_id' => 'generation-r24-B'],
            ['request_id' => 'synthetic-other-request'],
        ] as $mutation) {
            $result = $this->classify($intent, $receipt, $obs, $claims,
                array_replace($assertion, $mutation));
            $this->assertSame('provenance_claim_binding_mismatch', $result['state']);
            $this->assertDenied($result);
        }
        $result = $this->classify($otherIntent, $receipt, $obs, $claims, $assertion);
        $this->assertSame('durable_write_chain_unverifiable', $result['state']);
        $this->assertDenied($result);
    }

    public function test_missing_ack_invalid_r23_chain_and_transport_outcome_are_not_laundered(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('missing', false);
        $result = $this->classify($intent, $receipt, $obs, $claims, $assertion);
        $this->assertSame('durable_write_chain_unverifiable', $result['state']);
        $this->assertDenied($result);

        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('broken');
        $obs['outcome'] = 'timeout';
        $obs['observed_version_id'] = null;
        $result = $this->classify($intent, $receipt, $obs, $claims, $assertion);
        $this->assertSame('durable_write_chain_unverifiable', $result['state']);
        $this->assertDenied($result);

        $obs['outcome'] = 'present';
        $obs['observed_version_id'] = 'generation-r24-B';
        $result = $this->classify($intent, $receipt, $obs, $claims, $assertion);
        $this->assertSame('durable_write_chain_unverifiable', $result['state']);
        $this->assertDenied($result);
    }

    public function test_expired_future_and_post_receipt_claims_cannot_establish_provenance(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('time');
        foreach ([
            now()->subMinutes(7)->timestamp,
            now()->addMinute()->timestamp,
            $receipt['received_at'] + 1,
        ] as $issued) {
            $result = $this->classify($intent, $receipt, $obs, $claims,
                array_replace($assertion, ['issued_at' => $issued]));
            $this->assertSame('provenance_claim_timing_unverifiable', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_rotation_corruption_off_local_production_and_read_only_invariants(): void
    {
        [$intent, $receipt, $obs, $claims, $assertion] = $this->fixture('safety');

        config(['canovia.private_file_provider_attestation_boundary_enabled' => false]);
        $this->assertSame('blocked',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        config(['canovia.private_file_provider_attestation_boundary_enabled' => true]);
        config(['canovia.private_file_synthetic_write_provenance_enabled' => false]);
        $this->assertSame('blocked',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        config(['canovia.private_file_synthetic_write_provenance_enabled' => true]);

        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        config(['filesystems.default' => 's3']);

        $environment = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked',
                $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        } finally {
            app()->instance('env', $environment);
        }

        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/keep-r24.png', 'untouched');
        $before = DB::table('private_file_write_attempts')->where('upload_intent_id', $intent)->first();
        $this->assertDenied(
            $this->classify($intent, $receipt, $obs, $claims, $assertion), true,
        );
        $this->assertEquals($before,
            DB::table('private_file_write_attempts')->where('upload_intent_id', $intent)->first());
        $this->assertSame('untouched', Storage::disk('local')->get('inbox/synthetic/keep-r24.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);

        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('durable_write_chain_unverifiable',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        $key = config('app.key');
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->assertSame('durable_write_chain_unverifiable',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
        config(['app.key' => $key]);

        DB::table('private_file_upload_intents')->where('id', $intent)
            ->update(['encrypted_path' => 'corrupted']);
        $this->assertSame('durable_write_chain_unverifiable',
            $this->classify($intent, $receipt, $obs, $claims, $assertion)['state']);
    }
}
