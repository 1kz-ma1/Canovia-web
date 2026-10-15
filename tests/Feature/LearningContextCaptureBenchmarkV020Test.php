<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextCaptureDiagnostics;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * v0.20: synthetic source-capture benchmark, never a production benchmark.
 * Only the pinned disposable CI MySQL 8.4 / PostgreSQL 17 or in-memory SQLite.
 * No RefreshDatabase wrapping transaction: real SQL and measured reads must
 * commit normally. No performance SLA assertions on shared CI hosts.
 */
final class LearningContextCaptureBenchmarkV020Test extends TestCase
{
    private const SAMPLES = 21;

    /** @var list<int> */
    private array $planIds = [];

    private ?int $ownerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertDisposableTarget();
        if (DB::getDriverName() === 'sqlite') {
            // This connection is guaranteed to be :memory: by the guard;
            // unlike RefreshDatabase this does not wrap benchmark reads in
            // a per-test transaction. Real engines are migrated by the CI job.
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        }
        config([
            'intelligence.learning_context_watch.capture_diagnostics_enabled' => false,
            'intelligence.learning_context_watch.epoch_shadow_enabled' => false,
            'intelligence.learning_context_watch.persistence_enabled' => false,
        ]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->assertDisposableTarget();
        try {
            if ($this->planIds !== []) {
                DB::table('study_practice_attempts')->whereIn('plan_id', $this->planIds)->delete();
                DB::table('tasks')->whereIn('plan_id', $this->planIds)->delete();
                DB::table('plans')->whereIn('id', $this->planIds)->delete();
            }
            if ($this->ownerId !== null) {
                DB::table('users')->where('id', $this->ownerId)->delete();
            }
        } finally {
            config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => false]);
            parent::tearDown();
        }
    }

    private function assertDisposableTarget(): void
    {
        $driver = DB::getDriverName();
        $cfg = config('database.connections.'.config('database.default'));
        $real = in_array($driver, ['mysql', 'pgsql'], true)
            && getenv('CANOVIA_LEARNING_LOCK_DISPOSABLE_CI') === '1'
            && ($cfg['host'] ?? null) === '127.0.0.1'
            && (int) ($cfg['port'] ?? 0) === ($driver === 'mysql' ? 3306 : 5432)
            && ($cfg['database'] ?? null) === 'canovia_learning_watch_lock_ci'
            && ($cfg['username'] ?? null) === 'canovia_ci';
        $memory = $driver === 'sqlite' && ($cfg['database'] ?? null) === ':memory:';
        if (getenv('CI') !== 'true'
            || config('app.env') !== 'testing'
            || (string) getenv('DB_URL') !== ''
            || (! $real && ! $memory)) {
            throw new RuntimeException('V020_DISPOSABLE_CI_TARGET_REQUIRED');
        }
    }

    public function test_bounded_synthetic_capture_cost_and_source_correctness_without_thresholds(): void
    {
        $this->assertDisposableTarget();
        $owner = User::factory()->create();
        $this->ownerId = (int) $owner->id;
        $taskPlan = $this->plan($owner);
        $practicePlan = $this->plan($owner);
        $task = $this->addTasks($practicePlan, 0, 1)[0];

        $collector = new LearningContextCaptureDiagnostics;
        app()->instance(LearningContextCaptureDiagnostics::class, $collector);
        $capture = app(LearningContextSourceCaptureService::class);
        $previous = null;

        // Orthogonal curves: task count changes while practice stays zero.
        foreach ([0, 8, 32, 128, 256] as $count) {
            $already = $count === 0 ? 0 : match ($count) {
                8 => 0, 32 => 8, 128 => 32, 256 => 128,
            };
            if ($count > $already) {
                $this->addTasks($taskPlan, $already, $count);
            }
            $snapshot = $this->profile(
                $capture, $collector, $owner, $taskPlan,
                'tasks', $count, $count, 0, $previous,
            );
            $previous = $snapshot['source_revision'];
        }

        // A source row beyond the v0.7 cap must fail closed, not be timed
        // as a successful incomplete projection.
        $this->addTasks($taskPlan, 256, 257);
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        self::assertNull($capture->capture($owner, $taskPlan->id));
        self::assertFalse($capture->isCurrent($owner, $taskPlan->id, $previous));
        // Both capture() and isCurrent() invoke the bounded scanner.
        self::assertSame(2, $collector->snapshot()['outcomes']['task_cap'] ?? null);

        // Orthogonal practice curve: one stable Task, increasing historical
        // applied rows; the visible sample stays capped at eight.
        $previous = null;
        foreach ([0, 8, 128, 512] as $count) {
            $already = match ($count) {
                0 => 0, 8 => 0, 128 => 8, 512 => 128,
            };
            if ($count > $already) {
                $this->addPractice($owner, $practicePlan, $task, $already, $count);
            }
            $snapshot = $this->profile(
                $capture, $collector, $owner, $practicePlan,
                'practice', $count, 1, $count, $previous,
            );
            $previous = $snapshot['source_revision'];
        }

        $this->addPractice($owner, $practicePlan, $task, 512, 513);
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        self::assertNull($capture->capture($owner, $practicePlan->id));
        self::assertFalse($capture->isCurrent($owner, $practicePlan->id, $previous));
        self::assertSame(2, $collector->snapshot()['outcomes']['practice_cap'] ?? null);

        // 5 task + 4 practice profiles, each with 1 ON warmup + 21 ON
        // samples, plus 2 over-cap captures and 2 isCurrent() calls.
        $report = $collector->snapshot();
        self::assertSame(9 * (self::SAMPLES + 1) + 4, $report['total']);
        self::assertSame(9 * (self::SAMPLES + 1), $report['outcomes']['captured']);
        self::assertSame(2, $report['outcomes']['task_cap']);
        self::assertSame(2, $report['outcomes']['practice_cap']);
        self::assertFalse($report['persistent']);
        self::assertFalse($report['authoritative']);
        self::assertSame($report['total'], array_sum($report['duration']));
        self::assertSame($report['total'], array_sum($report['task_rows']));
        self::assertSame($report['total'], array_sum($report['practice_rows']));
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(
        LearningContextSourceCaptureService $capture,
        LearningContextCaptureDiagnostics $collector,
        User $owner,
        Plan $plan,
        string $dimension,
        int $profileRows,
        int $tasks,
        int $practice,
        ?string $priorRevision,
    ): array {
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => false]);
        $baseline = $capture->capture($owner, $plan->id);
        self::assertNotNull($baseline);
        self::assertSame(LearningContextSourceCaptureService::CAPTURE_SCHEMA, $baseline['schema']);
        self::assertSame($tasks > 8, $baseline['snapshot']['facts']['active_tasks']['truncated']);
        self::assertCount(min($tasks, 8), $baseline['snapshot']['facts']['active_tasks']['items']);
        self::assertSame($practice > 8, $baseline['snapshot']['facts']['recent_practice']['truncated']);
        self::assertSame(min($practice, 8),
            $baseline['snapshot']['facts']['recent_practice']['applied_attempts_considered']);
        if ($priorRevision !== null) {
            self::assertNotSame($priorRevision, $baseline['source_revision']);
        }

        $off = [];
        $on = [];
        config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => true]);
        self::assertSame($baseline, $capture->capture($owner, $plan->id)); // ON warmup
        for ($i = 0; $i < self::SAMPLES; ++$i) {
            // Counterbalance position bias: alternate ON-first/OFF-first.
            foreach (($i % 2 === 0 ? [false, true] : [true, false]) as $enabled) {
                config(['intelligence.learning_context_watch.capture_diagnostics_enabled' => $enabled]);
                $begin = hrtime(true);
                $observed = $capture->capture($owner, $plan->id);
                $elapsedNs = hrtime(true) - $begin;
                self::assertSame($baseline, $observed);
                self::assertGreaterThanOrEqual(0, $elapsedNs);
                if ($enabled) {
                    $on[] = $elapsedNs;
                } else {
                    $off[] = $elapsedNs;
                }
            }
        }
        self::assertCount(self::SAMPLES, $off);
        self::assertCount(self::SAMPLES, $on);

        foreach (['off' => $off, 'on' => $on] as $mode => $times) {
            sort($times, SORT_NUMERIC);
            // Nearest-rank order statistics, NOT product fleet percentiles.
            $p50 = $times[(int) ceil(self::SAMPLES * .50) - 1];
            $p95 = $times[(int) ceil(self::SAMPLES * .95) - 1];
            $max = $times[self::SAMPLES - 1];
            self::assertLessThanOrEqual($p95, $p50);
            self::assertLessThanOrEqual($max, $p95);
            // STDERR bypasses PHPUnit's successful-test output buffer;
            // only fixed labels and synthetic-size summaries are emitted.
            fwrite(STDERR, sprintf(
                "V020_SYNTHETIC_CAPTURE engine=%s dimension=%s rows=%d mode=%s samples=%d p50_ms=%.3f p95_ms=%.3f max_ms=%.3f\n",
                DB::getDriverName(), $dimension, $profileRows, $mode,
                self::SAMPLES, $p50 / 1_000_000, $p95 / 1_000_000, $max / 1_000_000,
            ));
        }
        return $baseline;
    }

    private function plan(User $owner): Plan
    {
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V020_SYNTHETIC_STUDY_ONLY',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(10),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $this->planIds[] = (int) $plan->id;
        return $plan;
    }

    /** @return list<Task> */
    private function addTasks(Plan $plan, int $from, int $until): array
    {
        $this->assertDisposableTarget();
        $new = [];
        $now = now();
        for ($i = $from; $i < $until; ++$i) {
            $new[] = [
                'plan_id' => $plan->id,
                'title' => 'V020_SYNTHETIC_TASK',
                'status' => 'todo',
                'progress_percent' => 20,
                'priority' => 1,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($new, 40) as $batch) {
            Task::query()->insert($batch);
        }
        // Only used for the single practice-plan Task.
        return $until - $from === 1 && $from === 0
            ? Task::query()->where('plan_id', $plan->id)->orderByDesc('id')->limit(1)->get()->all()
            : [];
    }

    private function addPractice(User $owner, Plan $plan, Task $task, int $from, int $until): void
    {
        $this->assertDisposableTarget();
        $now = now();
        for ($begin = $from; $begin < $until; $begin += 40) {
            $batch = [];
            for ($i = $begin; $i < min($until, $begin + 40); ++$i) {
                $batch[] = [
                    'user_id' => $owner->id,
                    'plan_id' => $plan->id,
                    'task_id' => $task->id,
                    'request_hash' => hash('sha256', 'v020-disposable-'.$plan->id.'-'.$i),
                    'exercise_title' => 'synthetic',
                    'questions' => '[]',
                    'answers' => '[]',
                    'assessment' => '{"question_feedback":[{"correctness":"incorrect","weakness_topics":["synthetic"]}]}',
                    'weaknesses' => '[]',
                    'score_percent' => 60,
                    'recommended_task_progress_percent' => 30,
                    'applied_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            StudyPracticeAttempt::query()->insert($batch);
        }
    }
}
