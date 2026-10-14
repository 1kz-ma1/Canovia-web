<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * R36: identity-only local-vs-external rollback boundary.
 * Never treats checksums, ETags or an application lock as object-generation
 * proof. This service does not probe or delete an external provider.
 */
final class PrivateFileStorageBoundaryService
{
    public function isLocalDefaultDisk(): bool
    {
        $disk = config('filesystems.default');
        if (! is_string($disk) || $disk === '') {
            return false;
        }

        $config = config('filesystems.disks.'.$disk);

        return is_array($config) && ($config['driver'] ?? null) === 'local';
    }

    public function requireLocalLegacyWriter(): void
    {
        if (! $this->isLocalDefaultDisk()) {
            throw new RuntimeException('Private legacy file write requires local storage.');
        }
    }

    /** @param string|list<string> $paths */
    public function deleteRollbackForLocal(string|array $paths): void
    {
        if (! $this->isLocalDefaultDisk()) {
            // Deliberately no path, user ID, disk name or exception content.
            Log::warning('Private rollback deletion held', [
                'reason' => 'provider_generation_delete_unverified',
            ]);

            return;
        }

        Storage::delete($paths);
    }
}
