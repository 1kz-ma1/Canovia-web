<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LearningContextSqlMutationProbe as Probe;
use Tests\TestCase;

/**
 * Real process-level deadlock and post-abort recovery evidence.
 * Only the pinned disposable MySQL 8.4 / PostgreSQL 17 CI matrix can run it.
 */
final class LearningContextSqlMutationDeadlockV016BTest extends TestCase
{
    private bool $installed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Probe::assertDisposableDatabase();
        if (getenv('CI') !== 'true'
            || (string) (config('database.connections.'.config('database.default').'.username')) !== 'canovia_ci'
            || (string) getenv('DB_URL') !== '') {
            throw new RuntimeException('V016b requires a pinned, isolated CI database and user.');
        }
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->installed) {
            Probe::uninstall();
            $this->installed = false;
        }
        parent::tearDown();
    }

    public function test_real_database_deadlock_victim_rolls_back_and_plan_first_followup_serializes(): void
    {
        Probe::install(true);
        $this->installed = true;
        [$plan, $task] = $this->fixture();
        $initial = Probe::inspect($plan->id);
        self::assertNotNull($initial);
        self::assertSame('tasks_insert', $initial['last_mutation_kind']);
        $progressBefore = (int) $task->progress_percent;

        // Two OS processes, each with its own PDO and DB transaction.
        // Opposing resources are held simultaneously before either second lock.
        $crossed = $this->runPair('deadlock', $plan->id, $task->id);
        $statuses = array_column($crossed, 'status');
        sort($statuses);
        self::assertSame(['committed', 'deadlock'], $statuses);
        $winner = $crossed['a']['status'] === 'committed' ? 'a' : 'b';
        self::assertSame('deadlock', $crossed[$winner === 'a' ? 'b' : 'a']['status']);

        // Each worker writes its first row BEFORE it requests the opposing
        // lock. The aborted worker's write must have been fully rolled back.
        $afterCrossed = Probe::inspect($plan->id);
        self::assertSame($initial['epoch'], $afterCrossed['epoch']);
        self::assertSame(
            $winner === 'a' ? 'v016b_ledger_first' : $initial['last_mutation_kind'],
            $afterCrossed['last_mutation_kind'],
        );
        self::assertSame(
            $winner === 'b' ? 'v016b_task_first' : 'BEFORE_V016B',
            $task->fresh()->description,
        );
        self::assertSame($progressBefore, (int) $task->fresh()->progress_percent);

        // Explicit fresh transactions, NOT an unsafe replay of the partial
        // failed transaction. Both workers take Plan -> Task -> probe ledger.
        $ordered = $this->runPair('ordered', $plan->id, $task->id);
        self::assertSame('committed', $ordered['a']['status']);
        self::assertSame('committed', $ordered['b']['status']);
        self::assertSame($progressBefore + 2, (int) $task->fresh()->progress_percent);
        self::assertSame($initial['epoch'] + 2, Probe::inspect($plan->id)['epoch']);
        self::assertSame('tasks_update', Probe::inspect($plan->id)['last_mutation_kind']);
        self::assertFalse(Probe::inspect($plan->id)['authoritative']);
    }

    /** @return array{Plan,Task} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V016B_DISPOSABLE_PLAN',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(10),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'V016B_DISPOSABLE_TASK',
            'description' => 'BEFORE_V016B',
            'status' => 'doing',
            'progress_percent' => 11,
            'sort_order' => 1,
            'priority' => 1,
        ]);

        return [$plan, $task];
    }

    /** @return array{a:array<string,mixed>,b:array<string,mixed>} */
    private function runPair(string $mode, int $planId, int $taskId): array
    {
        $directory = sys_get_temp_dir().'/canovia_lock_v016b_'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create disposable worker barrier.');
        }
        $cfg = config('database.connections.'.config('database.default'));
        $env = [
            'CI' => 'true',
            'APP_ENV' => 'testing',
            'CANOVIA_LEARNING_LOCK_DISPOSABLE_CI' => '1',
            'DB_CONNECTION' => DB::getDriverName(),
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $cfg['port'],
            'DB_DATABASE' => 'canovia_learning_watch_lock_ci',
            'DB_USERNAME' => 'canovia_ci',
            'DB_PASSWORD' => (string) $cfg['password'],
            'DB_URL' => '',
        ];
        $processes = [];
        try {
            foreach (['a', 'b'] as $role) {
                $command = [
                    PHP_BINARY,
                    base_path('tests/Support/LearningContextDeadlockWorkerV016B.php'),
                    $mode, $role, (string) $planId, (string) $taskId, $directory,
                ];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path(), $env);
                if (! is_resource($process)) {
                    throw new RuntimeException('Could not start separate CI worker.');
                }
                fclose($pipes[0]);
                $processes[$role] = ['process' => $process, 'pipes' => $pipes];
            }

            $until = microtime(true) + 22;
            $await = static function (callable $done, string $why) use ($until): void {
                while (! $done()) {
                    if (microtime(true) >= $until) {
                        throw new RuntimeException('Disposable worker timeout: '.$why);
                    }
                    usleep(20000);
                }
            };
            if ($mode === 'deadlock') {
                $await(static function () use ($directory): bool {
                    return is_file($directory.'/deadlock.a.ready')
                        && is_file($directory.'/deadlock.b.ready');
                }, 'first lock barrier');
                file_put_contents($directory.'/deadlock.go', '1', LOCK_EX);
            }
            $await(static function () use ($directory, $mode): bool {
                return is_file($directory."/$mode.a.result.json")
                    && is_file($directory."/$mode.b.result.json");
            }, 'both independent database transactions');

            $results = [];
            foreach (['a', 'b'] as $role) {
                $result = json_decode(
                    (string) file_get_contents($directory."/$mode.$role.result.json"),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                if (! is_array($result) || ! isset($result['status'])) {
                    throw new RuntimeException('Invalid CI-only worker result.');
                }
                $results[$role] = $result;
            }

            return $results;
        } finally {
            foreach ($processes as $entry) {
                $process = $entry['process'];
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                foreach ([$entry['pipes'][1], $entry['pipes'][2]] as $pipe) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
                proc_close($process);
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}
