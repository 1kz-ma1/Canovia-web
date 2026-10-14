<?php

namespace Tests\Feature;

use App\Contracts\PrivateFileIndependentProviderVerifier;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileProviderEvidenceAdmissionService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileUnavailableProviderVerifier;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileProviderEvidenceAdmissionG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r25-disposable-provider-bucket';

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
            'canovia.private_file_provider_evidence_admission_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    private function fixture(string $label, bool $ack = true): array
    {
        $path = 'inbox/synthetic/r25-'.$label.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)->prepare(
            's3', [$digest => $path],
        )[0];
        $attempt = app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent);
        if ($ack) {
            app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
                $attempt['attempt_id'], $attempt['request_token'], 'generation-r25-A',
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
            'version_id' => 'generation-r25-A',
            'request_id' => 'synthetic-r25-request',
            'received_at' => now()->timestamp,
        ];
        $observation = app(PrivateFileSyntheticProviderProbeAdapter::class)->observation(
            $attempt['attempt_id'], $attempt['request_token'],
            self::BUCKET, $path, 'present', 'generation-r25-A',
        );
        $capabilities = [
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
            'version_id' => 'generation-r25-A',
            'request_id' => 'synthetic-r25-request',
            'issued_at' => now()->timestamp,
        ];
        $candidate = [
            'origin' => 'synthetic_verifier_candidate_v1',
            'provider' => 's3_claimed',
            'attempt_id' => $attempt['attempt_id'],
            'request_id' => 'synthetic-r25-request',
            'version_id' => 'generation-r25-A',
            'bucket' => self::BUCKET,
            'key' => $path,
            'trust_root' => 'claimed',
            'write_binding' => 'claimed',
            'anti_replay' => 'claimed',
            'bucket_versioning' => 'enabled_claimed',
            'writer_isolation' => 'claimed',
            'generation_preconditions' => 'claimed',
            'observed_at' => now()->timestamp,
        ];

        return [$intent, $receipt, $observation, $capabilities, $assertion, $candidate];
    }

    private function inspect(array $fixture): array
    {
        return app(PrivateFileProviderEvidenceAdmissionService::class)->review(...$fixture);
    }

    private function assertDenied(array $result, bool $locallyConsistent = false): void
    {
        $this->assertSame($locallyConsistent, $result['local_binding_consistent']);
        foreach ([
            'independent_provider_verifier_available', 'provider_authenticated',
            'generation_write_bound', 'writer_exclusivity_verified',
            'retry_authorized', 'delete_authorized', 'network_requests',
            'storage_mutations',
        ] as $key) {
            $this->assertFalse($result[$key], $key);
        }
        $this->assertEqualsCanonicalizing([
            'provider_identity_and_trust_root', 'response_authenticity',
            'request_and_generation_binding', 'anti_replay_continuity',
            'bucket_versioning_and_iam', 'external_writer_fencing',
            'generation_conditional_operations',
        ], $result['missing_independent_checks']);
        $this->assertEqualsCanonicalizing([
            'state', 'missing_independent_checks', 'local_binding_consistent',
            'independent_provider_verifier_available', 'provider_authenticated',
            'generation_write_bound', 'writer_exclusivity_verified',
            'retry_authorized', 'delete_authorized', 'network_requests',
            'storage_mutations',
        ], array_keys($result));
        $output = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'generation-r25-A',
            'synthetic-r25-request'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_even_all_claimed_requirements_are_not_an_independent_attestation(): void
    {
        $fixture = $this->fixture('all-claimed');
        $result = $this->inspect($fixture);
        $this->assertSame('independent_verifier_unavailable', $result['state']);
        $this->assertDenied($result, true);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_unavailable_verifier_implements_contract_and_never_claims_success(): void
    {
        $verifier = app(PrivateFileUnavailableProviderVerifier::class);
        $this->assertInstanceOf(PrivateFileIndependentProviderVerifier::class, $verifier);
        $this->assertSame([
            'verdict' => 'independent_verifier_unavailable',
            'provider_authenticated' => false,
            'generation_write_bound' => false,
        ], $verifier->verify([
            'signature_verified' => true,
            'allow_delete' => true,
            'version_id' => 'forged-version',
        ]));
    }

    public function test_missing_or_unknown_conditions_are_not_upgraded_by_local_binding(): void
    {
        $fixture = $this->fixture('missing');
        foreach ([
            ['trust_root' => 'missing'],
            ['trust_root' => 'unknown'],
            ['write_binding' => 'missing'],
            ['anti_replay' => 'unknown'],
            ['bucket_versioning' => 'suspended'],
            ['bucket_versioning' => 'disabled'],
            ['bucket_versioning' => 'unknown'],
            ['writer_isolation' => 'missing'],
            ['generation_preconditions' => 'unknown'],
        ] as $change) {
            $candidate = $fixture;
            $candidate[5] = array_replace($fixture[5], $change);
            $result = $this->inspect($candidate);
            $this->assertSame('independent_requirements_unmet', $result['state']);
            $this->assertDenied($result, true);
        }
    }

    public function test_fake_authority_fields_and_invalid_claim_types_fail_closed(): void
    {
        $fixture = $this->fixture('forgery');
        foreach ([
            ['verified' => true],
            ['retry_authorized' => true],
            ['provider_authenticated' => true],
            ['signature' => 'fake'],
            ['origin' => 'real_s3_attestation'],
            ['provider' => 'aws_verified'],
            ['trust_root' => 'verified'],
            ['bucket_versioning' => true],
            ['write_binding' => 1],
            ['attempt_id' => '1'],
            ['observed_at' => 'now'],
        ] as $change) {
            $candidate = $fixture;
            $candidate[5] = array_replace($fixture[5], $change);
            $result = $this->inspect($candidate);
            $this->assertSame('invalid_evidence_candidate', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_cross_attempt_key_bucket_request_and_generation_fail_binding(): void
    {
        $fixture = $this->fixture('scope');
        $other = $this->fixture('different');
        foreach ([
            ['attempt_id' => $other[1]['attempt_id']],
            ['request_id' => 'other-request'],
            ['version_id' => 'generation-r25-B'],
            ['bucket' => 'other-bucket'],
            ['key' => 'inbox/other.png'],
        ] as $change) {
            $candidate = $fixture;
            $candidate[5] = array_replace($fixture[5], $change);
            $result = $this->inspect($candidate);
            $this->assertSame('candidate_scope_unverifiable', $result['state']);
            $this->assertDenied($result);
        }
        $candidate = $fixture;
        $candidate[0] = $other[0];
        $result = $this->inspect($candidate);
        $this->assertSame('prior_local_chain_unverifiable', $result['state']);
        $this->assertDenied($result);
    }

    public function test_bad_timestamps_replayed_and_future_claims_are_denied(): void
    {
        $fixture = $this->fixture('timing');
        foreach ([
            now()->subMinutes(7)->timestamp,
            now()->addMinute()->timestamp,
        ] as $time) {
            $candidate = $fixture;
            $candidate[5] = array_replace($fixture[5], ['observed_at' => $time]);
            $result = $this->inspect($candidate);
            $this->assertSame('candidate_timing_unverifiable', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_corrupt_or_missing_durable_evidence_never_crosses_prior_boundary(): void
    {
        $noAck = $this->fixture('no-ack', false);
        $result = $this->inspect($noAck);
        $this->assertSame('prior_local_chain_unverifiable', $result['state']);
        $this->assertDenied($result);

        $fixture = $this->fixture('broken');
        $fixture[2]['outcome'] = 'timeout';
        $fixture[2]['observed_version_id'] = null;
        $this->assertSame('prior_local_chain_unverifiable',
            $this->inspect($fixture)['state']);

        $fixture = $this->fixture('rotated');
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('prior_local_chain_unverifiable',
            $this->inspect($fixture)['state']);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        $key = config('app.key');
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->assertSame('prior_local_chain_unverifiable',
            $this->inspect($fixture)['state']);
        config(['app.key' => $key]);

        DB::table('private_file_upload_intents')->where('id', $fixture[0])
            ->update(['encrypted_path' => 'corrupt']);
        $this->assertSame('prior_local_chain_unverifiable',
            $this->inspect($fixture)['state']);
    }

    public function test_off_testing_gate_local_and_read_only_guarantee(): void
    {
        $fixture = $this->fixture('read-only');
        config(['canovia.private_file_provider_evidence_admission_enabled' => false]);
        $this->assertSame('blocked', $this->inspect($fixture)['state']);
        config(['canovia.private_file_provider_evidence_admission_enabled' => true]);
        config(['canovia.private_file_provider_attestation_boundary_enabled' => false]);
        $this->assertSame('blocked', $this->inspect($fixture)['state']);
        config(['canovia.private_file_provider_attestation_boundary_enabled' => true]);

        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked', $this->inspect($fixture)['state']);
        config(['filesystems.default' => 's3']);

        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->inspect($fixture)['state']);
        } finally {
            app()->instance('env', $previous);
        }

        Storage::fake('local');
        Storage::disk('local')->put('inbox/retained-r25.png', 'retained');
        $before = DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $fixture[0])->first();
        $this->assertDenied($this->inspect($fixture), true);
        $this->assertEquals($before, DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $fixture[0])->first());
        $this->assertSame('retained', Storage::disk('local')->get('inbox/retained-r25.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }
}
