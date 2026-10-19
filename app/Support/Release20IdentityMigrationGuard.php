<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Read-only preflight for the pending identity migration, not an audit waiver. */
final class Release20IdentityMigrationGuard
{
    public static function assertCompatible(): void
    {
        $table = 'mcp_linked_subjects';
        $expected = [
            'mcp_linked_subjects_identity_fingerprint_unique' => ['identity_fingerprint', true],
            'mcp_active_subject_identity_unique' => ['active_identity_fingerprint', true],
            'mcp_subject_identity_history_idx' => ['identity_fingerprint', false],
        ];
        $indexes = Schema::getIndexes($table);
        $present = [];
        foreach ($expected as $name => [$column, $unique]) {
            $named = array_values(array_filter($indexes, fn ($index) => $index['name'] === $name));
            if ($named === [] && in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)
                && array_filter($indexes, fn ($index) => strcasecmp($index['name'], $name) === 0)) {
                self::block(); // Case variants collide in these namespaces.
            }
            $sameColumns = array_values(array_filter($indexes, fn ($index) => $index['columns'] === [$column]
                && (bool) $index['unique'] === $unique));
            if (count($named) > 1 || count($sameColumns) > 1
                || ($sameColumns !== [] && ($named === [] || $sameColumns[0]['name'] !== $name))) {
                self::block(); // Alias/duplicate: never choose a DROP by guessing.
            }
            if ($named !== []) {
                $index = $named[0];
                if ($index['columns'] !== [$column] || (bool) $index['unique'] !== $unique || $index['primary']) {
                    self::block();
                }
                if (DB::getDriverName() === 'mysql') {
                    $parts = DB::table('information_schema.STATISTICS')
                        ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                        ->where('TABLE_NAME', $table)->where('INDEX_NAME', $name)
                        ->get(['SEQ_IN_INDEX', 'COLUMN_NAME', 'NON_UNIQUE', 'SUB_PART', 'EXPRESSION',
                            'INDEX_TYPE', 'IS_VISIBLE', 'COLLATION']);
                    $part = $parts->first();
                    if ($parts->count() !== 1 || (int) $part->SEQ_IN_INDEX !== 1
                        || $part->COLUMN_NAME !== $column || (int) $part->NON_UNIQUE !== ($unique ? 0 : 1)
                        || $part->SUB_PART !== null || $part->EXPRESSION !== null
                        || $part->INDEX_TYPE !== 'BTREE' || $part->IS_VISIBLE !== 'YES' || $part->COLLATION !== 'A') {
                        self::block();
                    }
                }
            }
            $present[$name] = $named !== [];
        }
        // Initially the old unique must exist. A valid active unique can also
        // identify a committed partial transition; missing both is ambiguous.
        if (! $present['mcp_linked_subjects_identity_fingerprint_unique']
            && ! $present['mcp_active_subject_identity_unique']) {
            self::block();
        }
        if (Schema::hasColumn($table, 'active_identity_fingerprint') && DB::getDriverName() === 'mysql') {
            $column = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', 'active_identity_fingerprint')->first();
            if ($column === null || $column->COLUMN_TYPE !== 'char(64)' || $column->IS_NULLABLE !== 'YES'
                || $column->COLUMN_DEFAULT !== null || $column->EXTRA !== '') {
                self::block();
            }
        }
        // Detect incompatible active records BEFORE column/index DDL or the
        // existing backfill. No record or duplicate key leaves this process.
        if (DB::table($table)->where('status', 'linked')->whereNull('revoked_at')
            ->select('identity_fingerprint')->groupBy('identity_fingerprint')->havingRaw('COUNT(*) > 1')->exists()) {
            self::block();
        }
    }

    private static function block(): never
    {
        throw new RuntimeException('RELEASE20_IDENTITY_PREFLIGHT_BLOCK');
    }
}
