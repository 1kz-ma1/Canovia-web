<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Local GitHub App connection generation. Installation ID alone is not a
 * reconnect epoch: the same installation can be reauthorized later.
 */
final class GitHubSourceEpochService
{
    public function current(array $metadata): ?string
    {
        $connection = data_get($metadata, 'github_app_connection', []);
        if (($connection['status'] ?? null) !== 'connected'
            || (int) ($connection['installation_id'] ?? 0) <= 0) {
            return null;
        }

        $epoch = (string) ($connection['source_epoch'] ?? '');

        return Str::isUuid($epoch) ? $epoch : null;
    }

    /** Apply only to an authoritative connection verification, under root lock. */
    public function verified(array $oldMetadata, array $verified): array
    {
        if (($verified['status'] ?? null) !== 'connected'
            || (int) ($verified['installation_id'] ?? 0) <= 0) {
            unset($verified['source_epoch']);

            return $verified;
        }

        $priorId = (int) data_get($oldMetadata, 'github_app_connection.installation_id', 0);
        $sameContinuousConnection =
            data_get($oldMetadata, 'github_app_connection.status') === 'connected'
            && $priorId === (int) $verified['installation_id'];

        $verified['source_epoch'] = $sameContinuousConnection
            ? ($this->current($oldMetadata) ?? (string) Str::uuid())
            : (string) Str::uuid();

        return $verified;
    }
}
