<?php

namespace App\Services;

/**
 * G3-R16: purely local provider-generation contract inventory.
 *
 * This class NEVER contacts storage, reads a private object, reads a
 * credential or secret, starts a transaction, or authorizes deletion.
 *
 * The comparison helper is a design-time contract for synthetic evidence;
 * it is NOT proof that a token was captured at the authenticated write.
 */
final class PrivateFileGenerationContractService
{
    /**
     * Fixed-label capability inventory. No tenant/bucket/path/endpoint/ID
     * is returned, and no network or storage API is invoked.
     *
     * @return array<string, string|bool>
     */
    public function report(): array
    {
        $result = [
            'assessment' => 'blocked',
            'driver' => 'not_inspected',
            'provider_generation' => 'not_verified',
            'write_time_version_binding' => 'not_implemented',
            'conditional_delete_enforcement' => 'not_implemented',
            'bucket_versioning' => 'not_verified',
            'all_writers_coordinated' => false,
            'generation_verified' => false,
            'delete_authorized' => false,
            'network_requests' => false,
            'storage_mutations' => false,
        ];

        if (app()->environment('production')
            || config('canovia.private_file_generation_contract_enabled', false) !== true) {
            return $result;
        }

        // Inspect only the selected driver, never the bucket name, endpoint,
        // access keys, credentials, or storage adapter's live state.
        $disk = (string) config('filesystems.default', 'local');
        $driver = (string) config('filesystems.disks.'.$disk.'.driver', '');

        if ($driver === 'local') {
            $result['assessment'] = 'unsupported_local_no_generation';
            $result['driver'] = 'local';
        } elseif ($driver === 's3') {
            $result['assessment'] = 'provider_contract_not_integrated';
            $result['driver'] = 's3_configuration_only';
        } else {
            $result['assessment'] = 'unsupported_or_unknown_driver';
            $result['driver'] = 'other';
        }

        return $result;
    }

    /**
     * Compare synthetic S3 VersionIds WITHOUT conferring any permission.
     *
     * Real proof would require a durable, authenticated write-time version
     * bound to the disk/bucket/path, a verified versioning-enabled provider,
     * coordinated writers, and an audited conditional generation operation.
     * None of those preconditions is established by two input strings.
     *
     * ETag is deliberately excluded: equal ETag is NOT equal VersionId.
     *
     * @return array{comparison:string,generation_verified:bool,delete_authorized:bool}
     */
    public function compareUnboundVersionIds(
        ?string $writeVersion,
        ?string $observedVersion,
    ): array {
        $status = 'write_version_missing_or_invalid';

        if ($this->validVersion($writeVersion)) {
            if (! $this->validVersion($observedVersion)) {
                $status = 'observed_version_missing_or_invalid';
            } elseif (! hash_equals($writeVersion, $observedVersion)) {
                $status = 'version_mismatch';
            } else {
                // Even an equal opaque VersionId is NOT bound to a validated
                // original write, bucket, object, or isolation guarantee.
                $status = 'version_match_unbound';
            }
        }

        return [
            'comparison' => $status,
            'generation_verified' => false,
            'delete_authorized' => false,
        ];
    }

    private function validVersion(?string $version): bool
    {
        return is_string($version)
            && $version !== ''
            && strlen($version) <= 1024
            // Unversioned/suspended S3 may reuse the null version, so
            // "null" must never count as an immutable generation.
            && strtolower($version) !== 'null'
            && ! str_contains($version, "\0");
    }
}
