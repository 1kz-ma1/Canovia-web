<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\CompanionMembershipCommitGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Direct SQL and bulk Eloquent updates intentionally bypass model hooks.
 * These cases exercise the real engine trigger contract, including rollback.
 */
final class AuthorizationEpochDatabaseTriggerG3Test extends TestCase
{
    use DatabaseTransactions;

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $newOwner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Trigger authority',
            'description' => 'Single origin',
            'category' => '制作活動',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => true,
        ]);
        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $member->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);

        return [$owner, $member, $newOwner, $plan, $membership];
    }

    public function test_direct_sql_role_aba_advances_member_epoch_and_revokes_old_context(): void
    {
        [, $member, , $plan, $membership] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $old = $guard->capture($member, $plan);

        // No Eloquent model event or observer fires in either statement.
        DB::statement("UPDATE plan_members SET role = 'viewer' WHERE id = ?", [$membership->id]);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        DB::statement("UPDATE plan_members SET role = 'editor' WHERE id = ?", [$membership->id]);
        $this->assertSame(3, (int) $membership->fresh()->authorization_epoch);

        $current = $guard->capture($member, $plan);
        $this->assertSame($old['membership_id'], $current['membership_id']);
        $this->assertSame($old['role'], $current['role']);
        $this->assertSame($old['membership_epoch'] + 2, $current['membership_epoch']);

        DB::transaction(function () use ($plan, $guard, $member, $old, $current): void {
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertFalse($guard->canAccess($member, $plan, true, $old));
            $this->assertTrue($guard->canAccess($member, $plan, true, $current));
        });
    }

    public function test_bulk_query_builder_owner_aba_and_collaboration_flip_advance_plan_epoch(): void
    {
        [$owner, , $nextOwner, $plan] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $before = $guard->capture($owner, $plan);

        DB::table('plans')->where('id', $plan->id)->update(['user_id' => $nextOwner->id]);
        DB::table('plans')->where('id', $plan->id)->update(['user_id' => $owner->id]);
        $this->assertSame($before['plan_epoch'] + 2, (int) $plan->fresh()->authorization_epoch);

        DB::table('plans')->where('id', $plan->id)->update(['is_collaborative' => false]);
        DB::table('plans')->where('id', $plan->id)->update(['is_collaborative' => true]);
        $this->assertSame($before['plan_epoch'] + 4, (int) $plan->fresh()->authorization_epoch);

        DB::transaction(function () use ($owner, $plan, $guard, $before): void {
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertFalse($guard->canAccess($owner, $plan, true, $before));
            $this->assertTrue($guard->canAccess(
                $owner, $plan, true, $guard->capture($owner, $plan),
            ));
        });
    }

    public function test_noop_or_cosmetic_updates_do_not_bump_and_orm_does_not_double_bump(): void
    {
        [, $member, , $plan, $membership] = $this->scenario();

        $membership->update(['role' => PlanMember::ROLE_VIEWER]);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        $plan->update(['is_collaborative' => false]);
        $this->assertSame(2, (int) $plan->fresh()->authorization_epoch);

        DB::table('plan_members')->where('id', $membership->id)->update(['role' => 'viewer']);
        DB::table('plans')->where('id', $plan->id)->update(['title' => 'New title']);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(2, (int) $plan->fresh()->authorization_epoch);

        $membership->update(['role' => PlanMember::ROLE_EDITOR]);
        $plan->update(['is_collaborative' => true]);
        $this->assertSame(3, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(3, (int) $plan->fresh()->authorization_epoch);
    }

    public function test_transaction_rollback_restores_role_and_epoch_together(): void
    {
        [, , , $plan, $membership] = $this->scenario();
        try {
            DB::transaction(function () use ($plan, $membership): void {
                DB::table('plan_members')->where('id', $membership->id)
                    ->update(['role' => PlanMember::ROLE_VIEWER]);
                DB::table('plans')->where('id', $plan->id)
                    ->update(['is_collaborative' => false]);
                $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
                $this->assertSame(2, (int) $plan->fresh()->authorization_epoch);
                throw new RuntimeException('Intentional rollback');
            });
            $this->fail('Expected rollback exception');
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional rollback', $exception->getMessage());
        }

        $this->assertSame(1, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(1, (int) $plan->fresh()->authorization_epoch);
        $this->assertSame('editor', $membership->fresh()->role);
        $this->assertTrue((bool) $plan->fresh()->is_collaborative);
    }

    public function test_direct_sql_cannot_retarget_member_id_to_another_user_or_plan(): void
    {
        [$owner, $member, $otherUser, $plan, $membership] = $this->scenario();
        $otherPlan = $plan->replicate(['public_slug', 'owner_token', 'authorization_epoch']);
        $otherPlan->public_slug = (string) Str::uuid();
        $otherPlan->owner_token = Str::random(64);
        $otherPlan->title = 'Other target Plan';
        $otherPlan->save();

        $guard = app(CompanionMembershipCommitGuard::class);
        $old = $guard->capture($member, $plan);
        $this->assertSame(1, $old['membership_epoch']);

        // Direct writes must not silently move a stable row identity into
        // another actor or Plan and then back without an epoch change.
        foreach ([
            ['user_id' => $otherUser->id],
            ['plan_id' => $otherPlan->id],
            ['user_id' => $otherUser->id, 'role' => PlanMember::ROLE_VIEWER],
            ['plan_id' => $otherPlan->id, 'role' => PlanMember::ROLE_VIEWER],
        ] as $rewrite) {
            try {
                // A nested transaction restores PostgreSQL's transaction
                // state after an expected trigger exception (SAVEPOINT).
                DB::transaction(fn () => DB::table('plan_members')
                    ->where('id', $membership->id)->update($rewrite));
                $this->fail('Rebinding a membership row must be forbidden by the database.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }

            $actual = $membership->fresh();
            $this->assertSame((int) $plan->id, (int) $actual->plan_id);
            $this->assertSame((int) $member->id, (int) $actual->user_id);
            $this->assertSame(PlanMember::ROLE_EDITOR, $actual->role);
            $this->assertSame(1, (int) $actual->authorization_epoch);
        }

        $this->assertSame($old, $guard->capture($member, $plan));
        $this->assertSame(0, PlanMember::query()
            ->where('plan_id', $otherPlan->id)->count());
    }

    public function test_eloquent_rebinding_is_denied_but_remove_and_fresh_join_are_allowed(): void
    {
        [$owner, $member, $otherUser, $plan, $membership] = $this->scenario();
        $guard = app(CompanionMembershipCommitGuard::class);
        $old = $guard->capture($member, $plan);

        try {
            DB::transaction(function () use ($membership, $otherUser): void {
                $membership->forceFill(['user_id' => $otherUser->id])->save();
            });
            $this->fail('An Eloquent forceFill must not bypass the database binding contract.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame((int) $member->id, (int) $membership->fresh()->user_id);

        // A legitimate edit changes only the role, bumping the existing epoch.
        $live = $membership->fresh();
        $live->update(['role' => PlanMember::ROLE_VIEWER]);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(PlanMember::ROLE_VIEWER, $membership->fresh()->role);

        $membership->fresh()->delete();
        $replacement = PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $member->id,
            'role' => PlanMember::ROLE_EDITOR, 'joined_at' => now(),
        ]);

        $this->assertNotSame((int) $membership->id, (int) $replacement->id);
        $fresh = $guard->capture($member, $plan);
        $this->assertSame(1, $fresh['membership_epoch']);
        $this->assertNotSame($old['membership_id'], $fresh['membership_id']);
        DB::transaction(function () use ($plan, $member, $guard, $old, $fresh): void {
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->assertFalse($guard->canAccess($member, $plan, true, $old));
            $this->assertTrue($guard->canAccess($member, $plan, true, $fresh));
        });
    }

    public function test_binding_noop_preserves_epoch_for_both_sql_and_eloquent(): void
    {
        [, $member, , $plan, $membership] = $this->scenario();
        $before = app(CompanionMembershipCommitGuard::class)->capture($member, $plan);

        DB::table('plan_members')->where('id', $membership->id)->update([
            'plan_id' => $plan->id,
            'user_id' => $member->id,
        ]);
        $membership->fresh()->update(['plan_id' => $plan->id, 'user_id' => $member->id]);

        $this->assertSame(1, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame($before, app(CompanionMembershipCommitGuard::class)->capture($member, $plan));
    }

    public function test_mysql_and_postgres_ignore_attempted_manual_epoch_rewrite(): void
    {
        [, , , $plan, $membership] = $this->scenario();

        if (DB::getDriverName() === 'sqlite') {
            // SQLite's AFTER OF trigger covers protected property writes;
            // it cannot guard arbitrary epoch-only rewrites in one pass.
            $this->assertSame(1, (int) $plan->fresh()->authorization_epoch);
            return;
        }

        DB::table('plan_members')->where('id', $membership->id)
            ->update(['role' => 'viewer', 'authorization_epoch' => 9000]);
        DB::table('plans')->where('id', $plan->id)
            ->update(['is_collaborative' => false, 'authorization_epoch' => 9000]);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(2, (int) $plan->fresh()->authorization_epoch);

        DB::table('plan_members')->where('id', $membership->id)
            ->update(['authorization_epoch' => 0]);
        DB::table('plans')->where('id', $plan->id)
            ->update(['authorization_epoch' => 0]);
        $this->assertSame(2, (int) $membership->fresh()->authorization_epoch);
        $this->assertSame(2, (int) $plan->fresh()->authorization_epoch);
    }
}
