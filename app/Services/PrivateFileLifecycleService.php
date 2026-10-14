<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bounded physical cleanup after DB-owned row deletion.
 *
 * Never assume a path owned by a deleted Plan/account is unreferenced: the
 * same private storage key may still appear on another Inbox, Recall or
 * Career row. Collect candidate paths BEFORE deleting rows; delete them
 * only AFTER commit and after checking all surviving references.
 *
 * Uncoordinated external storage writes and crash-safe cleanup/retry need
 * their own durable protocol and are not solved by this best-effort helper.
 */
final class PrivateFileLifecycleService
{
    private const SOURCES = [
        ['table' => 'inbox_items', 'path' => 'storage_path'],
        ['table' => 'study_recall_sources', 'path' => 'storage_path'],
        ['table' => 'career_captures', 'path' => 'screenshot_path'],
    ];

    /**
     * Only the Recall and Career files are cascaded on Plan deletion.
     * Inbox rows have nullOnDelete(plan_id): they are still independently
     * owned and must keep their private attachment bytes intact.
     *
     * Caller holds the Plan lock and invokes this inside the same transaction
     * as Plan deletion.
     *
     * @return Collection<int,string>
     */
    public function pathsCascadedByPlan(int $planId): Collection
    {
        $paths = collect();
        foreach (self::SOURCES as $source) {
            if ($source['table'] === 'inbox_items'
                || ! $this->tableHasPath($source)) {
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
     * @param Collection<int,string> $candidates
     */
    public function deleteAfterCommit(Collection $candidates): void
    {
        $paths = $this->normalize($candidates);
        if (DB::transactionLevel() > 0) {
            // Nested outer transaction: no file unlink until the actual
            // outermost commit. Laravel discards callbacks on rollback.
            DB::afterCommit(fn () => $this->deleteAfterCommit($paths));

            return;
        }

        foreach ($paths as $path) {
            try {
                if ($this->isReferenced($path)) {
                    continue;
                }

                // Deleting an absent path is an idempotent success.
                if (Storage::exists($path) && ! Storage::delete($path)) {
                    Log::warning('Private file cleanup failed after DB commit', [
                        'reason' => 'storage_delete_false',
                    ]);
                }
            } catch (Throwable) {
                // No path or private document content is emitted into logs.
                // The DB change is already committed and cannot be rolled back.
                Log::warning('Private file cleanup failed after DB commit', [
                    'reason' => 'storage_unavailable',
                ]);
            }
        }
    }

    public function isReferenced(string $path): bool
    {
        foreach (self::SOURCES as $source) {
            if ($this->tableHasPath($source)
                && DB::table($source['table'])
                    ->where($source['path'], $path)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param array{table:string,path:string} $source */
    private function tableHasPath(array $source): bool
    {
        return Schema::hasTable($source['table'])
            && Schema::hasColumn($source['table'], $source['path']);
    }

    /** @param Collection<int,mixed> $candidates
     *  @return Collection<int,string>
     */
    private function normalize(Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn ($path) => is_string($path) && trim($path) !== '')
            ->map(fn ($path) => (string) $path)
            ->unique()->values();
    }
}
