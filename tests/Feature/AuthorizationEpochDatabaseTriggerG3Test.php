<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\CompanionMembershipCommitGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
