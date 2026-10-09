<?php

/**
 * Explicitly operator-run, SELECT-only P0 MySQL recovery preflight.
 *
 * Never calls Migration::up(), artisan migrate, schema alteration or updates.
 * Must use an independently verified, existing MySQL SELECT-only account.
 * Outputs fixed classifications only (never names, row values or secrets).
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$emit = static function (string $status, string $code): void {
    echo json_encode([
        'result' => $status,
        'code' => $code,
        'release_authorized' => false,
        'production_database_modified' => false,
        'database_identity_independently_verified' => false,
        'backup_restore_verified' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
};

if (count($argv ?? []) !== 2 || ($argv[1] ?? null) !== '--check'
    || getenv('CANOVIA_P0_READONLY_PREFLIGHT') !== '1'
    || ! in_array(getenv('APP_ENV'), ['production', 'testing'], true)
    || getenv('DB_CONNECTION') !== 'mysql') {
    $emit('BLOCK', 'PREFLIGHT_OPERATOR_GUARD_REJECTED');
    exit(2);
}

$expectedHost = getenv('CANOVIA_P0_EXPECTED_DB_HOST');
$expectedDatabase = getenv('CANOVIA_P0_EXPECTED_DB_NAME');
if (! is_string($expectedHost) || trim($expectedHost) === ''
    || ! is_string($expectedDatabase) || trim($expectedDatabase) === ''
    || strlen($expectedHost) > 255 || strlen($expectedDatabase) > 64) {
    $emit('BLOCK', 'PREFLIGHT_TARGET_EXPECTATION_MISSING');
    exit(2);
}

// Only the fixed P0 migration is imported; its preflight() is SELECT-only.
// Kernel boot may initialize local application services; use an operator-owned
// isolated environment with an existing read-only DB credential.
try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = config('database.connections.mysql');
    if (config('database.default') !== 'mysql'
        || ! is_array($connection)
        || (string) ($connection['host'] ?? '') !== $expectedHost
        || (string) ($connection['database'] ?? '') !== $expectedDatabase
        || ! app()->environment(getenv('APP_ENV'))) {
        $emit('BLOCK', 'PREFLIGHT_EFFECTIVE_TARGET_MISMATCH');
        exit(2);
    }

    // Verify the active SQL connection's schema; a matching configured host
    // and database is NOT proof the intended Aiven service was selected.
    $selected = DB::selectOne('SELECT DATABASE() AS selected_database');
    if (! is_object($selected)
        || ($selected->selected_database ?? null) !== $expectedDatabase) {
        $emit('BLOCK', 'PREFLIGHT_SELECTED_SCHEMA_MISMATCH');
        exit(2);
    }

    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php';
    $migration->preflight();

    $emit('REVIEW_REQUIRED', 'PREFLIGHT_VALID_NOT_RELEASE_AUTHORIZATION');
    exit(0);
} catch (Throwable $exception) {
    // Diagnostic names are selected from a static whitelist: never reflect
    // arbitrary SQL exception strings, column IDs, credentials or raw rows.
    $codes = [
        'P0 recovery blocked: migration ledger missing.' => 'PREFLIGHT_LEDGER_MISSING',
        'P0 recovery blocked: expected table or migration history missing.' => 'PREFLIGHT_HISTORY_OR_TABLE_MISSING',
        'P0 recovery blocked: incomplete table contract.' => 'PREFLIGHT_COLUMN_MISSING',
        'P0 recovery blocked: missing referenced table or column.' => 'PREFLIGHT_PARENT_MISSING',
        'P0 recovery blocked: ambiguous foreign key.' => 'PREFLIGHT_FK_AMBIGUOUS',
        'P0 recovery blocked: incompatible foreign key.' => 'PREFLIGHT_FK_INCOMPATIBLE',
        'P0 recovery blocked: foreign key name collision.' => 'PREFLIGHT_FK_NAME_COLLISION',
        'P0 recovery blocked: database-wide foreign key name collision.' => 'PREFLIGHT_FK_SCHEMA_WIDE_COLLISION',
        'P0 recovery blocked: incompatible foreign-key column types.' => 'PREFLIGHT_FK_COLUMN_TYPES',
        'P0 recovery blocked: SET NULL foreign key column is not nullable.' => 'PREFLIGHT_FK_NULLABILITY',
        'P0 recovery blocked: orphaned foreign-key references.' => 'PREFLIGHT_ORPHANED_FK',
        'P0 recovery blocked: incompatible index.' => 'PREFLIGHT_INDEX_INCOMPATIBLE',
        'P0 recovery blocked: index name collision.' => 'PREFLIGHT_INDEX_NAME_COLLISION',
        'P0 recovery blocked: duplicate values for unique index.' => 'PREFLIGHT_DUPLICATE_UNIQUE_KEY',
    ];
    $emit('BLOCK', $codes[$exception->getMessage()] ?? 'PREFLIGHT_UNEXPECTED_ERROR');
    exit(2);
}
