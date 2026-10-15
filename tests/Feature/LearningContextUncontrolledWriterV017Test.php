<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LearningContextSqlMutationProbe as Probe;
use Tests\TestCase;

/**
 * Ephemeral MySQL8.4/PostgreSQL17 ONLY. No runtime retry or production DDL.
 * Real independent worker processes; no wrapping RefreshDatabase transaction.
 */
final class LearningContextUncontrolledWriterV017Test extends TestCase
{
    private bool $installed = false;
    private bool $journal = false;
    private const JOURNAL = 'learning_context_probe_requests';

    protected function setUp(): void
    {
        parent::setUp();
        Probe::assertDisposableDatabase();
        $cfg = config('database.connections.'.config('database.default'));
        if (getenv('CI') !== 'true'
            || ($cfg['username'] ?? null) !== 'canovia_ci'
            || (string) getenv('DB_URL') !== '') {
            throw new RuntimeException('v0.17 requires pinned isolated CI principal.');
        }
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // If a test fails, both child connections are killed in runPair().
        // Never alter tables before asserting the disposable DB again.
        Probe::assertDisposableDatabase();
        if ($this->journal) {
            Schema::dropIfExists(self::JOURNAL);
            $this->journal = false;
        }
        if ($this->installed) {
            Probe::uninstall();
            $this->installed = false;
        }
        parent::tearDown();
    }

    public function test_raw_sql_lock_inversion_and_keyed_repeat_are_detected_without_false_commits(): void
    {
        Probe::install(true);
        $this->installed = true;
        if (Schema::hasTable(self::JOURNAL)) {
            throw new RuntimeException('Unexpected existing v0.17 synthetic journal.');
        }
        Schema::create(self::JOURNAL, static function (Blueprint $table): void {
            $table->string('request_key', 64)->primary();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('task_id');
            $table->string('fingerprint', 64);
        });
        $this->journal = true;

        [$owner, $plan, $task] = $this->fixture();
        $epoch0 = (int) Probe::inspect($plan->id)['epoch'];
        $progress0 = (int) $task->progress_percent;
        $revision0 = $this->revision($owner, $plan);
        $key = bin2hex(random_bytes(32));

        // The app holds Plan and ledger while a raw SQL writer holds Task.
        // The writer's real progress UPDATE then obtains the ledger lock
        // in its AFTER trigger: even an app Plan lock cannot prevent AB/BA.
        $crossed = $this->runPair('mixed', $plan->id, $task->id, $key);
        $statuses = array_column($crossed, 'status');
        sort($statuses);
        $this->assertSame(['committed', 'deadlock'], $statuses);
        $appWon = $crossed['a']['status'] === 'committed';
        $this->assertSame($appWon ? 'deadlock' : 'committed', $crossed['b']['status']);

        $postMixed = Probe::inspect($plan->id);
        $this->assertSame($epoch0 + ($appWon ? 0 : 1), (int) $postMixed['epoch']);
        $this->assertSame(
            $appWon ? 'v017_app_first' : 'tasks_update',
            $postMixed['last_mutation_kind'],
        );
        $this->assertSame(
            $appWon ? 'BEFORE_V017' : 'v017_raw_first',
            $task->fresh()->description,
        );
        $this->assertSame($progress0 + ($appWon ? 0 : 1), (int) $task->fresh()->progress_percent);
        $this->assertSame($appWon, $revision0 === $this->revision($owner, $plan));

        // Independently model cooperating, transactional, idempotent app
        // replay. This cannot make uncontrolled raw SQL writers safe.
        $before = (int) $task->fresh()->progress_percent;
        $epochBefore = (int) Probe::inspect($plan->id)['epoch'];
        $first = $this->runPair('once', $plan->id, $task->id, $key);
        $firstStatuses = array_column($first, 'status');
        sort($firstStatuses);
        $this->assertSame(['applied', 'replayed'], $firstStatuses);
        $this->assertSame($before + 1, (int) $task->fresh()->progress_percent);
        $this->assertSame($epochBefore + 1, Probe::inspect($plan->id)['epoch']);
        $this->assertSame(1, DB::table(self::JOURNAL)->count());
        $this->assertDatabaseHas(self::JOURNAL, [
            'request_key' => $key,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'fingerprint' => hash('sha256', 'progress:+1'),
        ]);

        $revisionApplied = $this->revision($owner, $plan);
        $again = $this->runPair('once', $plan->id, $task->id, $key);
        $this->assertSame('replayed', $again['a']['status']);
        $this->assertSame('replayed', $again['b']['status']);

        // The same idempotency key cannot be repurposed for a different
        // payload. Deny it, even though the original request already landed.
        $mismatched = $this->runPair('mismatch', $plan->id, $task->id, $key);
        $this->assertSame('rejected', $mismatched['a']['status']);
        $this->assertSame('rejected', $mismatched['b']['status']);
        $this->assertSame($before + 1, (int) $task->fresh()->progress_percent);
        $this->assertSame($epochBefore + 1, Probe::inspect($plan->id)['epoch']);
        $this->assertSame($revisionApplied, $this->revision($owner, $plan));
        $this->assertSame(1, DB::table(self::JOURNAL)->count());
        $this->assertFalse(Probe::inspect($plan->id)['authoritative']);
    }

