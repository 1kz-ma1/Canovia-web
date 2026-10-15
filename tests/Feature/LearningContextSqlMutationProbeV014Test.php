<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\McpStudyResultProposal;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\LearningContextSourceCaptureService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\LearningContextSqlMutationProbe as Probe;
use Tests\TestCase;

/**
 * Deliberately separate from the source-epoch migration and RefreshDatabase.
 * The disposable GitHub Actions MySQL/PG database is migrated before this
 * test starts. Probe DDL is installed and removed only inside this class.
 */
final class LearningContextSqlMutationProbeV014Test extends TestCase
{
    private bool $installed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Probe::assertDisposableDatabase();
        config(['intelligence.learning_context_watch.epoch_shadow_enabled' => false]);
        Probe::install();
        $this->installed = true;
    }

    protected function tearDown(): void
    {
        DB::disconnect('learning_probe_rival');
        if ($this->installed) {
            Probe::uninstall();
        }
        parent::tearDown();
    }

    private function fixture(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'DISPOSABLE_STUDY_SOURCE',
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
            'title' => 'DISPOSABLE_DNS_TASK',
            'status' => 'doing',
            'progress_percent' => 25,
            'priority' => 1,
            'sort_order' => 1,
        ]);

        return [$owner, $plan, $task];
    }

    private function attempt(User $owner, Plan $plan, Task $task, bool $applied): StudyPracticeAttempt
    {
        return StudyPracticeAttempt::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'request_hash' => bin2hex(random_bytes(32)),
            'exercise_title' => 'SECRET_EXERCISE_NEVER_JOURNALED',
            'questions' => [['text' => 'SECRET_QUESTION']],
            'answers' => [['value' => 'SECRET_ANSWER']],
            'assessment' => ['rationale' => 'SECRET_PRIVATE_GRADE'],
            'score_percent' => 60,
            'weaknesses' => ['DNS'],
            'recommended_task_progress_percent' => 40,
            'applied_at' => $applied ? now() : null,
        ]);
    }

    private function source(User $owner, Plan $plan): array
    {
        return app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id);
    }

    public function test_direct_sql_old_practice_correction_and_delete_invalidate_source_and_advance_probe(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $old = null;
        for ($i = 0; $i < 11; ++$i) {
            $attempt = $this->attempt($owner, $plan, $task, true);
            DB::table('study_practice_attempts')->where('id', $attempt->id)
                ->update(['applied_at' => now()->subDays(25 - $i)]);
            $old ??= $attempt;
        }
        $before = $this->source($owner, $plan);
        $base = Probe::inspect($plan->id)['epoch'];

        DB::table('study_practice_attempts')->where('id', $old->id)
            ->update(['score_percent' => 12]);
        $corrected = $this->source($owner, $plan);
        $this->assertNotSame($before['source_revision'], $corrected['source_revision']);
        $this->assertSame($before['snapshot'], $corrected['snapshot']);
        $this->assertSame($base + 1, Probe::inspect($plan->id)['epoch']);

        DB::table('study_practice_attempts')->where('id', $old->id)->delete();
        $deleted = $this->source($owner, $plan);
        $this->assertNotSame($corrected['source_revision'], $deleted['source_revision']);
        $this->assertSame($base + 2, Probe::inspect($plan->id)['epoch']);
        $this->assertFalse(Probe::inspect($plan->id)['authoritative']);
        $this->assertFalse(app(LearningContextSourceCaptureService::class)
            ->isCurrent($owner, $plan->id, $before['source_revision']));
    }

    public function test_probe_and_source_revert_together_on_sql_rollback(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $attempt = $this->attempt($owner, $plan, $task, true);
        $before = $this->source($owner, $plan);
        $base = Probe::inspect($plan->id)['epoch'];
        try {
            DB::transaction(function () use ($attempt): void {
                DB::table('study_practice_attempts')
                    ->where('id', $attempt->id)->update(['score_percent' => 17]);
                throw new RuntimeException('disposable synthetic rollback');
            });
            $this->fail('Expected a rollback exception.');
        } catch (RuntimeException $e) {
            $this->assertSame('disposable synthetic rollback', $e->getMessage());
        }

        $this->assertSame(60, (int) $attempt->fresh()->score_percent);
        $this->assertSame($base, Probe::inspect($plan->id)['epoch']);
        $this->assertSame($before['source_revision'],
            $this->source($owner, $plan)['source_revision']);
    }

    public function test_task_reparent_bumps_both_plan_epochs_and_a_sql_delete_is_detected(): void
    {
        [$owner, $oldPlan, $task] = $this->fixture();
        [, $newPlan] = $this->fixture($owner);
        $baseOld = Probe::inspect($oldPlan->id)['epoch'];
        $baseNew = Probe::inspect($newPlan->id)['epoch'];
        $previousOld = $this->source($owner, $oldPlan);
        $previousNew = $this->source($owner, $newPlan);

        DB::table('tasks')->where('id', $task->id)
            ->update(['plan_id' => $newPlan->id]);
        $this->assertSame($baseOld + 1, Probe::inspect($oldPlan->id)['epoch']);
        $this->assertSame($baseNew + 1, Probe::inspect($newPlan->id)['epoch']);
        $this->assertNotSame($previousOld['source_revision'],
            $this->source($owner, $oldPlan)['source_revision']);
        $this->assertNotSame($previousNew['source_revision'],
            $this->source($owner, $newPlan)['source_revision']);

        DB::table('tasks')->where('id', $task->id)->delete();
        $this->assertSame($baseNew + 2, Probe::inspect($newPlan->id)['epoch']);
        $this->assertSame($baseOld + 1, Probe::inspect($oldPlan->id)['epoch']);
    }

    public function test_overcount_is_explicit_and_cannot_replace_the_historical_hash(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $unapplied = $this->attempt($owner, $plan, $task, false);
        $sourceBefore = $this->source($owner, $plan);
        $base = Probe::inspect($plan->id)['epoch'];

        // Neither unapplied attempts nor Task.description enter the v0.7
        // authoritative fingerprint. Every DML operation increments the
        // deliberately overinclusive probe: no "learning change" is implied.
        DB::table('study_practice_attempts')->where('id', $unapplied->id)
            ->update(['score_percent' => 15]);
        DB::table('tasks')->where('id', $task->id)
            ->update(['description' => 'PRIVATE_NOT_PERSISTED_IN_PROBE']);
        $this->assertSame($base + 2, Probe::inspect($plan->id)['epoch']);
        $this->assertSame($sourceBefore['source_revision'],
            $this->source($owner, $plan)['source_revision']);

        $row = DB::table('learning_context_probe_epochs')
            ->where('plan_id', $plan->id)->first();
        $this->assertStringNotContainsString('PRIVATE_NOT_PERSISTED_IN_PROBE',
            json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SECRET_EXERCISE_NEVER_JOURNALED',
            json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function test_direct_sql_plan_permission_transition_increments_probe_but_revokes_capture(): void
    {
        [$owner, $plan] = $this->fixture();
        $before = $this->source($owner, $plan);
        $base = Probe::inspect($plan->id)['epoch'];
        DB::table('plans')->where('id', $plan->id)
            ->update(['is_public' => true]);
        $this->assertSame($base + 1, Probe::inspect($plan->id)['epoch']);
        $this->assertNull(app(LearningContextSourceCaptureService::class)
            ->capture($owner, $plan->id));
        $this->assertFalse(app(LearningContextSourceCaptureService::class)
            ->isCurrent($owner, $plan->id, $before['source_revision']));
    }

    public function test_database_ledger_lock_blocks_direct_task_write_and_rolls_back(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $base = Probe::inspect($plan->id)['epoch'];
        $cfg = config('database.connections.'.config('database.default'));
        config(['database.connections.learning_probe_rival' => $cfg]);
        DB::purge('learning_probe_rival');
        $rival = DB::connection('learning_probe_rival');
        $rival->beginTransaction();
        try {
            $rival->table('learning_context_probe_epochs')
                ->where('plan_id', $plan->id)
                ->lockForUpdate()->firstOrFail();

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET lock_timeout = '250ms'");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            }

            try {
                DB::table('tasks')->where('id', $task->id)
                    ->update(['title' => 'MUST_ROLL_BACK_WHILE_LEDGER_LOCKED']);
                $this->fail('Direct SQL write must wait for the probe ledger.');
            } catch (QueryException $e) {
                $code = (string) ($e->errorInfo[0] ?? '');
                $this->assertContains($code, ['55P03', 'HY000']);
            }
        } finally {
            $rival->rollBack();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET lock_timeout = '0'");
            } else {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            }
        }

        $this->assertSame('DISPOSABLE_DNS_TASK', $task->fresh()->title);
        $this->assertSame($base, Probe::inspect($plan->id)['epoch']);

        DB::table('tasks')->where('id', $task->id)
            ->update(['title' => 'AFTER_LEDGER_UNLOCK']);
        $this->assertSame('AFTER_LEDGER_UNLOCK', $task->fresh()->title);
        $this->assertSame($base + 1, Probe::inspect($plan->id)['epoch']);
    }

    public function test_approved_external_result_corrected_then_revoked_via_sql_bumps_probe(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id, 'provider_key' => 'chatgpt',
            'identity_fingerprint' => hash('sha256', 'probe-source-fixture'),
            'status' => 'linked', 'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id, 'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => hash('sha256', 'probe-client-fixture'),
            'scope' => 'study_progress', 'status' => 'active',
            'consented_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $external = McpStudyResultProposal::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'grant_id' => $grant->id,
            'idempotency_key' => str_repeat('a', 64),
            'payload_hash' => str_repeat('b', 64),
            'question_count' => 10, 'correct_count' => 7,
            'weakness_topics' => ['DNS'],
            'status' => McpStudyResultProposal::APPROVED,
            'decided_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        $before = $this->source($owner, $plan);
        $base = Probe::inspect($plan->id)['epoch'];

        DB::table('mcp_study_result_proposals')->where('id', $external->id)
            ->update(['correct_count' => 4]);
        $this->assertNotSame($before['source_revision'],
            $this->source($owner, $plan)['source_revision']);

        DB::table('mcp_study_result_proposals')->where('id', $external->id)
            ->update(['status' => McpStudyResultProposal::REJECTED]);
        $this->assertSame($base + 2, Probe::inspect($plan->id)['epoch']);
        $this->assertFalse(app(LearningContextSourceCaptureService::class)
            ->isCurrent($owner, $plan->id, $before['source_revision']));

        DB::table('mcp_study_result_proposals')->where('id', $external->id)->delete();
        $this->assertSame($base + 3, Probe::inspect($plan->id)['epoch']);
    }
}
