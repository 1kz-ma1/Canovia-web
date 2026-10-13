<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionConversationService;
use App\Services\CompanionMembershipCommitGuard;
use App\Services\CompanionMutationApplyService;
use App\Services\NativeAiGateway;
use App\Services\PlanOwnershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

final class CompanionMembershipRevocationPostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-test',
        ]);
    }

    public function test_member_lock_first_prevents_rival_removal_until_commit(): void
    {
        [$owner, $member, $plan, , , $membership] = $this->scenario();
        $rival = $this->rival();

        DB::beginTransaction();
        try {
            $this->lockPlan($plan);
            $this->assertTrue(app(CompanionMembershipCommitGuard::class)
                ->canAccess($member, $plan, true));

            $rival->beginTransaction();
            $rival->exec("SET LOCAL lock_timeout = '200ms'");
            try {
                $rival->prepare('DELETE FROM plan_members WHERE id = :id')
                    ->execute(['id' => $membership->id]);
                $this->fail('A concurrent member DELETE must not overtake a protected commit.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                $rival->rollBack();
            }
            $this->assertSame(PlanMember::ROLE_EDITOR, $membership->fresh()->role);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
        }

        $rival->prepare('DELETE FROM plan_members WHERE id = :id')
            ->execute(['id' => $membership->id]);
        $this->assertFalse(DB::transaction(function () use ($member, $plan) {
            $this->lockPlan($plan);
            return app(CompanionMembershipCommitGuard::class)->canAccess($member, $plan);
        }));
        $this->assertTrue(DB::transaction(function () use ($owner, $plan) {
            $this->lockPlan($plan);
            return app(CompanionMembershipCommitGuard::class)->canAccess($owner, $plan, true);
        }));
    }

    public function test_rival_removal_first_blocks_guard_until_delete_commits(): void
    {
        [, $member, $plan, , , $membership] = $this->scenario();
        $rival = $this->rival();
        $rival->beginTransaction();
        try {
            $rival->prepare('DELETE FROM plan_members WHERE id = :id')
                ->execute(['id' => $membership->id]);
            DB::statement("SET lock_timeout = '250ms'");
            try {
                DB::transaction(function () use ($member, $plan) {
                    $this->lockPlan($plan);
                    return app(CompanionMembershipCommitGuard::class)->canAccess($member, $plan);
                });
                $this->fail('Membership guard must not pass a pending DELETE.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            } finally {
                DB::statement("SET lock_timeout = '0'");
            }
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) $rival->rollBack();
        }

        $this->assertFalse(DB::transaction(function () use ($member, $plan) {
            $this->lockPlan($plan);
            return app(CompanionMembershipCommitGuard::class)->canAccess($member, $plan);
        }));
    }

    public function test_viewer_can_read_but_cannot_apply_task_mutation_and_cached_role_is_ignored(): void
    {
        [, $member, $plan, $task, $candidate, $membership] = $this->scenario();
        $request = $this->requestFor($member);
        // Simulate preflight permission cached before an administrator downgrade.
        $ownership = app(PlanOwnershipService::class);
        $this->assertTrue($ownership->canEdit($request, $plan));
        $rival = $this->rival();
        $rival->prepare("UPDATE plan_members SET role = 'viewer' WHERE id = :id")
            ->execute(['id' => $membership->id]);
        $this->assertTrue($ownership->canEdit($request, $plan), 'This is the stale request-local cache.');

        DB::transaction(function () use ($member, $plan) {
            $this->lockPlan($plan);
            $guard = app(CompanionMembershipCommitGuard::class);
            $this->assertTrue($guard->canAccess($member, $plan));
            $this->assertFalse($guard->canAccess($member, $plan, true));
        });

        $this->expectApplyDenied($request, $candidate);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
    }

    public function test_approved_task_commit_retains_membership_lock_and_lock_order(): void
    {
        [, $member, $plan, $task, $candidate, $membership] = $this->scenario();
        $request = $this->requestFor($member);
        $rival = $this->rival();
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) $locks[] = $sql;
        });

        DB::transaction(function () use ($request, $candidate, $rival, $membership): void {
            $result = app(CompanionMutationApplyService::class)->apply(
                $request, $candidate, (string) Str::uuid(),
            );
            $this->assertFalse($result['already_applied']);
            $rival->beginTransaction();
            $rival->exec("SET LOCAL lock_timeout = '200ms'");
            try {
                $rival->prepare("UPDATE plan_members SET role = 'viewer' WHERE id = :id")
                    ->execute(['id' => $membership->id]);
                $this->fail('Member downgrade must wait for approved mutation commit.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            } finally {
                $rival->rollBack();
            }
        });

        $positions = [];
        foreach (['plans', 'tasks', 'companion_mutation_candidates', 'plan_members', 'user_product_grants'] as $table) {
            $idx = collect($locks)->search(fn ($sql) => str_contains($sql, 'from "'.$table.'"'));
            $this->assertNotFalse($idx, 'Missing lock for '.$table);
            $positions[] = $idx;
        }
        $this->assertSame([0, 1, 2, 3, 4], $positions);
        $this->assertSame('applied', $candidate->fresh()->status);
        $this->assertSame('Human approved note', $task->fresh()->next_action_note);

        $rival->prepare("UPDATE plan_members SET role = 'viewer' WHERE id = :id")
            ->execute(['id' => $membership->id]);
        $this->assertSame('Human approved note', $task->fresh()->next_action_note);
    }

    public function test_companion_ai_response_after_member_removal_creates_no_derived_reply_or_candidate(): void
    {
        [, $member, $plan, $task, , $membership, $thread] = $this->scenario();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-g3-membership', 'status' => 'completed',
            'output' => [[
                'type' => 'message', 'content' => [[
                    'type' => 'output_text', 'text' => json_encode([
                        'reply' => 'Unauthorised after membership removal',
                        'memories' => [['kind' => 'interest', 'category' => 'other', 'source_quote' => 'test']],
                        'candidates' => [[
                            'type' => 'update_task', 'title' => 'Must not persist',
                            'summary' => 'revoked', 'payload_json' => '{"next_action_note":"unauthorized"}',
                        ]],
                    ]),
                ]],
            ]],
        ], 200)]);
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $membership) {
                $reply = $real->generateStructured(...$args);
                $this->rival()->prepare('DELETE FROM plan_members WHERE id = :id')
                    ->execute(['id' => $membership->id]);
                return $reply;
            });
        app()->instance(NativeAiGateway::class, $mock);

        try {
            app(CompanionConversationService::class)->send(
                $thread, $member, 'I need guidance', (string) Str::uuid(), $plan, $task,
            );
            $this->fail('Revoked actor must not persist an AI-derived reply.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_target_changed', $exception->errorCode);
        }

        $this->assertDatabaseCount('companion_mutation_candidates', 1); // original fixture
        $this->assertDatabaseCount('future_memos', 0);
        $this->assertSame(['assistant', 'user'], $thread->messages()->orderBy('id')->pluck('role')->all());
        $this->assertNull($thread->fresh()->last_message_at);
    }

    public function test_plan_collaboration_disable_and_owner_or_global_scopes(): void
    {
        [$owner, $member, $plan, , , $membership] = $this->scenario();
        $plan->update(['is_collaborative' => false]);
        DB::transaction(function () use ($owner, $member, $plan) {
            $this->lockPlan($plan);
            $guard = app(CompanionMembershipCommitGuard::class);
            $this->assertTrue($guard->canAccess($owner, $plan, true));
            $this->assertFalse($guard->canAccess($member, $plan));
            $this->assertTrue($guard->canAccess($member, null));
        });
    }

    public function test_guard_cannot_run_outside_a_transaction(): void
    {
        [, $member, $plan] = $this->scenario();
        $this->expectException(LogicException::class);
        app(CompanionMembershipCommitGuard::class)->canAccess($member, $plan);
    }

    private function expectApplyDenied(Request $request, CompanionMutationCandidate $candidate): void
    {
        try {
            app(CompanionMutationApplyService::class)->apply($request, $candidate, (string) Str::uuid());
            $this->fail('Stale collaboration permission must not mutate a Task.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }
    }

    private function lockPlan(Plan $plan): void
    {
        Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        return $request;
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
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $member = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $member->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'title' => 'G3 collaborative membership commit', 'description' => 'Commit time recheck',
            'category' => '制作活動', 'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false, 'is_collaborative' => true,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review human changes',
            'description' => 'Shared Task', 'status' => 'doing', 'progress_percent' => 20,
            'estimated_minutes' => 60, 'remaining_minutes' => 50,
            'priority' => 1, 'activation_cost' => 2, 'sort_order' => 1,
            'next_action_note' => 'Original note',
        ]);
        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $member->id,
            'role' => PlanMember::ROLE_EDITOR, 'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $member->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);
        $assistant = $thread->messages()->create([
            'role' => 'assistant', 'content' => 'Human approval pending',
        ]);
        $candidate = CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $member->id, 'plan_id' => $plan->id, 'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(), 'type' => 'update_task',
            'status' => 'pending', 'title' => 'Human confirmation',
            'summary' => 'No automatic task update',
            'payload' => ['next_action_note' => 'Human approved note'],
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []],
            ],
        ]);

        return [$owner, $member, $plan, $task, $candidate, $membership, $thread];
    }
}
