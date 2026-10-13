<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionEntitlementCommitGuard;
use App\Services\CompanionMutationApplyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Two physically independent PostgreSQL sessions against disposable PG16.
 * Product-grant row serialization is tested at the real commit boundary.
 */
final class CompanionGrantRevocationPostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_grant_lock_prevents_concurrent_expiration_until_authorized_commit(): void
    {
        [$user, , , , $grant] = $this->scenario();
        $rival = $this->rival();

        DB::beginTransaction();
        try {
            $this->assertTrue(app(CompanionEntitlementCommitGuard::class)->allowed($user));

            $rival->beginTransaction();
            $rival->exec("SET LOCAL lock_timeout = '200ms'");
            try {
                $this->revoke($rival, $grant->id);
                $this->fail('Grant UPDATE must not pass the commit guard lock.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                $rival->rollBack();
            }

            $this->assertNull($grant->fresh()->expires_at);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->revoke($rival, $grant->id);
        $this->assertFalse(DB::transaction(fn () =>
            app(CompanionEntitlementCommitGuard::class)->allowed($user)
        ));
    }

    public function test_revoker_first_blocks_guard_and_committed_revoke_denies(): void
    {
        [$user, , , , $grant] = $this->scenario();
        $rival = $this->rival();

        $rival->beginTransaction();
        try {
            $this->revoke($rival, $grant->id);
            DB::statement("SET lock_timeout = '250ms'");
            try {
                DB::transaction(fn () => app(CompanionEntitlementCommitGuard::class)->allowed($user));
                $this->fail('Entitlement commit must not pass an uncommitted revocation.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            } finally {
                DB::statement("SET lock_timeout = '0'");
            }
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        $this->assertFalse(DB::transaction(fn () =>
            app(CompanionEntitlementCommitGuard::class)->allowed($user)
        ));
    }

    public function test_deleted_grant_prevents_human_approved_task_write(): void
    {
        [$user, $plan, $task, $candidate, $grant] = $this->scenario();
        $rival = $this->rival();
        $rival->prepare('DELETE FROM user_product_grants WHERE id = :id')
            ->execute(['id' => $grant->id]);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        try {
            app(CompanionMutationApplyService::class)->apply($request, $candidate, (string) Str::uuid());
            $this->fail('Revoked grant must block applying an AI-proposed task edit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }

        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
        $this->assertSame('doing', $task->fresh()->status);
    }

    public function test_real_approval_retains_grant_lock_until_outer_commit(): void
    {
        [$user, , $task, $candidate, $grant] = $this->scenario();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $rival = $this->rival();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $queries[] = strtolower($query->sql);
            }
        });

        DB::transaction(function () use ($request, $candidate, $grant, $rival): void {
            $result = app(CompanionMutationApplyService::class)->apply(
                $request, $candidate, (string) Str::uuid(),
            );
            $this->assertFalse($result['already_applied']);

            $rival->beginTransaction();
            $rival->exec("SET LOCAL lock_timeout = '200ms'");
            try {
                $this->revoke($rival, $grant->id);
                $this->fail('The human apply must hold the grant lock until outer commit.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                $rival->rollBack();
            }
        });

        $this->assertSame('Human approved note', $task->fresh()->next_action_note);
        $this->assertSame('applied', $candidate->fresh()->status);
        $positions = [];
        foreach (['plans', 'tasks', 'companion_mutation_candidates', 'user_product_grants'] as $table) {
            $pos = collect($queries)->search(
                fn (string $sql) => str_contains($sql, 'from "'.$table.'"')
            );
            $this->assertNotFalse($pos, 'Required lock missing for '.$table);
            $positions[] = $pos;
        }
        // A subsequent membership fence adds its own FOR UPDATE between
        // Candidate and Grant. Verify relative order, not adjacent offsets.
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertCount(4, array_unique($positions));

        $this->revoke($rival, $grant->id);
        $this->assertNotNull($grant->fresh()->expires_at);
        $this->assertSame('Human approved note', $task->fresh()->next_action_note);
    }

    public function test_active_gift_fallback_survives_one_revoked_product_grant(): void
    {
        [$user, , , , $grant] = $this->scenario();
        $gift = UserProductGrant::query()->create([
            'user_id' => $user->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'gift', 'starts_at' => now()->subMinute(),
        ]);
        $grant->update(['expires_at' => now()->subMinute()]);

        $this->assertTrue(DB::transaction(fn () =>
            app(CompanionEntitlementCommitGuard::class)->allowed($user)
        ));

        $gift->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse(DB::transaction(fn () =>
            app(CompanionEntitlementCommitGuard::class)->allowed($user)
        ));
    }

    public function test_commit_guard_refuses_outside_transaction(): void
    {
        [$user] = $this->scenario();
        $this->expectException(LogicException::class);
        app(CompanionEntitlementCommitGuard::class)->allowed($user);
    }

    private function revoke(PDO $rival, int $id): void
    {
        $stmt = $rival->prepare('UPDATE user_product_grants SET expires_at = NOW() - INTERVAL \'1 minute\' WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function rival(): PDO
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
        $grant = UserProductGrant::query()->create([
            'user_id' => $user->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'G3 entitlement race',
            'description' => 'Concurrent revocation contract', 'category' => '個人開発',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Human decision',
            'description' => 'Only apply with current entitlement',
            'estimated_minutes' => 60, 'remaining_minutes' => 45,
            'progress_percent' => 20, 'status' => 'doing', 'priority' => 1,
            'activation_cost' => 2, 'next_action_note' => 'Original note',
            'sort_order' => 1,
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'status' => 'active',
            'context_scope' => ['scope' => 'task'],
        ]);
        $assistant = CompanionMessage::query()->create([
            'companion_thread_id' => $thread->id, 'role' => 'assistant',
            'content' => 'AI suggestion awaiting human approval',
        ]);
        $candidate = CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id, 'companion_message_id' => $assistant->id,
            'user_id' => $user->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task', 'status' => 'pending',
            'title' => 'Only human confirms', 'summary' => 'No auto progress',
            'payload' => ['next_action_note' => 'Human approved note'],
            'metadata' => ['source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []]],
        ]);

        return [$user, $plan, $task, $candidate, $grant];
    }
}
