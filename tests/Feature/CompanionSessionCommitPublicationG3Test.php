<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\ExecutionRequestHandoffService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * No RefreshDatabase's implicit transaction: exercise actual commit,
 * rollback, retry-style nested savepoints and session publication timing.
 */
final class CompanionSessionCommitPublicationG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_failed_approval_rolls_back_db_and_never_publishes_execution_request(): void
    {
        [$request, $plan, $task, $candidate] = $this->scenario();
        $key = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $service = app(ExecutionRequestHandoffService::class);

        try {
            DB::transaction(function () use ($request, $candidate, $plan, $task, $key, $service): void {
                $service->confirmFromCompanion(
                    $request, $candidate, $plan, $task, 'Execute after human approval', 'ai', 30,
                );
                $candidate->update([
                    'status' => CompanionMutationCandidate::STATUS_APPLIED,
                    'apply_request_id' => (string) Str::uuid(),
                ]);

                $this->assertNull(session($key), 'Session publication must wait until COMMIT.');
                throw new RuntimeException('Simulated failure after handoff was prepared');
            });
            $this->fail('The simulated failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after handoff was prepared', $exception->getMessage());
        }

        $this->assertNull(session($key));
        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('doing', $task->fresh()->status);
    }

    public function test_session_is_published_exactly_after_committed_approval(): void
    {
        [$request, $plan, $task, $candidate] = $this->scenario();
        $key = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $service = app(ExecutionRequestHandoffService::class);
        $requestId = (string) Str::uuid();

        DB::transaction(function () use ($request, $candidate, $plan, $task, $key, $service, $requestId): void {
            $handoff = $service->confirmFromCompanion(
                $request, $candidate, $plan, $task, 'Execute after human approval', 'ai', 30,
            );
            $this->assertSame('companion_candidate', data_get($handoff, 'source.type'));
            $this->assertNull(session($key));

            $candidate->update([
                'status' => CompanionMutationCandidate::STATUS_APPLIED,
                'apply_request_id' => $requestId,
            ]);
            $this->assertNull(session($key));
        });

        $state = session($key);
        $this->assertSame('confirmed', data_get($state, 'execution_request.confirmation.state'));
        $this->assertSame('Execute after human approval', data_get($state, 'execution_request.instruction'));
        $this->assertSame((int) $candidate->id, data_get($state, 'execution_request.source.id'));
        $this->assertSame(CompanionMutationCandidate::STATUS_APPLIED, $candidate->fresh()->status);
        $this->assertSame($requestId, $candidate->fresh()->apply_request_id);
        $this->assertTrue($service->sourceIsCurrent($state['execution_request'], $plan, $task));
    }

    public function test_inner_transaction_rollback_discards_publication_when_outer_commits(): void
    {
        [$request, $plan, $task, $candidate] = $this->scenario();
        $key = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $service = app(ExecutionRequestHandoffService::class);

        DB::transaction(function () use ($request, $candidate, $plan, $task, $key, $service): void {
            try {
                DB::transaction(function () use ($request, $candidate, $plan, $task, $service): void {
                    $service->confirmFromCompanion(
                        $request, $candidate, $plan, $task, 'Discard a nested attempt',
                    );
                    throw new RuntimeException('Retry attempt rolled back');
                });
                $this->fail('Nested rollback must be observed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Retry attempt rolled back', $exception->getMessage());
            }

            $this->assertNull(session($key));
            $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
        });

        $this->assertNull(session($key));
        $this->assertSame(CompanionMutationCandidate::STATUS_PENDING, $candidate->fresh()->status);
    }

    public function test_companion_handoff_cannot_be_published_outside_transaction(): void
    {
        [$request, $plan, $task, $candidate] = $this->scenario();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a database transaction');

        app(ExecutionRequestHandoffService::class)->confirmFromCompanion(
            $request, $candidate, $plan, $task, 'No standalone Companion grant',
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
            'title' => 'G3 session commit fence',
            'description' => 'Only durable approvals become active handoffs',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Confirm source before execution',
            'description' => 'Only after DB commit',
            'status' => 'doing',
            'progress_percent' => 25,
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
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
            'content' => 'Confirm this execution request',
        ]);
        $candidate = CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'prepare_execution_request',
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => 'Approved execution only',
            'summary' => 'Context must be current',
            'payload' => ['instruction' => 'Execute after human approval', 'actor_type' => 'ai', 'available_minutes' => 30],
            'metadata' => ['source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []]],
        ]);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());

        return [$request, $plan, $task, $candidate];
    }
}
