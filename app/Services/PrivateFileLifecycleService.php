<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transactional private-file deletion outbox.
 *
 * Call deleteAfterCommit INSIDE the same transaction that removes the owning
 * rows. Outbox entries survive process death after commit. No physical file
 * deletion is allowed before the outermost DB commit.
 */
final class PrivateFileLifecycleService
{
    public function __construct(
        private readonly PrivateFilePathFenceService $pathFence,
    ) {}

    private const TABLE = 'private_file_cleanup_entries';

    private const SOURCES = [
        ['table' => 'inbox_items', 'path' => 'storage_path'],
        ['table' => 'study_recall_sources', 'path' => 'storage_path'],
        ['table' => 'career_captures', 'path' => 'screenshot_path'],
    ];

    /** @return Collection<int,string> */
    public function pathsCascadedByPlan(int $planId): Collection
    {
        $paths = collect();
        foreach (self::SOURCES as $source) {
            if ($source['table'] === 'inbox_items' || ! $this->tableHasPath($source)) {
                continue;
            }

            $paths = $paths->merge(
                DB::table($source['table'])
                    ->where('plan_id', $planId)
                    ->whereNotNull($source['path'])
                    ->pluck($source['path']),
            );
        }

        return $this->normalize($paths);
    }

    /**
     * Register the cleanup intent DURABLY, before committing the deletion.
     * Legacy callers outside a transaction still enqueue atomically, but
     * controller deletion routes must call this inside their transaction.
     *
     * @param Collection<int,string> $candidates
     */
    public function deleteAfterCommit(Collection $candidates): void
    {
        $paths = $this->normalize($candidates);
        if ($paths->isEmpty()) {
            return;
        }

        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->deleteAfterCommit($paths), 3);
            return;
        }

        $ids = [];
        $disk = (string) config('filesystems.default', 'local');
        foreach ($paths as $path) {
            if (! $this->safePath($path)) {
                $this->warn('invalid_private_path');
                continue;
            }

            try {
                if (! Storage::disk($disk)->exists($path)) {
                    continue; // Absent at registration: never target a future reused path.
                }
                $digest = hash('sha256', Storage::disk($disk)->get($path));
                $status = 'pending';
                $reason = null;
            } catch (Throwable) {
                // Do not auto-delete if the exact bytes could not be sealed.
                $digest = null;
                $status = 'quarantined';
                $reason = 'source_unavailable';
                $this->warn('storage_unavailable');
            }

            $ids[] = (int) DB::table(self::TABLE)->insertGetId([
                'encrypted_path' => Crypt::encryptString($path),
                'disk' => $disk,
                'expected_sha256' => $digest,
                'status' => $status,
                'attempt_count' => 0,
                'last_reason' => $reason,
                'next_attempt_at' => $status === 'pending' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($ids !== []) {
            // Laravel drops callback on any outer rollback. Catch callback
            // errors, because the parent DB delete is already committed.
            DB::afterCommit(function () use ($ids): void {
                try {
                    $this->processIds($ids);
                } catch (Throwable) {
                    $this->warn('worker_unavailable');
                }
            });
        }
    }

    /**
     * Operator-invoked bounded retry. No paths, user IDs or material leave
     * this service. It does not scan storage or infer deleted DB owners.
     *
     * @return array{selected:int,deleted:int,retained:int,retry:int,quarantined:int}
     */
    public function retryDue(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $ids = DB::table(self::TABLE)
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->processIds($ids);
    }

    /** @return array{pending:int,quarantined:int} */
    public function statusCounts(): array
    {
        return [
            'pending' => DB::table(self::TABLE)->where('status', 'pending')->count(),
            'quarantined' => DB::table(self::TABLE)->where('status', 'quarantined')->count(),
        ];
    }

    /** @param list<int> $ids
     * @return array{selected:int,deleted:int,retained:int,retry:int,quarantined:int}
     */
    private function processIds(array $ids): array
    {
        $totals = ['selected' => count($ids), 'deleted' => 0, 'retained' => 0,
            'retry' => 0, 'quarantined' => 0];
        foreach ($ids as $id) {
            try {
                $result = DB::transaction(fn () => $this->processOne($id), 3);
            } catch (Throwable) {
                // The row stays pending for a future bounded retry.
                $result = 'retry';
                $this->warn('worker_unavailable');
            }
            if (array_key_exists($result, $totals)) {
                $totals[$result]++;
            }
        }

        return $totals;
    }

    private function processOne(int $id): string
    {
        $row = DB::table(self::TABLE)->where('id', $id)
            ->lockForUpdate()->first();
        if ($row === null || $row->status !== 'pending'
            || ($row->next_attempt_at !== null
                && \Carbon\Carbon::parse($row->next_attempt_at)->isFuture())) {
            return 'retained';
        }

        try {
            $path = Crypt::decryptString($row->encrypted_path);
        } catch (Throwable) {
            $this->quarantine($id, 'invalid_encrypted_path');
            return 'quarantined';
        }
        if (! $this->safePath($path)
            || ! is_string($row->expected_sha256)
            || ! preg_match('/^[a-f0-9]{64}$/', $row->expected_sha256)) {
            $this->quarantine($id, 'unsafe_cleanup_entry');
            return 'quarantined';
        }

        // An outbox entry was sealed against the default private disk at
        // enqueue time. A later disk switch must never redirect deletion
        // to a different adapter, bucket or tenant namespace.
        if (! is_string($row->disk)
            || $row->disk !== (string) config('filesystems.default', 'local')) {
            $this->quarantine($id, 'storage_disk_mismatch');
            return 'quarantined';
        }

        // The flag is OFF until every native writer shares this lock.
        // After the outbox row lock, hold the path row lock through final
        // reference verification and physical unlink, never outside a
        // transaction. A missing fence table stops deletion safely.
        if ($this->pathFence->enabled()) {
            try {
                $this->pathFence->acquireInTransaction((string) $row->disk, $path);
            } catch (Throwable) {
                $this->retryLater($id, (int) $row->attempt_count, 'path_fence_unavailable');
                return 'retry';
            }
        }

        // A still-owned/linked file may NEVER be removed by this outbox.
        // Missing reference tables/columns or failed reference queries must
        // NOT be interpreted as an absence of live owners.
        try {
            if ($this->isReferenced($path)) {
                DB::table(self::TABLE)->where('id', $id)->delete();
                return 'retained';
            }
        } catch (Throwable) {
            $this->retryLater($id, (int) $row->attempt_count, 'reference_check_unavailable');
            return 'retry';
        }

        try {
            $storage = Storage::disk((string) $row->disk);
            if (! $storage->exists($path)) {
                DB::table(self::TABLE)->where('id', $id)->delete();
                return 'deleted';
            }
            // Protect against path reuse / external replacement. A different
            // byte version is quarantined, never retried destructively.
            if (! hash_equals($row->expected_sha256,
                hash('sha256', $storage->get($path)))) {
                $this->quarantine($id, 'file_bytes_changed');
                return 'quarantined';
            }

            // A live reference may have appeared while the file was read
            // for SHA verification. Check all reference sources again as
            // close as possible to unlink, failing closed if DB reads fail.
            // This narrows the window; it cannot coordinate external
            // writers that create references after this final check.
            try {
                if ($this->isReferenced($path)) {
                    DB::table(self::TABLE)->where('id', $id)->delete();
                    return 'retained';
                }
            } catch (Throwable) {
                $this->retryLater($id, (int) $row->attempt_count, 'reference_check_unavailable');
                return 'retry';
            }

            if (! $storage->delete($path)) {
                $this->retryLater($id, (int) $row->attempt_count, 'storage_delete_false');
                return 'retry';
            }
            DB::table(self::TABLE)->where('id', $id)->delete();
            return 'deleted';
        } catch (Throwable) {
            $this->retryLater($id, (int) $row->attempt_count, 'storage_unavailable');
            return 'retry';
        }
    }

    public function isReferenced(string $path): bool
    {
        foreach (self::SOURCES as $source) {
            if (! $this->tableHasPath($source)) {
                throw new \RuntimeException('Private file reference schema unavailable');
            }
            $query = DB::table($source['table'])
                ->where($source['path'], $path);

            // MySQL/MariaDB's default REPEATABLE READ can reuse a stale
            // snapshot for exists(). A locking SELECT is a CURRENT read;
            // first(['id']) deliberately preserves FOR UPDATE in SQL
            // (an exists() optimization need not include that clause).
            // This cannot serialize writers that do not share a path fence,
            // and broad gap/next-key locks require separate indexing review.
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                if ($query->lockForUpdate()->first(['id']) !== null) {
                    return true;
                }
            } elseif ($query->exists()) {
                return true;
            }
        }
        return false;
    }

    private function retryLater(int $id, int $attemptCount, string $reason): void
    {
        // Exponential minutes capped at 24h. Only the CLI/opted-in scheduler
        // runs due entries; no implicit production retry cron is enabled.
        $minutes = min(1440, 2 ** min(10, $attemptCount));
        DB::table(self::TABLE)->where('id', $id)->update([
            'attempt_count' => $attemptCount + 1,
            'last_reason' => $reason,
            'next_attempt_at' => now()->addMinutes($minutes),
            'updated_at' => now(),
        ]);
        $this->warn($reason);
    }

    private function quarantine(int $id, string $reason): void
    {
        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'quarantined',
            'last_reason' => $reason,
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
        $this->warn($reason);
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\')
            || str_contains($path, "\0")) {
            return false;
        }
        $segments = explode('/', $path);
        return count($segments) > 1
            && in_array($segments[0], [
                'inbox', 'study-recall-sources', 'career-captures',
            ], true)
            && ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true);
    }

    private function warn(string $reason): void
    {
        Log::warning('Private file cleanup failed after DB commit', [
            'reason' => $reason,
        ]);
    }

    /** @param array{table:string,path:string} $source */
    private function tableHasPath(array $source): bool
    {
        return Schema::hasTable($source['table'])
            && Schema::hasColumn($source['table'], $source['path']);
    }

    /** @param Collection<int,mixed> $candidates
     * @return Collection<int,string>
     */
    private function normalize(Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->map(fn ($path) => (string) $path)
            ->unique()->values();
    }
}
