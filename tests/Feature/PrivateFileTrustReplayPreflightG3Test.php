<?php

namespace Tests\Feature;

use App\Contracts\PrivateFileIndependentProviderVerifier;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileProviderEvidenceAdmissionService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileTrustReplayPreflightService;
use App\Services\PrivateFileUnavailableProviderVerifier;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileTrustReplayPreflightG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r26-disposable-synthetic-bucket';

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
            'canovia.private_file_trust_replay_preflight_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    private function fixture(string $name): array
    {
        $path = 'inbox/synthetic/r26-'.$name.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        $attempt = app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent);
        app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'generation-r26-A',
        );

        $receipt = [
            'origin' => 'synthetic_put_reply_v1',
            'operation' => 'PutObject',
            'status_code' => 200,
            'attempt_id' => $attempt['attempt_id'],
            'request_token' => $attempt['request_token'],
            'disk' => 's3',
            'bucket' => self::BUCKET,
            'key' => $path,
            'version_id' => 'generation-r26-A',
            'request_id' => 'synthetic-r26-request',
            'received_at' => now()->timestamp,
        ];
        $observation = app(PrivateFileSyntheticProviderProbeAdapter::class)
            ->observation($attempt['attempt_id'], $attempt['request_token'],
                self::BUCKET, $path, 'present', 'generation-r26-A');
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
            'version_id' => 'generation-r26-A',
            'request_id' => 'synthetic-r26-request',
            'issued_at' => now()->timestamp,
        ];
        $candidate = [
            'origin' => 'synthetic_verifier_candidate_v1',
            'provider' => 's3_claimed',
            'attempt_id' => $attempt['attempt_id'],
            'request_id' => 'synthetic-r26-request',
            'version_id' => 'generation-r26-A',
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
        $envelope = [
            'origin' => 'synthetic_trust_replay_envelope_v1',
            'issuer_claim' => 's3_claimed',
            'trust_root_claim' => 'claimed-root-01',
            'root_epoch' => 'synthetic-epoch-a',
            'attempt_id' => $attempt['attempt_id'],
            'request_id' => 'synthetic-r26-request',
            'version_id' => 'generation-r26-A',
            'nonce' => 'synthetic-nonce-new',
            'sequence' => 2,
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addSeconds(30)->timestamp,
        ];
        $prior = [
            'origin' => 'synthetic_prior_untrusted_v1',
            'issuer_claim' => 's3_claimed',
            'trust_root_claim' => 'claimed-root-01',
            'root_epoch' => 'synthetic-epoch-a',
            'attempt_id' => $attempt['attempt_id'],
            'nonce' => 'synthetic-nonce-old',
            'sequence' => 1,
            'issued_at' => now()->subSecond()->timestamp,
        ];

        return [$intent, $receipt, $observation, $claims,
            $assertion, $candidate, $envelope, $prior];
    }

    private function assess(array $arguments): array
    {
        return app(PrivateFileTrustReplayPreflightService::class)->inspect(...$arguments);
    }

    private function assertDenied(array $result, bool $local = false): void
    {
        $this->assertSame($local, $result['local_binding_consistent']);
        foreach ([
            'trusted_root_verified', 'replay_protection_verified',
            'independent_verifier_available', 'provider_authenticated',
            'generation_write_bound', 'retry_authorized',
            'delete_authorized', 'network_requests', 'storage_mutations',
        ] as $field) {
            $this->assertFalse($result[$field], $field);
        }
        $this->assertEqualsCanonicalizing([
            'state', 'local_binding_consistent', 'trusted_root_verified',
            'replay_protection_verified', 'independent_verifier_available',
            'provider_authenticated', 'generation_write_bound',
            'retry_authorized', 'delete_authorized', 'network_requests',
            'storage_mutations',
        ], array_keys($result));
        $out = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'generation-r26-A',
            'synthetic-r26-request', 'claimed-root-01', 'synthetic-nonce'] as $value) {
            $this->assertStringNotContainsString($value, $out);
        }
    }

    public function test_matching_root_claims_and_ordered_nonces_never_become_real_replay_proof(): void
    {
        $result = $this->assess($this->fixture('matching'));
        $this->assertSame('untrusted_history_not_replay_proof', $result['state']);
        $this->assertDenied($result, true);
    }

    public function test_absent_history_denies_even_valid_and_fresh_candidate(): void
    {
        $args = $this->fixture('absent');
        $args[7] = null;
        $result = $this->assess($args);
        $this->assertSame('replay_history_unavailable', $result['state']);
        $this->assertDenied($result);
    }

    public function test_duplicate_nonce_or_decreasing_sequence_is_rejected(): void
    {
        $args = $this->fixture('replay');
        $args[7]['nonce'] = $args[6]['nonce'];
        $result = $this->assess($args);
        $this->assertSame('duplicate_nonce_observed_untrusted', $result['state']);
        $this->assertDenied($result);

        $args = $this->fixture('order');
        $args[7]['sequence'] = 2;
        $this->assertSame('out_of_order_fixture', $this->assess($args)['state']);

        $args = $this->fixture('timestamp');
        $args[7]['issued_at'] = $args[6]['issued_at'];
        $this->assertSame('out_of_order_fixture', $this->assess($args)['state']);
    }

    public function test_changed_root_epoch_or_wrong_attempt_denies_and_never_authenticates(): void
    {
        $args = $this->fixture('epoch');
        foreach ([
            ['root_epoch' => 'synthetic-epoch-b'],
            ['trust_root_claim' => 'claimed-root-02'],
            ['issuer_claim' => 'other'],
            ['attempt_id' => 999999],
        ] as $change) {
            $candidate = $args;
            $candidate[7] = array_replace($args[7], $change);
            $result = $this->assess($candidate);
            $this->assertSame('untrusted_root_or_history_changed', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_expired_too_long_future_and_invalid_time_bounded_envelopes_fail(): void
    {
        $args = $this->fixture('expiry');
        foreach ([
            ['issued_at' => now()->subMinutes(7)->timestamp,
                'expires_at' => now()->subMinutes(6)->timestamp],
            ['expires_at' => now()->subSecond()->timestamp],
            ['issued_at' => now()->addMinute()->timestamp,
                'expires_at' => now()->addMinutes(2)->timestamp],
            ['expires_at' => now()->addMinutes(2)->timestamp],
            ['expires_at' => $args[6]['issued_at']],
        ] as $change) {
            $candidate = $args;
            $candidate[6] = array_replace($args[6], $change);
            $result = $this->assess($candidate);
            $this->assertSame('untrusted_envelope_expired_or_future', $result['state']);
            $this->assertDenied($result);
        }
    }

    public function test_spoofed_authority_and_malformed_fixture_have_no_success_path(): void
    {
        $args = $this->fixture('spoof');
        foreach ([
            ['provider_authenticated' => true],
            ['delete_authorized' => true],
            ['real_provider_signature' => 'forged'],
            ['origin' => 'aws_verified'],
            ['issuer_claim' => 'verified_s3'],
            ['trust_root_claim' => '../../secrets'],
            ['root_epoch' => ''],
            ['nonce' => "bad\nnonce"],
            ['sequence' => '2'],
            ['issued_at' => 'now'],
        ] as $change) {
            $candidate = $args;
            $candidate[6] = array_replace($args[6], $change);
            $result = $this->assess($candidate);
            $this->assertSame('invalid_trust_envelope', $result['state']);
            $this->assertDenied($result);
        }

        $candidate = $args;
        $candidate[6]['version_id'] = 'generation-r26-B';
        $this->assertSame('trust_envelope_scope_unverifiable',
            $this->assess($candidate)['state']);
    }

    public function test_substituting_verifier_or_admission_binding_is_detected_before_resolution(): void
    {
        $args = $this->fixture('substitution');
        app()->bind(PrivateFileIndependentProviderVerifier::class,
            fn () => new PrivateFileUnavailableProviderVerifier);
        $result = $this->assess($args);
        $this->assertSame('verifier_override_detected', $result['state']);
        $this->assertDenied($result);
        app()->forgetInstance(PrivateFileIndependentProviderVerifier::class);
        app()->offsetUnset(PrivateFileIndependentProviderVerifier::class);

        app()->bind(PrivateFileUnavailableProviderVerifier::class,
            fn () => new PrivateFileUnavailableProviderVerifier);
        $this->assertSame('verifier_override_detected', $this->assess($args)['state']);
        app()->offsetUnset(PrivateFileUnavailableProviderVerifier::class);

        app()->bind(PrivateFileProviderEvidenceAdmissionService::class,
            fn () => new \stdClass());
        $this->assertSame('verifier_override_detected', $this->assess($args)['state']);
    }

    public function test_parent_failure_and_no_io_or_database_mutations_are_enforced(): void
    {
        $args = $this->fixture('readonly');
        Storage::fake('local');
        Storage::disk('local')->put('inbox/keep-r26.txt', 'kept');
        $before = DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $args[0])->first();
        $this->assertDenied($this->assess($args), true);
        $this->assertEquals($before, DB::table('private_file_write_attempts')
            ->where('upload_intent_id', $args[0])->first());
        $this->assertSame('kept', Storage::disk('local')->get('inbox/keep-r26.txt'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);

        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $result = $this->assess($args);
        $this->assertSame('prior_local_chain_unverifiable', $result['state']);
        $this->assertDenied($result);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        DB::table('private_file_upload_intents')->where('id', $args[0])
            ->update(['encrypted_path' => 'corrupted']);
        $this->assertSame('prior_local_chain_unverifiable', $this->assess($args)['state']);
    }

    public function test_default_off_production_local_and_parent_off_guards(): void
    {
        $args = $this->fixture('guards');
        config(['canovia.private_file_trust_replay_preflight_enabled' => false]);
        $this->assertSame('blocked', $this->assess($args)['state']);
        config(['canovia.private_file_trust_replay_preflight_enabled' => true]);

        config(['canovia.private_file_provider_evidence_admission_enabled' => false]);
        $this->assertSame('blocked', $this->assess($args)['state']);
        config(['canovia.private_file_provider_evidence_admission_enabled' => true]);

        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked', $this->assess($args)['state']);
        config(['filesystems.default' => 's3']);

        $original = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->assess($args)['state']);
        } finally {
            app()->instance('env', $original);
        }
    }
}
