<?php

namespace Tests\Feature;

use App\Services\PrivateFileGenerationContractService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileGenerationContractG3Test extends TestCase
{
    private function contract(): PrivateFileGenerationContractService
    {
        return app(PrivateFileGenerationContractService::class);
    }

    public function test_disabled_config_is_blocked_without_touching_storage(): void
    {
        config(['canovia.private_file_generation_contract_enabled' => false]);
        $report = $this->contract()->report();

        $this->assertSame('blocked', $report['assessment']);
        $this->assertSame('not_inspected', $report['driver']);
        $this->assertFalse($report['network_requests']);
        $this->assertFalse($report['storage_mutations']);
        $this->assertFalse($report['generation_verified']);
        $this->assertFalse($report['delete_authorized']);
    }

    public function test_default_local_disk_is_explicitly_unsupported_for_generation_evidence(): void
    {
        config([
            'canovia.private_file_generation_contract_enabled' => true,
            'filesystems.default' => 'local',
        ]);
        $report = $this->contract()->report();

        $this->assertSame('unsupported_local_no_generation', $report['assessment']);
        $this->assertSame('local', $report['driver']);
        $this->assertSame('not_verified', $report['provider_generation']);
        $this->assertSame('not_implemented', $report['conditional_delete_enforcement']);
        $this->assertFalse($report['delete_authorized']);
    }

    public function test_s3_driver_is_only_configuration_not_provider_verification(): void
    {
        $sentinel = 'sensitive-bucket-name-do-not-print';
        config([
            'canovia.private_file_generation_contract_enabled' => true,
            'filesystems.default' => 's3',
            'filesystems.disks.s3.driver' => 's3',
            'filesystems.disks.s3.bucket' => $sentinel,
            'filesystems.disks.s3.key' => 'should-not-be-printed-access-key',
            'filesystems.disks.s3.secret' => 'should-not-be-printed-secret',
            'filesystems.disks.s3.endpoint' => 'https://private.example.invalid',
        ]);
        $report = $this->contract()->report();
        $output = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertSame('provider_contract_not_integrated', $report['assessment']);
        $this->assertSame('s3_configuration_only', $report['driver']);
        $this->assertSame('not_verified', $report['bucket_versioning']);
        $this->assertSame('not_implemented', $report['write_time_version_binding']);
        $this->assertFalse($report['network_requests']);
        $this->assertFalse($report['delete_authorized']);
        $this->assertStringNotContainsString($sentinel, $output);
        $this->assertStringNotContainsString('should-not-be-printed', $output);
        $this->assertStringNotContainsString('private.example', $output);
    }

    public function test_unknown_driver_is_fail_closed(): void
    {
        config([
            'canovia.private_file_generation_contract_enabled' => true,
            'filesystems.default' => 'future',
            'filesystems.disks.future.driver' => 'unknown-vendor',
        ]);
        $report = $this->contract()->report();
        $this->assertSame('unsupported_or_unknown_driver', $report['assessment']);
        $this->assertSame('other', $report['driver']);
        $this->assertFalse($report['generation_verified']);
    }

    public function test_even_same_non_null_version_ids_are_not_deletion_evidence(): void
    {
        $out = $this->contract()->compareUnboundVersionIds(
            'opaque-version-id-1', 'opaque-version-id-1',
        );
        $this->assertSame('version_match_unbound', $out['comparison']);
        $this->assertFalse($out['generation_verified']);
        $this->assertFalse($out['delete_authorized']);
    }

    public function test_version_mismatch_is_detected_without_exposing_tokens(): void
    {
        $write = 'sensitive-write-version';
        $observed = 'sensitive-observed-version';
        $out = $this->contract()->compareUnboundVersionIds($write, $observed);

        $this->assertSame('version_mismatch', $out['comparison']);
        $this->assertFalse($out['delete_authorized']);
        $this->assertStringNotContainsString($write, json_encode($out));
        $this->assertStringNotContainsString($observed, json_encode($out));
    }

    public function test_null_versions_and_empty_or_oversize_tokens_fail_closed(): void
    {
        $contract = $this->contract();
        foreach ([null, '', 'null', 'NULL', str_repeat('v', 1025), "abc\0def"] as $write) {
            $out = $contract->compareUnboundVersionIds($write, 'valid-version');
            $this->assertSame('write_version_missing_or_invalid', $out['comparison']);
            $this->assertFalse($out['generation_verified']);
        }
        foreach ([null, '', 'null', 'NULL', str_repeat('x', 1025), "x\0y"] as $observed) {
            $out = $contract->compareUnboundVersionIds('valid-version', $observed);
            $this->assertSame('observed_version_missing_or_invalid', $out['comparison']);
            $this->assertFalse($out['delete_authorized']);
        }
    }

    public function test_production_denies_even_with_opt_in_and_s3_configuration(): void
    {
        config([
            'canovia.private_file_generation_contract_enabled' => true,
            'filesystems.default' => 's3',
        ]);
        $previous = app()['env'];
        app()->instance('env', 'production');
        try {
            $report = $this->contract()->report();
        } finally {
            app()->instance('env', $previous);
        }

        $this->assertSame('blocked', $report['assessment']);
        $this->assertSame('not_inspected', $report['driver']);
        $this->assertFalse($report['network_requests']);
        $this->assertFalse($report['delete_authorized']);
    }

    public function test_operator_command_exposes_only_safe_labels_and_remains_read_only(): void
    {
        config([
            'canovia.private_file_generation_contract_enabled' => true,
            'filesystems.default' => 'local',
        ]);
        Storage::fake('local');
        Storage::put('inbox/synthetic/keep.png', 'do-not-delete-by-generation-audit');

        $this->artisan('canovia:private-file-generation-contract', [
            '--json' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('"delete_authorized":false')
            ->doesntExpectOutputToContain('do-not-delete-by-generation-audit')
            ->doesntExpectOutputToContain('keep.png');

        $this->assertSame('do-not-delete-by-generation-audit',
            Storage::get('inbox/synthetic/keep.png'));
    }
}