    /** @return array{User,Plan,Task} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V017_SYNTHETIC_ONLY',
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
            'title' => 'V017_SYNTHETIC_TASK',
            'description' => 'BEFORE_V017',
            'status' => 'doing',
            'progress_percent' => 11,
            'priority' => 1,
            'sort_order' => 1,
        ]);
        return [$owner, $plan, $task];
    }

    private function revision(User $owner, Plan $plan): string
    {
        $snapshot = app(LearningContextSourceCaptureService::class)->capture($owner, $plan->id);
        $this->assertNotNull($snapshot);
        return $snapshot['source_revision'];
    }

    /** @return array{a:array<string,mixed>,b:array<string,mixed>} */
    private function runPair(string $mode, int $planId, int $taskId, string $key): array
    {
        $directory = sys_get_temp_dir().'/canovia_lock_v017_'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create v0.17 disposable semaphore.');
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
        $children = [];
        try {
            foreach (['a', 'b'] as $role) {
                $command = [
                    PHP_BINARY,
                    base_path('tests/Support/LearningContextUncontrolledWriterV017.php'),
                    $mode, $role, (string) $planId, (string) $taskId, $directory, $key,
                ];
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path(), $env);
                if (! is_resource($process)) {
                    throw new RuntimeException('Failed to spawn v0.17 disposable worker.');
                }
                fclose($pipes[0]);
                $children[$role] = ['process' => $process, 'pipes' => $pipes];
            }

            $until = microtime(true) + 22;
            $await = static function (callable $ready, string $label) use ($children, $directory, $mode, $until): void {
                while (! $ready()) {
                    foreach ($children as $role => $child) {
                        $status = proc_get_status($child['process']);
                        if (! $status['running'] && ! is_file("$directory/$mode.$role.result.json")) {
                            $safeError = trim((string) stream_get_contents($child['pipes'][2]));
                            throw new RuntimeException(
                                'v0.17 worker ended before result: '.$role.' code='.$status['exitcode']
                                .' diagnostic='.substr($safeError, 0, 160),
                            );
                        }
                    }
                    if (microtime(true) >= $until) {
                        throw new RuntimeException('v0.17 bounded wait exceeded: '.$label);
                    }
                    usleep(20000);
                }
            };

            if ($mode === 'mixed') {
                $await(static fn (): bool =>
                    (is_file("$directory/mixed.a.ready") && is_file("$directory/mixed.b.ready"))
                    || is_file("$directory/mixed.a.result.json")
                    || is_file("$directory/mixed.b.result.json"), 'first lock barrier');
                if (! is_file("$directory/mixed.a.ready") || ! is_file("$directory/mixed.b.ready")) {
                    throw new RuntimeException('One worker failed before mixed lock barrier.');
                }
                file_put_contents("$directory/mixed.go", '1', LOCK_EX);
            }
            $await(static fn (): bool =>
                is_file("$directory/$mode.a.result.json")
                && is_file("$directory/$mode.b.result.json"), 'both transaction results');

            $result = [];
            foreach (['a', 'b'] as $role) {
                $item = json_decode(
                    (string) file_get_contents("$directory/$mode.$role.result.json"),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                if (! is_array($item) || ! isset($item['status'])) {
                    throw new RuntimeException('Invalid disposable worker report.');
                }
                $result[$role] = $item;
            }
            return $result;
        } finally {
            foreach ($children as $child) {
                if (proc_get_status($child['process'])['running']) {
                    proc_terminate($child['process']);
                }
                foreach ([$child['pipes'][1], $child['pipes'][2]] as $pipe) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
                proc_close($child['process']);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }
}
