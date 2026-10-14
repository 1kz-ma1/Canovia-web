<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * G3-R14: explicitly opt-in, bounded, non-destructive observation of old
 * upload intents. Not an orphan detector, deletion authorization, or reaper.
 *
 * Never emits private paths, ciphertext, digests, IDs, provider errors,
 * account references, or individual row findings. No locks or writes.
 */
final class PrivateFileUploadReconciliationPreviewService
{
    private const LEDGER = 'private_file_upload_intents';

    private const FENCE_TABLE = 'private_file_path_fences';

    private const REFERENCES = [
        ['table' => 'inbox_items', 'column' => 'storage_path'],
        ['table' => 'study_recall_sources', 'column' => 'storage_path'],
        ['table' => 'career_captures', 'column' => 'screenshot_path'],
    ];

    private const CATEGORIES = [
        'reference_and_object_observed',
        'reference_only_observed',
        'object_without_reference_observed',
        'neither_observed',
        'unverifiable',
    ];

    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
        private readonly PrivateFileReconciliationSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string,mixed> Only fixed labels and aggregate counts.
     */
    public function preview(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Invalid private upload preview limit.');
        }

        $report = [
            'schema' => 'not_checked',
            'outcome' => 'blocked',
            'scope' => 'stale_prepared_and_needs_review',
            'limit' => $limit,
            'eligible' => 0,
            'observed' => 0,
            'fence_row_observed' => 0,
            'fence_row_not_observed' => 0,
            'categories' => array_fill_keys(self::CATEGORIES, 0),
            'consistency_probe' => 'disabled',
            'consistency' => [
                'weak_pair_unchanged' => 0,
                'change_observed' => 0,
                'unverifiable' => 0,
                // Flysystem metadata has no immutable generation identity.
                'generation_verified' => 0,
            ],
            'destructive_actions' => 0,
        ];

        // Production is explicitly prohibited, including when env flags are
        // set accidentally. This preview does targeted Storage::exists calls.
        if (app()->environment('production')
            || config('canovia.private_file_upload_reconcile_preview_enabled', false) !== true
            || ! $this->fence->enabled()
            || config('canovia.private_file_upload_journal_enabled', false) !== true) {
            return $report;
        }

        $probeEnabled = config('canovia.private_file_upload_consistency_probe_enabled', false) === true;
        if ($probeEnabled) {
            $report['consistency_probe'] = 'weak_metadata_not_generation_bound';
        }

        try {
            foreach ([
                self::LEDGER => [
                    'id', 'encrypted_path', 'path_digest', 'disk',
                    'status', 'created_at',
                ],
                self::FENCE_TABLE => ['path_digest'],
            ] as $table => $columns) {
                if (! $this->tableReady($table, $columns)) {
                    return $this->unavailable($report);
                }
            }
            foreach (self::REFERENCES as $source) {
                if (! $this->tableReady($source['table'], [$source['column']])) {
                    return $this->unavailable($report);
                }
            }

            $eligible = fn () => DB::table(self::LEDGER)
                ->where(function ($query): void {
                    $query->where('status', 'needs_review')
                        ->orWhere(function ($query): void {
                            $query->where('status', 'prepared')
                                ->where('created_at', '<=', now()->subDay());
                        });
                });

            $report['eligible'] = $eligible()->count();
            $rows = $eligible()->orderBy('id')->limit($limit)
                ->get(['encrypted_path', 'path_digest', 'disk']);

            foreach ($rows as $row) {
                $report['observed']++;
                // Fail closed on corrupt ciphertext, rotation mismatch, bad
                // namespace, unexpected disk, or storage/query exceptions.
                try {
                    $disk = (string) $row->disk;
                    $path = Crypt::decryptString((string) $row->encrypted_path);
                    $digest = $this->fence->fingerprint($disk, $path);
                    if (! hash_equals($digest, (string) $row->path_digest)) {
                        $report['categories']['unverifiable']++;
                        continue;
                    }

                    if ($probeEnabled) {
                        // Two reads can reveal SOME concurrent changes, but
                        // stable size/mtime are not a provider generation ID.
                        // No locks, object bytes, list operations or writes.
                        $first = $this->snapshots->capture($disk, $path, $digest);
                        $second = $this->snapshots->capture($disk, $path, $digest);
                        if (! $this->snapshots->valid($first)
                            || ! $this->snapshots->valid($second)) {
                            $report['consistency']['unverifiable']++;
                            $report['categories']['unverifiable']++;
                            continue;
                        }

                        if ($first !== $second) {
                            $report['consistency']['change_observed']++;
                            $report['categories']['unverifiable']++;
                            continue;
                        }

                        $report['consistency']['weak_pair_unchanged']++;
                        $fenceExists = $second['fence'];
                        $referenced = in_array(true, $second['references'], true);
                        $objectSeen = $second['exists'];
                    } else {
                        // R14 compatibility: single time-of-check, no
                        // additional storage metadata operation.
                        $fenceExists = DB::table(self::FENCE_TABLE)
                            ->where('path_digest', $digest)->exists();
                        $referenced = false;
                        foreach (self::REFERENCES as $source) {
                            if (DB::table($source['table'])
                                ->where($source['column'], $path)->exists()) {
                                $referenced = true;
                            }
                        }
                        $objectSeen = Storage::disk($disk)->exists($path);
                    }

                    $report[$fenceExists
                        ? 'fence_row_observed' : 'fence_row_not_observed']++;

                    $category = match (true) {
                        $referenced && $objectSeen => 'reference_and_object_observed',
                        $referenced => 'reference_only_observed',
                        $objectSeen => 'object_without_reference_observed',
                        default => 'neither_observed',
                    };
                    $report['categories'][$category]++;
                } catch (Throwable) {
                    if ($probeEnabled) {
                        $report['consistency']['unverifiable']++;
                    }
                    $report['categories']['unverifiable']++;
                }
            }

            $report['schema'] = 'ready';
            $report['outcome'] = $report['categories']['unverifiable'] > 0
                ? 'attention' : 'observations_only';
        } catch (Throwable) {
            return $this->unavailable($report);
        }

        return $report;
    }

    /** @param list<string> $columns */
    private function tableReady(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function unavailable(array $report): array
    {
        // Do not return partial results which could imply healthy/missing
        // objects when a query or schema dependency became unavailable.
        return [
            ...$report,
            'schema' => 'unavailable',
            'outcome' => 'unavailable',
            'eligible' => 0,
            'observed' => 0,
            'fence_row_observed' => 0,
            'fence_row_not_observed' => 0,
            'categories' => array_fill_keys(self::CATEGORIES, 0),
            'consistency' => [
                'weak_pair_unchanged' => 0,
                'change_observed' => 0,
                'unverifiable' => 0,
                'generation_verified' => 0,
            ],
        ];
    }
}
