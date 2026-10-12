<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionMutationApplyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use Tests\TestCase;

/**
 * Independent disposable PostgreSQL sessions; exercises the actual human
 * Companion apply path and its target/source lock sequence.
 */
final class CompanionApprovalPostgresLockOrderG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL G3 CI service.');
        }

        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_plan_lock_contention_never_locks_candidate_or_partially_applies(): void
    {
        [$user, $plan, $task, $candidate] = $this->scenario();
        $rival = $this->rivalConnection();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $rival->beginTransaction();
        try {
            $statement = $rival->prepare('SELECT id FROM plans WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $plan->id]);
            $statement->fetchAll();

            // We must fail closed while a different worker holds Plan. A
            // Candidate-first transaction would hold Candidate and create a
            // Candidate->Plan / Plan->Candidate deadlock opportunity.
            DB::statement("SET lock_timeout = '250ms'");
            try {
                $this->apply($user, $candidate);
                $this->fail('Applying a Candidate must not bypass the Plan lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            } finally {
                DB::statement("SET lock_timeout = '0'");
            }

            $this->assertFalse(collect($queries)->contains(
                fn (string $sql) => str_contains($sql, 'from "companion_mutation_candidates"')
            ), 'Candidate FOR UPDATE must not be obtained before Plan.');

            // Because worker A never obtained the candidate row lock, B can
            // perform a competing human dismissal while owning the Plan.
            $dismiss = $rival->prepare(
                "UPDATE companion_mutation_candidates SET status = 'dismissed' WHERE id = :id"
            );
            $dismiss->execute(['id' => $candidate->id]);
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        $this->assertSame('dismissed', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame('Previous next step', $task->fresh()->next_action_note);

        try {
            $this->apply($user, $candidate->fresh());
            $this->fail('A dismissed Candidate must not be replayed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }
        $this->assertSame('Previous next step', $task->fresh()->next_action_note);
    }

    public function test_successful_approval_locks_plan_then_task_then_candidate_and_is_idempotent(): void
    {
        [$user, $plan, $task, $candidate] = $this->scenario();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $requestId = (string) Str::uuid();
        $result = $this->apply($user, $candidate, $requestId);
        $this->assertFalse($result['already_applied']);
        $this->assertSame('applied', $candidate->fresh()->status);
        $this->assertSame($requestId, $candidate->fresh()->apply_request_id);
        $this->assertSame('Confirmed by human', $task->fresh()->next_action_note);

        $targetTables = ['plans', 'tasks', 'companion_mutation_candidates'];
        $positions = [];
        foreach ($targetTables as $table) {
            $position = collect($queries)->search(
                fn (string $sql) => str_contains($sql, 'from "'.$table.'"')
            );
            $this->assertNotFalse($position, 'Missing row lock for '.$table);
            $positions[] = $position;
        }
        $this->assertLessThan($positions[1], $positions[0]);
        $this->assertLessThan($positions[2], $positions[1]);

        $again = $this->apply($user, $candidate->fresh(), $requestId);
        $this->assertTrue($again['already_applied']);
        $this->assertSame('Confirmed by human', $task->fresh()->next_action_note);
    }

    public function test_candidate_retargeted_after_route_binding_is_not_approved(): void
    {
        [$user, $plan, $task, $candidate] = $this->scenario();
        $oldBinding = $candidate->fresh();
        $candidate->update(['task_id' => null]);

        try {
            $this->apply($user, $oldBinding);
            $this->fail('A stale route-bound target must not silently retarget.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }

        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('Previous next step', $task->fresh()->next_action_note);
    }

    private function apply(User $user, CompanionMutationCandidate $candidate, ?string $id = null): array
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        return app(CompanionMutationApplyService::class)->apply(
            $request, $candidate, $id ?? (string) Str::uuid(),
        );
    }

    private function rivalConnection(): PDO
    {
        $db = config('database.connections.pgsql');

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s',
                (string) ($db['host'] ?? '127.0.0.1'),
                (int) ($db['port'] ?? 5432),
                (string) ($db['database'] ?? ''),
            ),
            (string) ($db['username'] ?? ''),
            (string) ($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 approval concurrency',
            'description' => 'DB lock order',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Review source before applying',
            'description' => 'Human approval',
            'status' => 'doing',
            'progress_percent' => 30,
            'estimated_minutes' => 50,
            'remaining_minutes' => 35,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
            'next_action_note' => 'Previous next step',
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);
        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => 'Consider a human-confirmed action',
        ]);
        $candidate = CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task',
            'status' => 'pending',
            'title' => 'Human confirmation',
            'summary' => 'Do not infer progress',
            'payload' => ['next_action_note' => 'Confirmed by human'],
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []],
            ],
        ]);

        return [$user, $plan, $task, $candidate];
    }
}
