<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanActivityLog;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * G3-R4: independent PostgreSQL actor modifies collaboration state between
 * the invite locator SELECT and the join's Plan FOR UPDATE.
 */
final class CollaborationInviteJoinPostgresG3Test extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }
        $this->withoutVite();
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_code_join_rechecks_plan_after_concurrent_disable_and_never_inserts_member(): void
    {
        [$owner, $joiner, $plan] = $this->scenario();
        $rival = $this->rival();
        $this->afterInviteLookup('collaboration_join_code', function () use ($rival, $plan): void {
            $rival->prepare('UPDATE plans SET is_collaborative = false WHERE id = :id')
                ->execute(['id' => $plan->id]);
        });

        $this->actingAs($joiner)
            ->post(route('collaboration.join.code'), ['join_code' => $plan->collaboration_join_code])
            ->assertRedirect()
            ->assertSessionHasErrors('join_code');

        $this->assertFalse($plan->fresh()->is_collaborative);
        $this->assertNoJoinSideEffects($plan, $joiner);
    }

    public function test_token_join_rechecks_current_token_after_concurrent_rotation(): void
    {
        [$owner, $joiner, $plan] = $this->scenario();
        $rival = $this->rival();
        $oldToken = $plan->collaboration_share_token;
        $this->afterInviteLookup('collaboration_share_token', function () use ($rival, $plan): void {
            $rival->prepare('UPDATE plans SET collaboration_share_token = :new_token WHERE id = :id')
                ->execute(['new_token' => Str::random(48), 'id' => $plan->id]);
        });

        $this->actingAs($joiner)
            ->get(route('collaboration.join.token', ['token' => $oldToken]))
            ->assertNotFound();

        $this->assertNotSame($oldToken, $plan->fresh()->collaboration_share_token);
        $this->assertNoJoinSideEffects($plan, $joiner);
    }

    public function test_join_holds_plan_lock_until_outer_commit_and_records_member_audit_once(): void
    {
        [$owner, $joiner, $plan] = $this->scenario();
        $rival = $this->rival();
        DB::beginTransaction();
        try {
            $this->actingAs($joiner)
                ->post(route('collaboration.join.code'), ['join_code' => $plan->collaboration_join_code])
                ->assertRedirect(route('plans.show', $plan))
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('plan_members', [
                'plan_id' => $plan->id, 'user_id' => $joiner->id,
                'role' => PlanMember::ROLE_VIEWER,
            ]);
            $rival->beginTransaction();
            $rival->exec("SET LOCAL lock_timeout = '200ms'");
            try {
                $rival->prepare('UPDATE plans SET is_collaborative = false WHERE id = :id')
                    ->execute(['id' => $plan->id]);
                $this->fail('Owner disable must not overtake an uncommitted member join.');
            } catch (PDOException $error) {
                $this->assertSame('55P03', $error->getCode());
            } finally {
                $rival->rollBack();
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
        }

        $this->assertSame(1, PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $joiner->id)->count());
        $this->assertSame(1, PlanActivityLog::query()
            ->where('plan_id', $plan->id)
            ->where('action', 'member_joined')
            ->where('user_id', $joiner->id)->count());

        // Reusing a valid current invite must not demote an existing editor,
        // insert a second row, or emit another "joined" audit event.
        PlanMember::query()->where('plan_id', $plan->id)
            ->where('user_id', $joiner->id)->update(['role' => PlanMember::ROLE_EDITOR]);
        $this->actingAs($joiner)
            ->post(route('collaboration.join.code'), ['join_code' => $plan->collaboration_join_code])
            ->assertRedirect(route('plans.show', $plan));
        $this->assertSame(PlanMember::ROLE_EDITOR, PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $joiner->id)->firstOrFail()->role);
        $this->assertSame(1, PlanActivityLog::query()
            ->where('plan_id', $plan->id)
            ->where('action', 'member_joined')
            ->where('user_id', $joiner->id)->count());
    }

    public function test_admin_invite_rotation_and_disable_fail_behind_plan_lock(): void
    {
        [$owner, $joiner, $plan] = $this->scenario();
        $rival = $this->rival();
        $oldCode = $plan->collaboration_join_code;
        $oldToken = $plan->collaboration_share_token;

        $rival->beginTransaction();
        try {
            $rival->prepare('SELECT id FROM plans WHERE id = :id FOR UPDATE')
                ->execute(['id' => $plan->id]);
            DB::statement("SET lock_timeout = '250ms'");
            try {
                $this->actingAs($owner)
                    ->post(route('plans.collaboration.regenerate', $plan));
                $this->fail('Invite rotation must wait for the Plan lock.');
            } catch (QueryException $error) {
                $this->assertSame('55P03', (string) ($error->errorInfo[0] ?? ''));
            } finally {
                DB::statement("SET lock_timeout = '0'");
            }
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) $rival->rollBack();
        }

        $this->assertSame($oldCode, $plan->fresh()->collaboration_join_code);
        $this->assertSame($oldToken, $plan->fresh()->collaboration_share_token);
        $this->actingAs($owner)
            ->post(route('plans.collaboration.regenerate', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertNotSame($oldCode, $plan->fresh()->collaboration_join_code);
        $this->assertNotSame($oldToken, $plan->fresh()->collaboration_share_token);

        $this->actingAs($owner)
            ->delete(route('plans.collaboration.disable', $plan))
            ->assertRedirect(route('plans.collaboration.settings', $plan));
        $this->assertFalse($plan->fresh()->is_collaborative);
        $this->actingAs($joiner)
            ->post(route('collaboration.join.code'), ['join_code' => $oldCode])
            ->assertSessionHasErrors('join_code');
        $this->assertNoJoinSideEffects($plan, $joiner);
    }

    public function test_valid_token_still_joins_and_owner_never_gets_a_membership_row(): void
    {
        [$owner, $joiner, $plan] = $this->scenario();

        $this->actingAs($joiner)
            ->get(route('collaboration.join.token', ['token' => $plan->collaboration_share_token]))
            ->assertRedirect(route('plans.show', $plan));

        $this->assertDatabaseHas('plan_members', [
            'plan_id' => $plan->id, 'user_id' => $joiner->id,
            'role' => PlanMember::ROLE_VIEWER,
        ]);

        $this->actingAs($owner)
            ->post(route('collaboration.join.code'), ['join_code' => $plan->collaboration_join_code])
            ->assertRedirect(route('plans.show', $plan));
        $this->assertSame(0, PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $owner->id)->count());
    }

    private function afterInviteLookup(string $column, callable $change): void
    {
        $fired = false;
        DB::listen(function ($query) use ($column, $change, &$fired): void {
            $sql = strtolower($query->sql);
            if ($fired || ! str_contains($sql, 'from "plans"')
                || ! str_contains($sql, '"'.$column.'"')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'for update')) {
                return;
            }
            $fired = true;
            $change();
        });
    }

    private function assertNoJoinSideEffects(Plan $plan, User $user): void
    {
        $this->assertSame(0, PlanMember::query()
            ->where('plan_id', $plan->id)->where('user_id', $user->id)->count());
        $this->assertSame(0, PlanActivityLog::query()
            ->where('plan_id', $plan->id)
            ->where('action', 'member_joined')
            ->where('user_id', $user->id)->count());
    }

    private function rival(): PDO
    {
        $db = config('database.connections.pgsql');
        return new PDO(sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 5432),
            (string) ($db['database'] ?? ''),
        ), (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $joiner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'G3 invitation race',
            'description' => 'Retired invites must be rejected before insert',
            'category' => '制作活動', 'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false, 'is_collaborative' => true,
            'collaboration_join_code' => 'CNV-'.strtoupper(Str::random(6)),
            'collaboration_share_token' => Str::random(48),
        ]);
        return [$owner, $joiner, $plan];
    }
}
