<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Opt-in path-scoped DB row fence. Every participating writer and the cleanup
 * worker must lock the SAME row before touching a private storage path.
 *
 * This is only a foundation: non-participating writers remain unprotected.
 * Locks are released at the end of the enclosing DB transaction; callers
 * must keep storage operations AND reference mutations inside that boundary.
 */
final class PrivateFilePathFenceService
{
    private const TABLE = 'private_file_path_fences';

    public function enabled(): bool
    {
        return config('canovia.private_file_path_fence_enabled', false) === true;
    }

    /**
     * @return string 64-character, keyed digest; not a reversible storage path.
     */
    public function fingerprint(string $disk, string $path): string
    {
        $segments = explode('/', $path);
        if ($disk === '' || strlen($disk) > 64
            || $disk !== (string) config('filesystems.default', 'local')
            || strlen($path) > 1024 || count($segments) < 2
            || ! in_array($segments[0], [
                'inbox', 'study-recall-sources', 'career-captures',
            ], true)
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid private file fence key.');
        }

        $secret = (string) config('app.key', '');
        if ($secret === '') {
            throw new RuntimeException('Private file fence key unavailable.');
        }

        return hash_hmac('sha256', "canovia-private-file-fence-v1\0".$disk."\0".$path, $secret);
    }

    public function acquireInTransaction(string $disk, string $path): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Private file path fence is disabled.');
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Private file path fence requires a transaction.');
        }

        $digest = $this->fingerprint($disk, $path);
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Private file path fence schema unavailable.');
        }

        // insert-or-ignore serializes initial creation against an independent
        // session with the same unique digest on MySQL/InnoDB and PostgreSQL.
        // Every later operation locks the same persistent row, including
        // tombstones, so removing rows while the feature is active is forbidden.
        DB::table(self::TABLE)->insertOrIgnore([
            'path_digest' => $digest,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table(self::TABLE)
            ->where('path_digest', $digest)
            ->lockForUpdate()
            ->first(['id']);

        if ($row === null) {
            throw new RuntimeException('Private file path fence unavailable.');
        }
    }
}
