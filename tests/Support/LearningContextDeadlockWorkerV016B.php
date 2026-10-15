<?php

/**
 * V0.16b disposable-CI-only SQL lock experiment worker.
 *
 * A separate PHP process and PDO connection are essential: a second Laravel
 * connection in the same test process cannot deterministically interleave two
 * blocking statements. No Laravel bootstrap or persistent DB is permitted.
 */
declare(strict_types=1);

[$script, $mode, $role, $planId, $taskId, $directory] = array_pad($argv, 6, null);
$driver = getenv('DB_CONNECTION');
$port = (int) getenv('DB_PORT');
$expectedPort = $driver === 'mysql' ? 3306 : 5432;
if (PHP_SAPI !== 'cli'
    || getenv('CI') !== 'true'
    || getenv('CANOVIA_LEARNING_LOCK_DISPOSABLE_CI') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || ! in_array($driver, ['mysql', 'pgsql'], true)
    || getenv('DB_HOST') !== '127.0.0.1'
    || $port !== $expectedPort
    || getenv('DB_DATABASE') !== 'canovia_learning_watch_lock_ci'
    || getenv('DB_USERNAME') !== 'canovia_ci'
    || getenv('DB_URL') !== ''
    || ! in_array($mode, ['deadlock', 'ordered'], true)
    || ! in_array($role, ['a', 'b'], true)
    || ! ctype_digit((string) $planId)
    || ! ctype_digit((string) $taskId)
    || (int) $planId < 1
    || (int) $taskId < 1
    || ! is_dir($directory)
    || ! str_starts_with(realpath($directory) ?: '', realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'canovia_lock_v016b_')) {
    fwrite(STDERR, "V016B_DISPOSABLE_ONLY_DENIED\n");
    exit(42);
}

$path = static fn (string $suffix): string => $directory.DIRECTORY_SEPARATOR."$mode.$role.$suffix";
$pdo = null;
$result = ['role' => $role, 'mode' => $mode, 'status' => 'error'];
$exitCode = 1;
try {
    $dsn = $driver === 'pgsql'
        ? 'pgsql:host=127.0.0.1;port=5432;dbname=canovia_learning_watch_lock_ci'
        : 'mysql:host=127.0.0.1;port=3306;dbname=canovia_learning_watch_lock_ci;charset=utf8mb4';
    $pdo = new PDO($dsn, 'canovia_ci', (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($driver === 'mysql') {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 8');
    }
    $pdo->beginTransaction();
    if ($driver === 'pgsql') {
        $pdo->exec("SET LOCAL lock_timeout = '8s'");
        $pdo->exec("SET LOCAL statement_timeout = '12s'");
    }

    $lock = static function (string $table, string $field, int $id) use ($pdo): void {
        // Table/field names are fixed literals below, never external input.
        $sql = "SELECT $field FROM $table WHERE ".($table === 'tasks' ? 'id' : 'plan_id')." = ? FOR UPDATE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException("Expected row missing in $table");
        }
        $stmt->closeCursor();
    };
    $update = static function (string $sql, int $id) use ($pdo): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Expected exactly one disposable row update');
        }
    };

    if ($mode === 'deadlock') {
        if ($role === 'a') {
            $lock('learning_context_probe_epochs', 'epoch', (int) $planId);
            $update(
                "UPDATE learning_context_probe_epochs SET last_mutation_kind = 'v016b_ledger_first' WHERE plan_id = ?",
                (int) $planId,
            );
        } else {
            $lock('tasks', 'progress_percent', (int) $taskId);
            // Excluded by the v0.15 filtered trigger; remains transactional.
            $update(
                "UPDATE tasks SET description = 'v016b_task_first' WHERE id = ?",
                (int) $taskId,
            );
        }
        file_put_contents($path('ready'), '1', LOCK_EX);
        $deadline = microtime(true) + 10;
        while (! is_file($directory.DIRECTORY_SEPARATOR.'deadlock.go')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for peer to hold its first lock');
            }
            usleep(20000);
        }
        if ($role === 'a') {
            $lock('tasks', 'progress_percent', (int) $taskId);
        } else {
            $lock('learning_context_probe_epochs', 'epoch', (int) $planId);
        }
    } else {
        // Candidate for app-controlled writes: Plan -> Task -> ledger.
        // Both workers use the same order; the Plan lock serializes them.
        $stmt = $pdo->prepare('SELECT id FROM plans WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $planId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Expected Plan missing');
        }
        $stmt->closeCursor();
        usleep(150000); // Encourage contention without depending on exact timing.
        $lock('tasks', 'progress_percent', (int) $taskId);
        $lock('learning_context_probe_epochs', 'epoch', (int) $planId);
        $update(
            'UPDATE tasks SET progress_percent = progress_percent + 1 WHERE id = ?',
            (int) $taskId,
        );
    }
    $pdo->commit();
    $result['status'] = 'committed';
    $exitCode = 0;
} catch (PDOException $e) {
    $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
    $vendorCode = (int) ($e->errorInfo[1] ?? 0);
    $deadlock = ($driver === 'pgsql' && $sqlstate === '40P01')
        || ($driver === 'mysql' && $sqlstate === '40001' && $vendorCode === 1213);
    $result += ['sqlstate' => $sqlstate, 'vendor_code' => $vendorCode];
    if ($mode === 'deadlock' && $deadlock) {
        $result['status'] = 'deadlock';
        $exitCode = 0;
    } else {
        // Deliberately do not copy raw SQL errors, SQL literals or credentials.
        fwrite(STDERR, "V016B_UNEXPECTED_SQL_ERROR:$sqlstate:$vendorCode\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'V016B_WORKER_FAILURE:'.get_class($e)."\n");
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
            // A deadlock victim may already have been rolled back by MySQL.
            // Still report the original SQLSTATE to the parent test.
        }
    }
    file_put_contents($path('result.json'), json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}
exit($exitCode);
