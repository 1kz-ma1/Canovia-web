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

    public function test_independent_sql_membership_rebind_cannot_overtake_guard_or_change_binding(): void
    {
        [, $member, $plan, , , $membership] = $this->scenario();
        $unrelated = User::factory()->create(['first_run_completed_at' => now()]);
        $captured = app(CompanionMembershipCommitGuard::class)->capture($member, $plan);
        $rival = $this->rival();

        DB::beginTransaction();
        try {
            $this->lockPlan($plan);
            $this->assertTrue(app(CompanionMembershipCommitGuard::class)
                ->canAccess($member, $plan, true, $captured));

            $rival->beginTransaction();
            try {
                $rival->exec("SET LOCAL lock_timeout = '200ms'");
                try {
                    $rival->prepare('UPDATE plan_members SET user_id = :user WHERE id = :id')
                        ->execute(['user' => $unrelated->id, 'id' => $membership->id]);
                    $this->fail('Another connection cannot modify a locked membership row.');
                } catch (PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }
            } finally {
                $rival->rollBack();
            }
            $this->assertSame($captured, app(CompanionMembershipCommitGuard::class)
                ->capture($member, $plan));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
        }

        // The lock is released, but immutable-binding DDL permanently rejects
        // this raw SQL, and cannot be bypassed by finding a quiet interval.
        try {
            $rival->prepare('UPDATE plan_members SET user_id = :user WHERE id = :id')
                ->execute(['user' => $unrelated->id, 'id' => $membership->id]);
            $this->fail('DB must reject a member identity change even after the lock releases.');
        } catch (PDOException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame((int) $member->id, (int) $membership->fresh()->user_id);
        $this->assertSame((int) $plan->id, (int) $membership->fresh()->plan_id);
        $this->assertSame($captured, app(CompanionMembershipCommitGuard::class)
            ->capture($member, $plan));
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

    public function test_old_collaborative_candidate_without_origin_proof_is_read_only_even_for_current_editor(): void
    {
        [, $member, $plan, $task, $candidate] = $this->scenario();
        $candidate->update(['metadata' => [
            'source' => 'canovia_companion',
            'source_provenance' => ['version' => 1, 'github_evidence' => []],
        ]]);
        $preview = app(CompanionMutationApplyService::class)->reviewPreview($candidate->fresh());

        $this->assertTrue($preview['membership_revalidation_required']);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
        $this->expectApplyDenied($this->requestFor($member), $candidate->fresh());
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
        $this->assertNull(data_get($candidate->fresh()->metadata, 'apply_audit'));
    }

    public function test_old_shared_candidate_is_marked_for_reproposal_in_full_companion_ui(): void
    {
        [, $member, $plan, $task, $candidate, $membership, $thread] = $this->scenario();
        $candidate->update(['metadata' => [
            'source' => 'canovia_companion',
            'source_provenance' => ['version' => 1, 'github_evidence' => []],
        ]]);

        $this->actingAs($member)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('data-companion-membership-revalidation-required', false)
            ->assertDontSee('action="'.route('companion.candidates.apply', [$thread, $candidate]).'"', false);

        $preview = app(\App\Services\CompanionThreadSurfaceService::class)
            ->build($thread->fresh(), $member)['candidatePreviews'][$candidate->id];
        $this->assertTrue($preview['membership_revalidation_required']);
    }

    public function test_old_shared_owner_candidate_cannot_bypass_missing_origin_proof(): void
    {
        [$owner, $member, $plan, , $candidate] = $this->scenario();
        // A historic candidate may have been created while the actor was
        // a collaborator, then promoted to owner. Current ownership cannot
        // reconstruct its original generation-time permission.
        $candidate->update([
            'user_id' => $owner->id,
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []],
            ],
        ]);
        $this->expectApplyDenied($this->requestFor($owner), $candidate->fresh());
        $this->assertSame('pending', $candidate->fresh()->status);
    }

    public function test_old_collaborative_execution_handoff_cannot_republish_session_after_apply(): void
    {
        [, $member, $plan, $task, $candidate] = $this->scenario();
        $originalApplyId = (string) Str::uuid();
        $candidate->update([
            'type' => 'prepare_execution_request',
            'status' => 'applied',
            'apply_request_id' => $originalApplyId,
            'applied_target_type' => 'execution_request',
            'applied_target_id' => $task->id,
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []],
            ],
        ]);
        $preview = app(CompanionMutationApplyService::class)->reviewPreview($candidate->fresh());
        $this->assertTrue($preview['membership_revalidation_required']);

        try {
            app(CompanionMutationApplyService::class)->apply(
                $this->requestFor($member), $candidate->fresh(), $originalApplyId,
            );
            $this->fail('Legacy shared-Plan Execution must not republish a recovered handoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }
        $this->assertSame('applied', $candidate->fresh()->status);
        $this->assertSame($originalApplyId, $candidate->fresh()->apply_request_id);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
    }

    public function test_legacy_pending_private_plan_proposal_keeps_existing_behavior(): void
    {
        [$owner, $member, $plan, $task, $candidate] = $this->scenario();
        $plan->update(['user_id' => $member->id, 'is_collaborative' => false]);
        $candidate->update(['metadata' => [
            'source' => 'canovia_companion',
            'source_provenance' => ['version' => 1, 'github_evidence' => []],
        ]]);

        $preview = app(CompanionMutationApplyService::class)->reviewPreview($candidate->fresh());
        $this->assertFalse($preview['membership_revalidation_required']);
        $applied = app(CompanionMutationApplyService::class)->apply(
            $this->requestFor($member), $candidate->fresh(), (string) Str::uuid(),
        );
        $this->assertFalse($applied['already_applied']);
        $this->assertSame('Human approved note', $task->fresh()->next_action_note);
    }

    public function test_removed_and_rejoined_editor_has_new_identity_not_old_ai_authority(): void
    {
        [, $member, $plan, $task, $candidate, $oldMember] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $captured = $guard->capture($member, $plan);
        $this->assertSame((int) $oldMember->id, $captured['membership_id']);
        $this->assertSame('editor', $captured['role']);

        $replacementId = $this->replaceMembership($oldMember);
        $this->assertNotSame($captured['membership_id'], $replacementId);

        DB::transaction(function () use ($guard, $member, $plan, $captured): void {
            $this->lockPlan($plan);
            $this->assertTrue($guard->canAccess($member, $plan, true));
            $this->assertFalse($guard->canAccess($member, $plan, true, $captured));
            $this->assertFalse($guard->canAccess($member, $plan, false, $captured));
        });

        $metadata = $candidate->metadata;
        $metadata['membership_provenance'] = $captured;
        $candidate->update(['metadata' => $metadata]);
        $this->expectApplyDenied($this->requestFor($member), $candidate->fresh());
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->apply_request_id);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
    }

    public function test_old_membership_ai_reply_cannot_commit_after_same_role_rejoin(): void
    {
        [, $member, $plan, $task, , $original, $thread] = $this->scenario();
        $this->fakeCompanionOutput('Human approved note');
        $newMemberId = null;

        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $original, &$newMemberId) {
                $reply = $real->generateStructured(...$args);
                $newMemberId = $this->replaceMembership($original);

                return $reply;
            });
        app()->instance(NativeAiGateway::class, $mock);

        try {
            app(CompanionConversationService::class)->send(
                $thread, $member, 'Please review this Plan', (string) Str::uuid(), $plan, $task,
            );
            $this->fail('New member identity must not inherit an earlier AI output.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_target_changed', $exception->errorCode);
        }

        $this->assertNotNull($newMemberId);
        $this->assertNotSame((int) $original->id, $newMemberId);
        $this->assertSame(1, $thread->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, $thread->messages()->where('role', 'user')->count());
        $this->assertSame(1, CompanionMutationCandidate::query()->count());
        $this->assertNull($thread->fresh()->last_message_at);
    }

    public function test_valid_ai_output_stamps_membership_on_reply_and_candidate(): void
    {
        [, $member, $plan, $task, $legacyCandidate, $membership, $thread] = $this->scenario();
        $this->fakeCompanionOutput('Human approved note');
        // Simulate a pre-#533 candidate with no historical role proof: it
        // must not suppress a fresh safe proposal despite identical payload.
        $legacyCandidate->update(['metadata' => [
            'source' => 'canovia_companion',
            'source_provenance' => ['version' => 1, 'github_evidence' => []],
        ]]);

        $result = app(CompanionConversationService::class)->send(
            $thread, $member, 'Please review this Plan', (string) Str::uuid(), $plan, $task,
        );
        $expected = app(CompanionMembershipCommitGuard::class)->capture($member, $plan);

        $this->assertSame((int) $membership->id, $expected['membership_id']);
        $this->assertSame($expected, data_get($result['message']->metadata, 'membership_provenance'));
        $this->assertCount(1, $result['candidates'], 'Old unproven candidate must not shadow new membership generation.');
        $this->assertSame($expected, data_get($result['candidates']->first()->metadata, 'membership_provenance'));
        $this->assertNotSame($legacyCandidate->id, $result['candidates']->first()->id);
        $this->assertSame(2, CompanionMutationCandidate::query()->count());

        $approval = app(CompanionMutationApplyService::class)->apply(
            $this->requestFor($member),
            $result['candidates']->first(),
            (string) Str::uuid(),
        );
        $this->assertFalse($approval['already_applied']);
        $this->assertSame('Human approved note', $task->fresh()->next_action_note);
    }

    public function test_membership_insertion_after_absent_capture_does_not_authorize_old_context(): void
    {
        [, $member, $plan, , , $original] = $this->scenario();
        $rival = $this->rival();
        $rival->prepare('DELETE FROM plan_members WHERE id = :id')
            ->execute(['id' => $original->id]);
        $guard = app(CompanionMembershipCommitGuard::class);
        $before = $guard->capture($member, $plan);
        $this->assertSame(['scope' => 'unavailable'], $before);

        $rival->beginTransaction();
        try {
            DB::beginTransaction();
            try {
                $this->lockPlan($plan);
                $this->assertFalse($guard->canAccess($member, $plan, false, $before));

                $rival->exec("SET LOCAL lock_timeout = '200ms'");
                try {
                    $this->insertMember($rival, $plan->id, $member->id);
                    $this->fail('An aligned member INSERT must wait for the Plan commit.');
                } catch (PDOException $exception) {
                    $this->assertSame('55P03', $exception->getCode());
                }
                DB::commit();
            } finally {
                if (DB::transactionLevel() > 0) DB::rollBack();
            }
        } finally {
            if ($rival->inTransaction()) $rival->rollBack();
        }

        $replacementId = $this->insertMember($rival, $plan->id, $member->id);
        $this->assertGreaterThan((int) $original->id, $replacementId);

        DB::transaction(function () use ($guard, $member, $plan, $before): void {
            $this->lockPlan($plan);
            $this->assertTrue($guard->canAccess($member, $plan));
            $this->assertFalse($guard->canAccess($member, $plan, false, $before));
        });
    }

    private function fakeCompanionOutput(string $nextAction): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-g3-membership-epoch', 'status' => 'completed',
            'output' => [[
                'type' => 'message', 'content' => [[
                    'type' => 'output_text', 'text' => json_encode([
                        'reply' => 'Review a shared Plan',
                        'memories' => [],
                        'candidates' => [[
                            'type' => 'update_task', 'title' => 'Human confirmation',
                            'summary' => 'No automatic progress',
                            'payload_json' => json_encode(['next_action_note' => $nextAction]),
                        ]],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ], 200)]);
    }

    private function replaceMembership(PlanMember $old): int
    {
        $rival = $this->rival();
        $rival->prepare('DELETE FROM plan_members WHERE id = :id')
            ->execute(['id' => $old->id]);

        return $this->insertMember($rival, $old->plan_id, $old->user_id);
    }

    private function insertMember(PDO $rival, int $planId, int $userId): int
    {
        $stmt = $rival->prepare(
            "INSERT INTO plan_members (plan_id, user_id, role, joined_at, created_at, updated_at)
             VALUES (:plan_id, :user_id, 'editor', NOW(), NOW(), NOW()) RETURNING id"
        );
        $stmt->execute(['plan_id' => $planId, 'user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function test_role_downgrade_then_restore_advances_epoch_and_invalidates_old_candidate(): void
    {
        [, $member, $plan, $task, $candidate, $membership] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $original = $guard->capture($member, $plan);
        $this->assertSame(1, $original['membership_epoch']);
        $this->assertSame(1, $original['plan_epoch']);

        $membership->update(['role' => PlanMember::ROLE_VIEWER]);
        $this->assertSame(2, $membership->fresh()->authorization_epoch);
        $membership->update(['role' => PlanMember::ROLE_EDITOR]);
        $this->assertSame(3, $membership->fresh()->authorization_epoch);

        $current = $guard->capture($member, $plan);
        $this->assertSame($original['membership_id'], $current['membership_id']);
        $this->assertSame($original['role'], $current['role']);
        $this->assertNotSame($original['membership_epoch'], $current['membership_epoch']);

        DB::transaction(function () use ($guard, $member, $plan, $original, $current): void {
            $this->lockPlan($plan);
            $this->assertFalse($guard->canAccess($member, $plan, true, $original));
            $this->assertTrue($guard->canAccess($member, $plan, true, $current));
        });

        $this->expectApplyDenied($this->requestFor($member), $candidate->fresh());
        $this->assertSame('Original note', $task->fresh()->next_action_note);
        $this->assertSame('pending', $candidate->fresh()->status);
    }

    public function test_inflight_ai_reply_is_discarded_after_editor_downgrade_and_repromotion(): void
    {
        [, $member, $plan, $task, , $membership, $thread] = $this->scenario();
        $this->fakeCompanionOutput('Should never be persisted');
        $real = app(NativeAiGateway::class);
        $mock = Mockery::mock(NativeAiGateway::class)->makePartial();
        $mock->shouldReceive('generateStructured')->once()
            ->andReturnUsing(function (...$args) use ($real, $membership) {
                $reply = $real->generateStructured(...$args);
                $membership->update(['role' => PlanMember::ROLE_VIEWER]);
                $membership->update(['role' => PlanMember::ROLE_EDITOR]);

                return $reply;
            });
        app()->instance(NativeAiGateway::class, $mock);

        try {
            app(CompanionConversationService::class)->send(
                $thread, $member, 'Review the task', (string) Str::uuid(), $plan, $task,
            );
            $this->fail('An AI response generated during an earlier Editor lifetime must be denied.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_target_changed', $exception->errorCode);
        }

        $this->assertSame(3, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(1, $thread->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, $thread->messages()->where('role', 'user')->count());
        $this->assertSame(1, CompanionMutationCandidate::query()->count());
        $this->assertDatabaseCount('future_memos', 0);
        $this->assertNull($thread->fresh()->last_message_at);
    }

    public function test_owner_transfer_then_restore_advances_plan_epoch_even_with_same_owner(): void
    {
        [$owner, $member, $plan, $task, $candidate] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $old = $guard->capture($owner, $plan);
        $this->assertSame('owner', $old['scope']);

        $candidate->update([
            'user_id' => $owner->id,
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => ['version' => 1, 'github_evidence' => []],
                'membership_provenance' => $old,
            ],
        ]);

        $plan->update(['user_id' => $member->id]);
        $this->assertSame($old['plan_epoch'] + 1, $plan->fresh()->authorization_epoch);
        $plan->update(['user_id' => $owner->id]);
        $this->assertSame($old['plan_epoch'] + 2, $plan->fresh()->authorization_epoch);

        $new = $guard->capture($owner, $plan);
        $this->assertSame($old['scope'], $new['scope']);
        $this->assertSame($old['owner_user_id'], $new['owner_user_id']);
        $this->assertNotSame($old['plan_epoch'], $new['plan_epoch']);

        DB::transaction(function () use ($owner, $plan, $guard, $old, $new): void {
            $this->lockPlan($plan);
            $this->assertFalse($guard->canAccess($owner, $plan, true, $old));
            $this->assertTrue($guard->canAccess($owner, $plan, true, $new));
        });

        $this->expectApplyDenied($this->requestFor($owner), $candidate->fresh());
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertSame('Original note', $task->fresh()->next_action_note);
    }

    public function test_collaboration_disable_and_reenable_invalidates_old_member_identity(): void
    {
        [, $member, $plan, , $candidate] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $before = $guard->capture($member, $plan);

        app(\App\Services\PlanCollaborationService::class)->disable($plan);
        $this->assertSame($before['plan_epoch'] + 1, $plan->fresh()->authorization_epoch);
        app(\App\Services\PlanCollaborationService::class)->enable($plan);
        $this->assertSame($before['plan_epoch'] + 2, $plan->fresh()->authorization_epoch);

        $after = $guard->capture($member, $plan);
        $this->assertSame($before['membership_id'], $after['membership_id']);
        $this->assertSame($before['membership_epoch'], $after['membership_epoch']);
        $this->assertNotSame($before['plan_epoch'], $after['plan_epoch']);
        $this->expectApplyDenied($this->requestFor($member), $candidate->fresh());
    }

    public function test_cosmetic_and_redundant_role_writes_do_not_revoke_live_epoch(): void
    {
        [, $member, $plan, , , $membership] = $this->scenario();
        $before = app(CompanionMembershipCommitGuard::class)->capture($member, $plan);

        $membership->update(['role' => PlanMember::ROLE_EDITOR]);
        $plan->update(['title' => 'Cosmetic rename']);
        $this->assertSame($before, app(CompanionMembershipCommitGuard::class)->capture($member, $plan));
        DB::transaction(function () use ($member, $plan, $before): void {
            $this->lockPlan($plan);
            $this->assertTrue(app(CompanionMembershipCommitGuard::class)
                ->canAccess($member, $plan, true, $before));
        });
    }

    public function test_old_pre_epoch_membership_proof_requires_new_collaborative_proposal(): void
    {
        [, $member, $plan, $task, $candidate, $membership] = $this->scenario();
        $oldProof = [
            'scope' => 'member',
            'membership_id' => (int) $membership->id,
            'role' => PlanMember::ROLE_EDITOR,
        ];
        $metadata = $candidate->metadata;
        $metadata['membership_provenance'] = $oldProof;
        $candidate->update(['metadata' => $metadata]);

        $preview = app(CompanionMutationApplyService::class)->reviewPreview($candidate->fresh());
        $this->assertTrue($preview['membership_revalidation_required']);
        $this->expectApplyDenied($this->requestFor($member), $candidate->fresh());
        $this->assertSame('Original note', $task->fresh()->next_action_note);
    }

    public function test_guest_owner_claim_advances_plan_epoch_under_plan_lock(): void
    {
        [$owner, , $plan] = $this->scenario();
        $plan->update(['user_id' => null]);
        $before = (int) $plan->fresh()->authorization_epoch;
        $newOwner = User::factory()->create(['first_run_completed_at' => now()]);
        $request = $this->requestFor($newOwner);
        $request->cookies->set('pace_keeper_owner_token_'.$plan->id, $plan->owner_token);

        $claimed = app(\App\Services\GuestPlanClaimService::class)->claim($request, $newOwner);
        $this->assertSame(1, $claimed);
        $this->assertSame((int) $newOwner->id, (int) $plan->fresh()->user_id);
        $this->assertSame($before + 1, (int) $plan->fresh()->authorization_epoch);
        $this->assertSame(0, app(\App\Services\GuestPlanClaimService::class)->claim($request, $newOwner));
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
                'membership_provenance' => app(CompanionMembershipCommitGuard::class)
                    ->capture($member, $plan),
            ],
        ]);

        return [$owner, $member, $plan, $task, $candidate, $membership, $thread];
    }
}
