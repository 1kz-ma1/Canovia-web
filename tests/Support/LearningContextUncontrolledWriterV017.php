<?php

/**
 * v0.17 - ephemeral, guarded PDO process; never loaded by the application.
 * The CLI and the database must BOTH be the pinned disposable CI environment.
 */
declare(strict_types=1);

[$script, $mode, $role, $planId, $taskId, $dir, $requestKey] = array_pad($argv, 7, null);
$driver = getenv('DB_CONNECTION');
$expectedPort = $driver === 'mysql' ? 3306 : 5432;
$root = realpath(sys_get_temp_dir());
$actual = is_string($dir) ? realpath($dir) : false;
if (PHP_SAPI !== 'cli'
    || getenv('CI') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('CANOVIA_LEARNING_LOCK_DISPOSABLE_CI') !== '1'
    || ! in_array($driver, ['mysql', 'pgsql'], true)
    || getenv('DB_HOST') !== '127.0.0.1'
    || (int) getenv('DB_PORT') !== $expectedPort
    || getenv('DB_DATABASE') !== 'canovia_learning_watch_lock_ci'
    || getenv('DB_USERNAME') !== 'canovia_ci'
    || (string) getenv('DB_URL') !== ''
    || ! in_array($mode, ['mixed', 'once', 'mismatch'], true)
    || ! in_array($role, ['a', 'b'], true)
    || ! ctype_digit((string) $planId) || (int) $planId < 1
    || ! ctype_digit((string) $taskId) || (int) $taskId < 1
    || ! is_string($requestKey) || preg_match('/\A[a-f0-9]{64}\z/D', $requestKey) !== 1
    || $root === false || $actual === false
    || ! str_starts_with($actual, $root.DIRECTORY_SEPARATOR.'canovia_lock_v017_')) {
    fwrite(STDERR, "V017_DISPOSABLE_ONLY_DENIED\n");
    exit(42);
}
$dir = $actual;
$path = static fn (string $suffix): string => "$dir/$mode.$role.$suffix";
$pdo = null;
$result = ['role' => $role, 'mode' => $mode, 'status' => 'error'];
$exit = 1;
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

    $one = static function (string $sql, array $args) use ($pdo): mixed {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        $v = $s->fetchColumn();
        $s->closeCursor();
        if ($v === false) {
            throw new RuntimeException('Expected disposable row is absent');
        }
        return $v;
    };
    $update = static function (string $sql, array $args) use ($pdo): void {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        if ($s->rowCount() !== 1) {
            throw new RuntimeException('Expected one disposable update');
        }
        $s->closeCursor();
    };

    if ($mode === 'mixed') {
        if ($role === 'a') {
            // Deliberately UNSAFE app writer: Plan -> ledger -> Task.
            $one('SELECT id FROM plans WHERE id = ? FOR UPDATE', [(int) $planId]);
            $one('SELECT epoch FROM learning_context_probe_epochs WHERE plan_id = ? FOR UPDATE', [(int) $planId]);
            $update(
                "UPDATE learning_context_probe_epochs SET last_mutation_kind='v017_app_first' WHERE plan_id = ?",
                [(int) $planId],
            );
        } else {
            // Direct SQL bypasses app Plan-first policy: locks Task immediately.
            // Description is not hashed, and v0.15's filter ignores it.
            $update("UPDATE tasks SET description='v017_raw_first' WHERE id = ?", [(int) $taskId]);
        }
        file_put_contents($path('ready'), '1', LOCK_EX);
        $deadline = microtime(true) + 10;
        while (! is_file("$dir/mixed.go")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Synthetic first-lock barrier timed out');
            }
            usleep(20000);
        }
        if ($role === 'a') {
            $one('SELECT progress_percent FROM tasks WHERE id = ? FOR UPDATE', [(int) $taskId]);
        } else {
            // This triggers an AFTER UPDATE ledger bump *after* Task is locked.
            // Deadlock is real: Task -> ledger versus app ledger -> Task.
            $update(
                'UPDATE tasks SET progress_percent=progress_percent+1 WHERE id = ?',
                [(int) $taskId],
            );
        }
        $result['status'] = 'committed';
    } else {
        // Candidate app-controlled idempotency protocol. One Plan lock
        // serializes matching requests; lookup is a CURRENT locking read.
        $one('SELECT id FROM plans WHERE id = ? FOR UPDATE', [(int) $planId]);
        $fingerprint = hash('sha256', $mode === 'mismatch' ? 'progress:+2' : 'progress:+1');
        $read = $pdo->prepare(
            'SELECT plan_id, task_id, fingerprint FROM learning_context_probe_requests WHERE request_key = ? FOR UPDATE'
        );
        $read->execute([$requestKey]);
        $stored = $read->fetch(PDO::FETCH_ASSOC);
        $read->closeCursor();
        if ($stored !== false) {
            $result['status'] =
                (int) $stored['plan_id'] === (int) $planId
                && (int) $stored['task_id'] === (int) $taskId
                && hash_equals((string) $stored['fingerprint'], $fingerprint)
                ? 'replayed' : 'rejected';
        } else {
            if ($mode !== 'once') {
                throw new RuntimeException('Mismatch stage requires an existing committed key');
            }
            $current = (int) $one(
                'SELECT progress_percent FROM tasks WHERE id = ? AND plan_id = ? FOR UPDATE',
                [(int) $taskId, (int) $planId],
            );
            if ($current >= 99) {
                throw new RuntimeException('Synthetic progress boundary exceeded');
            }
            $update(
                'UPDATE tasks SET progress_percent=progress_percent+1 WHERE id = ?',
                [(int) $taskId],
            );
            // Same SQL transaction: both Task+filtered trigger and request
            // journal commit or roll back atomically. No external calls.
            $s = $pdo->prepare(
                'INSERT INTO learning_context_probe_requests (request_key,plan_id,task_id,fingerprint) VALUES (?,?,?,?)'
            );
            $s->execute([$requestKey, (int) $planId, (int) $taskId, $fingerprint]);
            $result['status'] = 'applied';
        }
    }

    $pdo->commit();
    $exit = 0;
} catch (PDOException $e) {
    $state = (string) ($e->errorInfo[0] ?? $e->getCode());
    $vendor = (int) ($e->errorInfo[1] ?? 0);
    $deadlock = ($driver === 'pgsql' && $state === '40P01')
        || ($driver === 'mysql' && $state === '40001' && $vendor === 1213);
    $result['sqlstate'] = $state;
    $result['vendor_code'] = $vendor;
    if ($mode === 'mixed' && $deadlock) {
        $result['status'] = 'deadlock';
        $exit = 0;
    } else {
        fwrite(STDERR, "V017_UNEXPECTED_SQLSTATE:$state:$vendor\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'V017_WORKER_ERROR:'.get_class($e)."\n");
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (PDOException) {
            // MySQL may have rolled back the deadlock victim already.
        }
    }
    file_put_contents($path('result.json'), json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
}
exit($exit);
