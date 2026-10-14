<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Physical files are not transactional. Only delete a private file after
 * its owning row is committed gone, and never while another current record
 * still points at the exact same storage path.
 */
final class PrivateFileCleanupGuard
{
    private const REFERENCES = [
        ['inbox_items', 'storage_path'],
        ['study_recall_sources', 'storage_path'],
        ['career_captures', 'screenshot_path'],
    ];

    public function deleteIfUnreferenced(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        foreach (self::REFERENCES as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            if (DB::table($table)->where($column, $path)->exists()) {
                return false;
            }
        }

        try {
            // Missing file is already clean; Storage::delete returns false
            // for some backends, which should not reanimate deleted DB rows.
            return ! Storage::exists($path) || Storage::delete($path);
        } catch (Throwable) {
            // Best-effort cleanup; the caller must not lose its committed
            // database deletion because of storage unavailability.
            return false;
        }
    }
}
