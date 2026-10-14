<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One non-atomic, metadata-only sample for a private upload-intent key.
 *
 * Two identical samples are NOT an immutable object-generation proof:
 * same-size/mtime replacements, external writes and ABA remain possible.
 * This class is intentionally replaceable for deterministic race tests.
 */
class PrivateFileReconciliationSnapshotService
{
    private const REFERENCES = [
        ['table' => 'inbox_items', 'column' => 'storage_path'],
        ['table' => 'study_recall_sources', 'column' => 'storage_path'],
        ['table' => 'career_captures', 'column' => 'screenshot_path'],
    ];

    /**
     * @return array{
     *   fence:bool, references:list<bool>, exists:bool,
     *   size:int|null, last_modified:int|null
     * }
     */
    public function capture(string $disk, string $path, string $digest): array
    {
        // All SELECTs are separate committed reads. Never start a snapshot
        // transaction, insert a fence row, lock a reference or list storage.
        $fence = DB::table('private_file_path_fences')
            ->where('path_digest', $digest)->exists();

        $references = [];
        foreach (self::REFERENCES as $source) {
            $references[] = DB::table($source['table'])
                ->where($source['column'], $path)->exists();
        }

        $storage = Storage::disk($disk);
        $exists = $storage->exists($path);

        return [
            'fence' => $fence,
            'references' => $references,
            'exists' => $exists,
            // Flysystem size/mtime are weak metadata, NOT ETag, generation
            // or conditional-delete tokens. Inability to read these fields
            // must fail as unverifiable, never be treated as "no object".
            'size' => $exists ? (int) $storage->size($path) : null,
            'last_modified' => $exists ? (int) $storage->lastModified($path) : null,
        ];
    }

    /**
     * Require an exact complete shape before interpreting a sampled pair.
     * Never allow missing/fabricated metadata to look stable.
     */
    public function valid(array $sample): bool
    {
        if (! isset($sample['fence'], $sample['references'], $sample['exists'])
            || ! is_bool($sample['fence'])
            || ! is_bool($sample['exists'])
            || ! is_array($sample['references'])
            || count($sample['references']) !== count(self::REFERENCES)) {
            return false;
        }
        foreach ($sample['references'] as $reference) {
            if (! is_bool($reference)) {
                return false;
            }
        }
        if ($sample['exists']) {
            return isset($sample['size'], $sample['last_modified'])
                && is_int($sample['size'])
                && $sample['size'] >= 0
                && is_int($sample['last_modified'])
                && $sample['last_modified'] >= 0;
        }

        return array_key_exists('size', $sample)
            && array_key_exists('last_modified', $sample)
            && $sample['size'] === null
            && $sample['last_modified'] === null;
    }
}
