<?php

namespace Tests\Feature;

use App\Models\LearningContextChangeRecord;
use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextWatchPersistenceService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Actual two-physical-connection contention against CI's loopback disposable
 * PostgreSQL/MySQL DB only. No RefreshDatabase transaction wrapper.
 * Fail closed before using ANY non-CI database.
 */
final class LearningContextPlanLockContentionTest extends TestCase
{
    private const DATABASE = 'canovia_learning_watch_lock_ci';

    protected function setUp(): void
    {
        parent::setUp();
        $driver = (string) config('database.default');
        $cfg = config('database.connections.'.$driver);
        if (getenv('CANOVIA_LEARNING_LOCK_DISPOSABLE_CI') !== '1'
            || config('app.env') !== 'testing'
            || ! in_array($driver, ['mysql', 'pgsql'], true)
            || ! is_array($cfg)
            || ($cfg['host'] ?? null) !== '127.0.0.1'
            || ($cfg['database'] ?? null) !== self::DATABASE) {
            $this->markTestSkipped('Only the pinned disposable Learning Plan-lock CI database is supported.');
        }
        $this->withoutVite();
        config([
            'database.connections.learning_lock_contender' => $cfg,
            'intelligence.learning_context_watch.persistence_enabled' => true,
        ]);
        DB::purge('learning_lock_contender');
    }

    protected function tearDown(): void
    {
        DB::disconnect('learning_lock_contender');
        parent::tearDown();
    }

    public function test_task_edit_waits_on_plan_before_mutating_a_task(): void
    {
        [$user, $plan, $task] = $this->fixture();
        $this->actingAs($user)->withoutExceptionHandling();
        $contender = DB::connection('learning_lock_contender');

        $this->withContendedPlan($contender, $plan, function () use ($task): void {
            $this->expectLockDenied(function () use ($task): void {
                $this->put(route('tasks.update', $task), $this->taskPayload('CHANGED_WHILE_LOCKED'));
            });
        });

        self::assertSame('doing', $task->fresh()->status);
        self::assertSame('LOCKED_SOURCE_TASK', $task->fresh()->title);

        $this->put(route('tasks.update', $task), $this->taskPayload('AFTER_UNLOCK'))
            ->assertRedirect(route('plans.show', $plan));
        self::assertSame('AFTER_UNLOCK', $task->fresh()->title);
    }

    public function test_task_delete_waits_on_plan_and_cannot_half_delete(): void
    {
        [$user, $plan, $task] = $this->fixture();
        $this->actingAs($user)->withoutExceptionHandling();
        $contender = DB::connection('learning_lock_contender');

        $this->withContendedPlan($contender, $plan, function () use ($task): void {
            $this->expectLockDenied(function () use ($task): void {
                $this->delete(route('tasks.destroy', $task));
            });
        });

        self::assertTrue(Task::query()->whereKey($task->id)->exists());
        $this->delete(route('tasks.destroy', $task))
            ->assertRedirect(route('plans.show', $plan));
        self::assertFalse(Task::query()->whereKey($task->id)->exists());
    }

    public function test_applied_study_result_waits_on_plan_before_locking_attempt(): void
    {
        [$user, $plan, $task] = $this->fixture();
        $attempt = StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'request_hash' => hash('sha256', 'learning-lock-ci-'.$plan->id),
            'exercise_title' => 'SYNTHETIC_STUDY_ATTEMPT',
            'questions' => [['id' => 'q1']],
            'answers' => [['answer' => 'A']],
            'assessment' => ['score_percent' => 70],
            'score_percent' => 70,
            'strengths' => ['DNS'],
            'weaknesses' => ['MTU'],
            'recommended_task_progress_percent' => 60,
            'evidence_summary' => 'Safe synthetic context',
            'next_action' => 'Review DNS',
        ]);
        $this->actingAs($user)->withoutExceptionHandling();
        $payload = ['attempt_id' => $attempt->id, 'request_hash' => $attempt->request_hash];
        $contender = DB::connection('learning_lock_contender');

        $this->withContendedPlan($contender, $plan, function () use ($plan, $task, $payload): void {
            $this->expectLockDenied(function () use ($plan, $task, $payload): void {
                $this->post(route('plans.tasks.study_practice.apply', [$plan, $task]), $payload);
            });
        });

        self::assertNull($attempt->fresh()->applied_at);
        self::assertSame(15, $task->fresh()->progress_percent);
        $this->post(route('plans.tasks.study_practice.apply', [$plan, $task]), $payload)
            ->assertRedirect();
        self::assertNotNull($attempt->fresh()->applied_at);
        self::assertGreaterThanOrEqual(15, (int) $task->fresh()->progress_percent);
    }

    public function test_watch_advance_waits_for_same_plan_and_never_records_partial_status(): void
    {
        [$user, $plan, $task] = $this->fixture();
        $this->actingAs($user);
        $watch = app(LearningContextWatchPersistenceService::class);
        self::assertTrue($watch->optIn($user, $plan->id));
        $task->update(['status' => 'paused']);
        $contender = DB::connection('learning_lock_contender');

        $this->withContendedPlan($contender, $plan, function () use ($watch, $user, $plan): void {
            $this->expectLockDenied(function () use ($watch, $user, $plan): void {
                $watch->advance($user, $plan->id);
            });
        });

        self::assertSame(0, LearningContextChangeRecord::query()->count());
        self::assertSame('doing', LearningContextWatchState::query()->sole()
            ->encrypted_baseline['snapshot']['facts']['active_tasks']['items'][0]['status']);
        self::assertSame('recorded_review_candidate', $watch->advance($user, $plan->id)['status']);
        self::assertSame(1, LearningContextChangeRecord::query()->count());
    }

    /** @return array{User,Plan,Task} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_LOCK_PLAN',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(10),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'LOCKED_SOURCE_TASK',
            'description' => 'PRIVATE_NOT_SHARED',
            'status' => 'doing', 'progress_percent' => 15,
            'priority' => 1, 'sort_order' => 1,
        ]);

        return [$owner, $plan, $task];
    }

    /** @return array<string,int|string> */
    private function taskPayload(string $title): array
    {
        return [
            'title' => $title, 'description' => 'PRIVATE_NOT_SHARED',
            'estimated_minutes' => 30, 'remaining_minutes' => 20,
            'progress_percent' => 15, 'status' => 'doing',
            'priority' => 1, 'activation_cost' => 1,
        ];
    }

    private function withContendedPlan(ConnectionInterface $contender, Plan $plan, callable $run): void
    {
        $contender->beginTransaction();
        try {
            $contender->table('plans')->where('id', $plan->id)->lockForUpdate()->firstOrFail();
            // Use a short bounded DB wait; never allow test timeouts to
            // hold locks indefinitely if the Plan-first fence regresses.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET lock_timeout = '250ms'");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            }

            $run();
        } finally {
            if ($contender->transactionLevel() > 0) {
                $contender->rollBack();
            }
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("SET lock_timeout = '0'");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            }
        }
    }

    private function expectLockDenied(callable $run): void
    {
        try {
            $run();
            self::fail('Concurrent request bypassed the locked Plan row.');
        } catch (QueryException $e) {
            $code = (string) ($e->errorInfo[0] ?? '');
            // PostgreSQL: lock_not_available; MySQL: lock wait timeout.
            self::assertContains($code, ['55P03', 'HY000'],
                'Unexpected SQLSTATE during independent Plan contention: '.$code);
        }
    }
}
