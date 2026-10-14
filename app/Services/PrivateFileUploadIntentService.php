<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * An opt-in, write-ahead, encrypted recovery ledger.
 *
 * prepare() MUST commit before any private storage I/O. Intents are never
 * interpreted as proof of an orphan; this service never opens or deletes
 * storage objects and does not expose encrypted paths to an operator.
 */
final class PrivateFileUploadIntentService
{
    private const TABLE = 'private_file_upload_intents';

    public function enabled(): bool
    {
        return config('canovia.private_file_upload_journal_enabled', false) === true;
    }

    /**
     * @param array<string,string> $keys Sorted digest => path from the fence.
     * @return list<int>
     */
    public function prepare(string $disk, array $keys): array
    {
        if (! $this->enabled()
            || config('canovia.private_file_path_fence_enabled', false) !== true) {
            throw new RuntimeException('Private upload journal is disabled.');
        }

        // Committing on the same connection while a parent transaction holds
        // the storage mutation would be impossible; fail BEFORE writing bytes.
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Private upload journal requires an outermost transaction boundary.');
        }

        if ($keys === [] || ! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Private upload journal unavailable.');
        }
        foreach (['path_digest', 'encrypted_path', 'disk', 'status', 'created_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException('Private upload journal unavailable.');
            }
        }

        return DB::transaction(function () use ($disk, $keys): array {
            $ids = [];
            foreach ($keys as $digest => $path) {
                if (! preg_match('/^[0-9a-f]{64}$/D', $digest)
                    || ! is_string($path)) {
                    throw new RuntimeException('Invalid private upload journal key.');
                }

                // A historical digest is NEVER reused. Unique violations
                // abort the entire intent batch before any physical writes.
                $ids[] = (int) DB::table(self::TABLE)->insertGetId([
                    'path_digest' => $digest,
                    'encrypted_path' => Crypt::encryptString($path),
                    'disk' => $disk,
                    'status' => 'prepared',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $ids;
        }, 1);
    }

    /** @param list<int> $ids */
    public function settle(array $ids, string $status): void
    {
        if (! in_array($status, ['committed', 'needs_review'], true) || $ids === []) {
            throw new RuntimeException('Invalid upload journal status.');
        }

        $updated = DB::table(self::TABLE)
            ->whereIn('id', $ids)
            ->where('status', 'prepared')
            ->update(['status' => $status, 'updated_at' => now()]);
        if ($updated !== count($ids)) {
            throw new RuntimeException('Private upload journal settle unavailable.');
        }
    }

    /** Read-only, aggregate status. No path decryption and no storage I/O. */
    public function report(): array
    {
        $result = [
            'schema' => 'unavailable',
            'health' => 'unavailable',
            'prepared' => 0,
            'prepared_stale_24h' => 0,
            'needs_review' => 0,
            'committed' => 0,
            'unknown_status' => 0,
        ];
        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $result;
            }
            foreach (['path_digest', 'encrypted_path', 'disk', 'status', 'created_at', 'updated_at'] as $col) {
                if (! Schema::hasColumn(self::TABLE, $col)) {
                    return $result;
                }
            }

            $pending = DB::table(self::TABLE)->where('status', 'prepared');
            $result['prepared'] = (clone $pending)->count();
            $result['prepared_stale_24h'] = (clone $pending)
                ->where('created_at', '<=', now()->subDay())->count();
            $result['needs_review'] = DB::table(self::TABLE)
                ->where('status', 'needs_review')->count();
            $result['committed'] = DB::table(self::TABLE)
                ->where('status', 'committed')->count();
            $result['unknown_status'] = DB::table(self::TABLE)
                ->whereNotIn('status', ['prepared', 'needs_review', 'committed'])
                ->count();
            $result['schema'] = 'ready';
            $result['health'] = ($result['prepared_stale_24h'] > 0
                || $result['needs_review'] > 0
                || $result['unknown_status'] > 0)
                ? 'attention' : 'healthy';
        } catch (\Throwable) {
            return ['schema' => 'unavailable', 'health' => 'unavailable',
                ...array_diff_key($result, ['schema' => true, 'health' => true])];
        }

        return $result;
    }
}
