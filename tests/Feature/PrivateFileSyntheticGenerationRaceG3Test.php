<?php

namespace Tests\Feature;

use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileSyntheticGenerationRaceService;
use App\Services\PrivateFileSyntheticProviderProbeAdapter;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileWriteAttemptService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileSyntheticGenerationRaceG3Test extends TestCase
{
    use DatabaseTruncation;

    private const BUCKET = 'r22-disposable-bucket';

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
            'canovia.private_file_synthetic_race_matrix_enabled' => true,
            'canovia.private_file_generation_key_epoch' => 'app-key-v1',
        ]);
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function fixture(string $name): array
    {
        $path = 'inbox/synthetic/r22-'.$name.'.png';
        $digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);
        $intent = app(PrivateFileUploadIntentService::class)
            ->prepare('s3', [$digest => $path])[0];
        return [
            $intent,
            app(PrivateFileWriteAttemptService::class)->reserveSynthetic($intent),
            $path,
        ];
    }

    private function claims(
        string $versioning = 'enabled',
        string $writers = 'claimed_exclusive',
        string $conditional = 'claimed_supported',
    ): array {
        return [
            'versioning_mode' => $versioning,
            'writer_coordination' => $writers,
            'conditional_generation' => $conditional,
        ];
    }

    private function sample(
        array $attempt,
        string $path,
        int $index,
        string $outcome = 'present',
        ?string $version = 'vA',
    ): array {
        // Future five-second tolerance is R21 fixture-only. Each observation
        // falls after the reserved attempt and is strictly monotonic.
        return app(PrivateFileSyntheticProviderProbeAdapter::class)->observation(
            $attempt['attempt_id'], $attempt['request_token'],
            self::BUCKET, $path, $outcome, $version, now()->timestamp + $index,
        );
    }

    private function assess(int $intent, array $samples, ?array $claims = null): array
    {
        return app(PrivateFileSyntheticGenerationRaceService::class)
            ->assess($intent, $samples, $claims ?? $this->claims());
    }

    private function assertSafeDenial(array $result): void
    {
        foreach ([
            'provider_attested', 'generation_verified', 'writer_exclusivity_verified',
            'race_free', 'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ] as $field) {
            $this->assertFalse($result[$field], $field);
        }
        $this->assertEqualsCanonicalizing([
            'state', 'provider_attested', 'generation_verified',
            'writer_exclusivity_verified', 'race_free',
            'retry_authorized', 'delete_authorized',
            'network_requests', 'storage_mutations',
        ], array_keys($result));
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::BUCKET, 'inbox/', 'request_token', 'vA', 'vB'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_equal_versions_and_claimed_exclusive_writers_do_not_establish_race_freedom(): void
    {
        [$intent, $attempt, $path] = $this->fixture('equal');
        app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'vA',
        );
        $result = $this->assess($intent, [
            $this->sample($attempt, $path, 1),
            $this->sample($attempt, $path, 2),
        ]);
        $this->assertSame('equal_snapshots_aba_not_excluded', $result['state']);
        $this->assertSafeDenial($result);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }

    public function test_explicit_aba_pattern_is_detected_but_still_untrusted(): void
    {
        [$intent, $attempt, $path] = $this->fixture('aba');
        app(PrivateFileWriteAttemptService::class)->recordSyntheticAck(
            $attempt['attempt_id'], $attempt['request_token'], 'vA',
        );
        $result = $this->assess($intent, [
            $this->sample($attempt, $path, 1, 'present', 'vA'),
            $this->sample($attempt, $path, 2, 'present', 'vB'),
            $this->sample($attempt, $path, 3, 'present', 'vA'),
        ]);
        $this->assertSame('aba_pattern_observed_untrusted', $result['state']);
        $this->assertSafeDenial($result);
    }

    public function test_version_and_presence_transitions_never_authorize_replay(): void
    {
        [$intent, $attempt, $path] = $this->fixture('transition');
        $cases = [
            [
                [$this->sample($attempt, $path, 1, 'present', 'vA'),
                    $this->sample($attempt, $path, 2, 'present', 'vB')],
                'generation_transition_untrusted',
            ],
            [
                [$this->sample($attempt, $path, 1, 'absent', null),
                    $this->sample($attempt, $path, 2, 'present', 'vA')],
                'presence_transition_untrusted',
            ],
            [
                [$this->sample($attempt, $path, 1, 'present', 'vA'),
                    $this->sample($attempt, $path, 2, 'absent', null)],
                'presence_transition_untrusted',
            ],
            [
                [$this->sample($attempt, $path, 1, 'absent', null),
                    $this->sample($attempt, $path, 2, 'absent', null)],
                'absence_not_replay_safe',
            ],
            [
                [$this->sample($attempt, $path, 1, 'present', null),
                    $this->sample($attempt, $path, 2, 'present', 'vA')],
                'generation_unknown',
            ],
        ];
        foreach ($cases as [$samples, $expected]) {
            $result = $this->assess($intent, $samples);
            $this->assertSame($expected, $result['state']);
            $this->assertSafeDenial($result);
        }
    }

    public function test_versioning_suspension_external_writer_and_condition_claims_are_not_proof(): void
    {
        [$intent, $attempt, $path] = $this->fixture('capability');
        $samples = [$this->sample($attempt, $path, 1), $this->sample($attempt, $path, 2)];
        foreach (['suspended', 'disabled', 'unknown'] as $versioning) {
            $result = $this->assess($intent, $samples, $this->claims($versioning));
            $this->assertSame('versioning_unverified_or_unsupported', $result['state']);
            $this->assertSafeDenial($result);
        }
        $external = $this->assess($intent, $samples,
            $this->claims('enabled', 'external_possible'));
        $this->assertSame('external_writer_unexcluded', $external['state']);
        $this->assertSafeDenial($external);
        $conditional = $this->assess($intent, $samples,
            $this->claims('enabled', 'claimed_exclusive', 'unsupported'));
        $this->assertSame('conditional_generation_unavailable', $conditional['state']);
        $this->assertSafeDenial($conditional);
        $unknown = $this->assess($intent, $samples,
            $this->claims('enabled', 'unknown', 'unknown'));
        $this->assertSame('equal_snapshots_aba_not_excluded', $unknown['state']);
        $this->assertSafeDenial($unknown);
    }

    public function test_transport_gaps_and_cross_attempt_forgery_do_not_pass_validation(): void
    {
        [$intent, $attempt, $path] = $this->fixture('transport');
        [$otherIntent, $otherAttempt, $otherPath] = $this->fixture('other');
        foreach (['unknown', 'timeout', 'network_error'] as $outcome) {
            $result = $this->assess($intent, [
                $this->sample($attempt, $path, 1),
                $this->sample($attempt, $path, 2, $outcome, null),
            ]);
            $this->assertSame('observation_gap_unresolved', $result['state']);
            $this->assertSafeDenial($result);
        }
        foreach ([
            [$this->sample($attempt, $path, 1),
                $this->sample($otherAttempt, $otherPath, 2)],
            [$this->sample($attempt, $path, 1),
                array_replace($this->sample($attempt, $path, 2), ['bucket' => 'wrong-bucket'])],
            [$this->sample($attempt, $path, 1),
                array_replace($this->sample($attempt, $path, 2), ['origin' => 'live_sdk'])],
            [$this->sample($attempt, $path, 1),
                array_replace($this->sample($attempt, $path, 2), ['safe_to_delete' => true])],
        ] as $samples) {
            $result = $this->assess($intent, $samples);
            $this->assertSame('observation_binding_unverifiable', $result['state']);
            $this->assertSafeDenial($result);
        }
        $this->assertSame('observation_binding_unverifiable',
            $this->assess($otherIntent, [
                $this->sample($attempt, $path, 1),
                $this->sample($attempt, $path, 2),
            ])['state']);
    }

    public function test_non_monotonic_stale_extra_and_oversized_timelines_are_rejected(): void
    {
        [$intent, $attempt, $path] = $this->fixture('shape');
        $one = $this->sample($attempt, $path, 1);
        $two = $this->sample($attempt, $path, 2);
        foreach ([
            [$one],
            [$one, $one],
            [$two, $one],
            [$one, $two, $one],
            [$one, $two, $this->sample($attempt, $path, 3),
                $this->sample($attempt, $path, 4),
                $this->sample($attempt, $path, 5)],
            [1 => $one, 2 => $two],
        ] as $samples) {
            $result = $this->assess($intent, $samples);
            $this->assertSame('invalid_race_fixture', $result['state']);
            $this->assertSafeDenial($result);
        }
        $badClaims = [
            array_replace($this->claims(), ['provider_attested' => true]),
            array_replace($this->claims(), ['writer_coordination' => true]),
            array_replace($this->claims(), ['versioning_mode' => 'enforced']),
            [],
        ];
        foreach ($badClaims as $claims) {
            $this->assertSame('invalid_race_fixture',
                $this->assess($intent, [$one, $two], $claims)['state']);
        }
        $stale = array_replace($one, ['observed_at' => now()->subMinutes(7)->timestamp]);
        $this->assertSame('observation_binding_unverifiable',
            $this->assess($intent, [$stale, $two])['state']);
    }

    public function test_default_off_local_production_rotation_and_corruption_fail_closed(): void
    {
        [$intent, $attempt, $path] = $this->fixture('security');
        $samples = [$this->sample($attempt, $path, 1), $this->sample($attempt, $path, 2)];
        config(['canovia.private_file_synthetic_race_matrix_enabled' => false]);
        $this->assertSame('blocked', $this->assess($intent, $samples)['state']);
        config(['canovia.private_file_synthetic_race_matrix_enabled' => true]);

        config(['filesystems.default' => 'local']);
        $this->assertSame('blocked', $this->assess($intent, $samples)['state']);
        config(['filesystems.default' => 's3']);

        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $this->assertSame('blocked', $this->assess($intent, $samples)['state']);
        } finally {
            app()->instance('env', $previous);
        }

        config(['canovia.private_file_generation_key_epoch' => 'app-key-v2']);
        $this->assertSame('observation_binding_unverifiable',
            $this->assess($intent, $samples)['state']);
        config(['canovia.private_file_generation_key_epoch' => 'app-key-v1']);

        $oldKey = config('app.key');
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);
        $this->assertSame('observation_binding_unverifiable',
            $this->assess($intent, $samples)['state']);
        config(['app.key' => $oldKey]);

        DB::table('private_file_upload_intents')->where('id', $intent)
            ->update(['encrypted_path' => 'corrupt']);
        $this->assertSame('observation_binding_unverifiable',
            $this->assess($intent, $samples)['state']);
    }

    public function test_read_only_matrix_never_modifies_attempts_or_objects(): void
    {
        [$intent, $attempt, $path] = $this->fixture('readonly');
        Storage::fake('local');
        Storage::disk('local')->put('inbox/synthetic/keep.png', 'untouched');
        $before = DB::table('private_file_write_attempts')
            ->where('id', $attempt['attempt_id'])->first();
        $result = $this->assess($intent, [
            $this->sample($attempt, $path, 1),
            $this->sample($attempt, $path, 2),
        ]);
        $this->assertSafeDenial($result);
        $this->assertEquals($before, DB::table('private_file_write_attempts')
            ->where('id', $attempt['attempt_id'])->first());
        $this->assertSame('untouched',
            Storage::disk('local')->get('inbox/synthetic/keep.png'));
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_generation_evidence', 0);
    }
}
