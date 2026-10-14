<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only, strictly aggregate operational monitoring for private cleanup.
 * No file paths, ciphertexts, disk identifiers, users or row IDs returned.
 * Does not enumerate cloud/local storage or mutate cleanup entries.
 */
final class PrivateFileCleanupHealthService
{
    private const TABLE = 'private_file_cleanup_entries';

    private const KNOWN_QUARANTINE_REASONS = [
        'source_unavailable',
        'file_bytes_changed',
        'invalid_encrypted_path',
        'unsafe_cleanup_entry',
    ];

    /**
     * @return array{
     *   schema:string,health:string,pending:int,pending_due:int,
     *   pending_stale_24h:int,pending_high_retry_5:int,
     *   pending_without_retry_date:int,quarantined:int,unknown_status:int,
     *   quarantined_reasons:array<string,int>
     * }
     */
    public function report(): array
    {
        $emptyReasons = array_fill_keys(self::KNOWN_QUARANTINE_REASONS, 0);
        $emptyReasons['other'] = 0;
        $report = [
            'schema' => 'unavailable',
            'health' => 'unavailable',
            'pending' => 0,
            'pending_due' => 0,
            'pending_stale_24h' => 0,
            'pending_high_retry_5' => 0,
            'pending_without_retry_date' => 0,
            'quarantined' => 0,
            'unknown_status' => 0,
            'quarantined_reasons' => $emptyReasons,
        ];

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $report;
            }
            foreach ([
                'status', 'next_attempt_at', 'attempt_count',
                'created_at', 'last_reason',
            ] as $requiredColumn) {
                if (! Schema::hasColumn(self::TABLE, $requiredColumn)) {
                    return $report;
                }
            }

            $now = now();
            $pending = DB::table(self::TABLE)->where('status', 'pending');
            $report['pending'] = (clone $pending)->count();
            $report['pending_due'] = (clone $pending)
                ->where('next_attempt_at', '<=', $now)->count();
            $report['pending_stale_24h'] = (clone $pending)
                ->where('created_at', '<=', $now->copy()->subDay())->count();
            $report['pending_high_retry_5'] = (clone $pending)
                ->where('attempt_count', '>=', 5)->count();
            $report['pending_without_retry_date'] = (clone $pending)
                ->whereNull('next_attempt_at')->count();

            $quarantined = DB::table(self::TABLE)->where('status', 'quarantined');
            $report['quarantined'] = (clone $quarantined)->count();
            $report['unknown_status'] = DB::table(self::TABLE)
                ->whereNotIn('status', ['pending', 'quarantined'])->count();

            // Intentionally avoid GROUP BY on untrusted stored reason strings:
            // only a fixed allowlist of reason-code keys is ever displayed.
            foreach (self::KNOWN_QUARANTINE_REASONS as $reason) {
                $report['quarantined_reasons'][$reason] = (clone $quarantined)
                    ->where('last_reason', $reason)->count();
            }
            $knownCount = array_sum(array_values($report['quarantined_reasons']));
            $report['quarantined_reasons']['other'] = max(
                0, $report['quarantined'] - $knownCount,
            );

            $report['schema'] = 'ready';
            $report['health'] = (
                $report['pending_stale_24h'] > 0
                || $report['pending_high_retry_5'] > 0
                || $report['pending_without_retry_date'] > 0
                || $report['quarantined'] > 0
                || $report['unknown_status'] > 0
            ) ? 'attention' : 'healthy';
        } catch (Throwable) {
            // A DB outage or partially migrated table must NOT be reported
            // as a healthy empty queue. No SQL errors or paths are surfaced.
            return [
                ...$report,
                'schema' => 'unavailable',
                'health' => 'unavailable',
            ];
        }

        return $report;
    }
}
