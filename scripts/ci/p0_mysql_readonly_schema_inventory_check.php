<?php

declare(strict_types=1);

/**
 * P0 read-only schema inventory SQL smoke for disposable MySQL 8 only.
 *
 * The source SQL is a separate, reviewable SELECT-only operator artifact.
 * This harness MUST NOT run against a non-CI or production database.
 * It intentionally prints no database/schema data or personal records.
 */
if (getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'canovia_p0_ci'
    || getenv('DB_USERNAME') !== 'canovia_ci') {
    fwrite(STDERR, "p0_inventory_ci_environment: blocked\n");
    exit(1);
}

$path = dirname(__DIR__).'/sql/p0_mysql_readonly_schema_inventory.sql';
$sql = file_get_contents($path);
if (! is_string($sql) || strlen($sql) > 16000) {
    fwrite(STDERR, "p0_inventory_sql_bounds: blocked\n");
    exit(1);
}

// Remove whole-line SQL comments (never evaluate arbitrary substitutions).
// Multi-statements are forbidden at PDO connection level below.
$source = preg_replace('/^\s*--[^\n]*$/m', '', $sql);
if (! is_string($source)) {
    exit(1);
}
$queries = array_values(array_filter(
    array_map('trim', explode(';', $source)),
    static fn (string $q): bool => $q !== '',
));
if (count($queries) !== 6) {
    fwrite(STDERR, "p0_inventory_query_count: blocked\n");
    exit(1);
}
foreach ($queries as $query) {
    // Audit SQL may contain literal 'SET NULL', which is not a command.
    // Reject any non-SELECT statement and attempts to export results to files.
    if (! preg_match('/\ASELECT\s/i', $query)
        || preg_match('/(?:\A|\n)\s*(?:UPDATE|DELETE|INSERT|ALTER|DROP|CREATE|REPLACE|TRUNCATE|GRANT|REVOKE|CALL|SET|LOCK)\b/i', $query)
        || preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $query)) {
        fwrite(STDERR, "p0_inventory_select_only: blocked\n");
        exit(1);
    }
}

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=canovia_p0_ci;charset=utf8mb4',
        'canovia_ci',
        (string) getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ],
    );
    $succeeded = [];
    $numberOfChecks = 0;
    foreach ($queries as $query) {
        $result = $pdo->query($query);
        if ($result === false) {
            throw new RuntimeException('Read-only inventory query failed');
        }
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException('Read-only inventory returned no checks');
        }
        foreach ($rows as $row) {
            $section = $row['section'] ?? '';
            $objectId = $row['object_id'] ?? '';
            $status = $row['status'] ?? '';
            if (! is_string($section) || ! is_string($objectId)
                || ! is_string($status) || $objectId === ''
                || ! in_array($status, ['SCHEMA_SELECTED', 'PRESENT', 'PASS', 'APPLIED'], true)) {
                throw new RuntimeException('Read-only inventory detected a schema mismatch');
            }
            $succeeded[$section.'|'.$objectId] = true;
            ++$numberOfChecks;
        }
    }
    foreach ([
        'table|migrations',
        'table|intelligence_decision_traces',
        'table|learning_answer_evaluation_adjustments',
        'foreign_key|idt_snapshot_fk',
        'foreign_key|laea_answer_event_fk',
        'foreign_key|laea_user_fk',
        'index|learning_eval_adjustment_event_unique',
        'index|intelligence_decision_scope_created_idx',
        'migration|2026_10_04_000200_create_intelligence_decision_traces_table',
        'migration|2026_10_08_230000_create_learning_answer_evaluation_adjustments',
    ] as $required) {
        if (! isset($succeeded[$required])) {
            throw new RuntimeException('Required inventory check missing');
        }
    }
    if ($numberOfChecks < 25) {
        throw new RuntimeException('Read-only inventory truncated');
    }
    echo "p0_readonly_mysql_inventory_all_expected_checks: pass\n";
    echo "production_aiven_schema_invoked: false\n";
} catch (Throwable) {
    // Exception messages can contain details about the host/schema/SQL.
    fwrite(STDERR, "p0_readonly_mysql_inventory: blocked\n");
    exit(1);
}
