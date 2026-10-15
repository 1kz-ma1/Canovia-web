<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LearningContextSqlMutationProbe as Probe;
use Tests\TestCase;

/**
 * CI-only follow-up to the deliberately overinclusive v0.14 probe.
 * No migration, production DDL, automatic Watch, notification or expiry.
 */
final class LearningContextSqlMutationProbeV015Test extends TestCase
{
    private bool $installed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Probe::assertDisposableDatabase();
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('learning_probe_rival');
        if ($this->installed) {
            Probe::uninstall();
        }
        parent::tearDown();
    }

    private function start(bool $filtered): void
    {
        Probe::install($filtered);
        $this->installed = true;
    }

    private function stop(): void
    {
        if ($this->installed) {
            Probe::uninstall();
            $this->installed = false;
        }
    }

    private function fixture(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'FILTERED_DISPOSABLE_STUDY',
            'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(15),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'FILTERED_DNS',
            'description' => 'BASELINE', 'status' => 'doing',
            'progress_percent' => 25, 'sort_order' => 1, 'priority' => 1,
        ]);

        return [$owner, $plan, $task];
    }

    private function attempt(User $owner, Plan $plan, Task $task, bool $applied): StudyPracticeAttempt
    {
        return StudyPracticeAttempt::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'request_hash' => bin2hex(random_bytes(32)),
            'exercise_title' => 'SECRET_DO_NOT_JOURNAL',
            'questions' => [], 'answers' => [], 'assessment' => [],
            'score_percent' => 61, 'weaknesses' => [],
            'recommended_task_progress_percent' => 30,
            'applied_at' => $applied ? now() : null,
        ]);
    }

    private function revision(User $owner, Plan $plan): string
    {
        $snapshot = app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id);
        $this->assertNotNull($snapshot);

        return $snapshot['source_revision'];
    }

    public function test_filtered_probe_ignores_unapplied_and_nonhashed_task_updates_but_catches_real_corrections(): void
    {
        $this->start(true);
        [$owner, $plan, $task] = $this->fixture();
        $base = Probe::inspect($plan->id)['epoch'];
        $hash = $this->revision($owner, $plan);

        DB::table('tasks')->where('id', $task->id)->update([
            'description' => 'UNHASHED_BUT_PRIVATE',
        ]);
        $unapplied = $this->attempt($owner, $plan, $task, false);
        DB::table('study_practice_attempts')->where('id', $unapplied->id)
            ->update(['score_percent' => 15]);
        DB::table('study_practice_attempts')->where('id', $unapplied->id)->delete();

        $this->assertSame($base, Probe::inspect($plan->id)['epoch']);
        $this->assertSame($hash, $this->revision($owner, $plan));

        $applied = $this->attempt($owner, $plan, $task, true);
        $afterApply = Probe::inspect($plan->id)['epoch'];
        $this->assertSame($base + 1, $afterApply);
        $revisionAfter = $this->revision($owner, $plan);
        $this->assertNotSame($hash, $revisionAfter);

        DB::table('study_practice_attempts')->where('id', $applied->id)
            ->update(['score_percent' => 12]);
        $this->assertSame($afterApply + 1, Probe::inspect($plan->id)['epoch']);
        $this->assertNotSame($revisionAfter, $this->revision($owner, $plan));

        DB::table('tasks')->where('id', $task->id)->update(['progress_percent' => 80]);
        $this->assertSame($afterApply + 2, Probe::inspect($plan->id)['epoch']);

        // Same-value SQL Task UPDATE is conservatively ignored: it cannot
        // change v0.7's current hashed columns or updated_at.
        DB::table('tasks')->where('id', $task->id)->update(['title' => $task->title]);
        $this->assertSame($afterApply + 2, Probe::inspect($plan->id)['epoch']);
        $this->assertFalse(Probe::inspect($plan->id)['authoritative']);
    }

    public function test_filtered_probe_inactive_task_and_status_boundary_match_source_membership(): void
    {
        $this->start(true);
        [$owner, $plan, $task] = $this->fixture();
        $base = Probe::inspect($plan->id)['epoch'];

        $id = DB::table('tasks')->insertGetId([
            'plan_id' => $plan->id, 'title' => 'INELIGIBLE_DRAFT_TASK',
            'status' => 'done', 'progress_percent' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tasks')->where('id', $id)
            ->update(['title' => 'STILL_DONE']);
        $this->assertSame($base, Probe::inspect($plan->id)['epoch']);
        $initial = $this->revision($owner, $plan);

        DB::table('tasks')->where('id', $id)->update(['status' => 'todo']);
        $this->assertSame($base + 1, Probe::inspect($plan->id)['epoch']);
        $this->assertNotSame($initial, $this->revision($owner, $plan));

        DB::table('tasks')->where('id', $id)->update(['status' => 'done']);
        $this->assertSame($base + 2, Probe::inspect($plan->id)['epoch']);
        DB::table('tasks')->where('id', $id)->delete();
        $this->assertSame($base + 2, Probe::inspect($plan->id)['epoch']);
    }

    public function test_observational_fixed_workload_reports_plain_vs_naive_vs_filtered_cost_without_performance_gate(): void
    {
        // Time only the 72 direct SQL UPDATE statements, never DDL or factory
        // setup. CI host variance makes absolute millisecond thresholds unsound.
        [$owner, $plan, $task] = $this->fixture();
        $n = 72;
        $bench = function (string $label) use ($task, $n): float {
            $start = hrtime(true);
            for ($i = 0; $i < $n; ++$i) {
                DB::table('tasks')->where('id', $task->id)
                    ->update(['description' => "$label:$i"]);
            }

            return round((hrtime(true) - $start) / 1_000_000, 3);
        };
        $baseline = $bench('baseline');
        $this->start(false);
        $naive = $bench('unfiltered');
        $this->assertSame($n, Probe::inspect($plan->id)['epoch']);
        $this->stop();
        $this->start(true);
        $filtered = $bench('filtered');
        $this->assertNull(Probe::inspect($plan->id));
        $this->assertNotNull($this->revision($owner, $plan));

        fwrite(STDERR, sprintf(
            "LEARNING_PROBE_DML_PERF driver=%s iterations=%d baseline_ms=%.3f unfiltered_ms=%.3f filtered_ms=%.3f (illustrative, not production benchmark)\n",
            DB::getDriverName(), $n, $baseline, $naive, $filtered,
        ));
        $this->assertGreaterThanOrEqual(0.0, $baseline);
        $this->assertGreaterThanOrEqual(0.0, $filtered);
    }

    public function test_filtered_probe_real_concurrent_lock_inversion_is_demonstrable_without_forcing_deadlock(): void
    {
        $this->start(true);
        [$owner, $plan, $task] = $this->fixture();
        $base = Probe::inspect($plan->id)['epoch'];
        $cfg = config('database.connections.'.config('database.default'));
        config(['database.connections.learning_probe_rival' => $cfg]);
        DB::purge('learning_probe_rival');
        $primary = DB::connection();
        $rival = DB::connection('learning_probe_rival');

        // Crossed lock order demonstrates the unsafe AB/BA possibility:
        // app path may hold ledger before Task while raw writer locks Task
        // then requests ledger in its AFTER UPDATE trigger. NOWAIT avoids
        // intentionally inducing a deadlock and CI nondeterminism.
        $primary->beginTransaction();
        $rival->beginTransaction();
        try {
            $primary->table('learning_context_probe_epochs')
                ->where('plan_id', $plan->id)->lockForUpdate()->firstOrFail();
            $rival->table('tasks')->where('id', $task->id)
                ->lockForUpdate()->firstOrFail();

            $this->assertNowaitDenied($primary, 'tasks', $task->id);
            $this->assertNowaitDenied($rival, 'learning_context_probe_epochs', $plan->id);
        } finally {
            $rival->rollBack();
            $primary->rollBack();
        }
        $this->assertSame($base, Probe::inspect($plan->id)['epoch']);
        $this->assertSame('FILTERED_DNS', $task->fresh()->title);
        $this->assertNotNull($this->revision($owner, $plan));
    }

    private function assertNowaitDenied(ConnectionInterface $connection, string $table, int $id): void
    {
        try {
            // A nested transaction is a SAVEPOINT on both engines. Required:
            // PostgreSQL otherwise aborts the entire outer transaction on
            // expected 55P03 lock-not-available.
            $connection->transaction(function () use ($connection, $table, $id): void {
                $connection->table($table)
                    ->where($table === 'tasks' ? 'id' : 'plan_id', $id)
                    ->lock('FOR UPDATE NOWAIT')->firstOrFail();
            });
            $this->fail("Expected NOWAIT contention on $table.");
        } catch (QueryException $e) {
            $this->assertContains((string) ($e->errorInfo[0] ?? ''), [
                '55P03', 'HY000',
            ]);
        }
    }
}
