<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Production-migration safety rehearsal on throwaway PostgreSQL/MySQL only.
 * The normal Aiven database and isolated MCP Render stage are NEVER targets.
 */
final class McpMigrationRollbackSafetyTest extends TestCase
{
    private const DATABASE = 'canovia_mcp_lock_ci';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CANOVIA_MCP_DISPOSABLE_LOCK_CI') !== '1'
            || config('app.env') !== 'testing'
            || ! in_array(config('database.default'), ['mysql', 'pgsql'], true)
            || config('database.connections.'.config('database.default').'.host') !== '127.0.0.1'
            || config('database.connections.'.config('database.default').'.database') !== self::DATABASE) {
            $this->markTestSkipped('MCP migration tests require exact loopback disposable CI target.');
        }
        $this->withoutVite();
    }

    public function test_indefinite_grants_and_relinked_history_block_dangerous_rollback_without_deletion(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $fingerprint = hash('sha256', (string) Str::uuid());
        $old = McpLinkedSubject::query()->create([
            'user_id' => $first->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprint,
            'status' => McpLinkedSubject::STATUS_REVOKED,
            'revoked_at' => now()->subHour(),
            'linked_at' => now()->subDay(),
        ]);
        $active = McpLinkedSubject::query()->create([
            'user_id' => $second->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprint,
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
        ]);
        $plan = $this->plan($second);
        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $second->id,
            'subject_link_id' => $active->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => hash('sha256', (string) Str::uuid()),
            'scope' => 'overview',
            'status' => McpDelegatedGrant::STATUS_ACTIVE,
            'consented_at' => now(),
            'expires_at' => null,
        ]);
        DB::table('mcp_delegated_access_events')->insert([
            'actor_user_id' => $second->id,
            'subject_link_id' => $active->id,
            'grant_id' => $grant->id,
            'event_kind' => 'context_read',
            'scope' => 'overview',
            'created_at' => now(),
        ]);

        $grantMigration = require base_path('database/migrations/2026_10_14_000100_allow_nonexpiring_mcp_grants.php');
        $identityMigration = require base_path('database/migrations/2026_10_14_000200_preserve_historical_mcp_identity_links.php');

        $grantMigration->up();
        $identityMigration->up();
        $identityMigration->up();

        try {
            $grantMigration->down();
            $this->fail('Null-expiry grant was silently converted or removed by rollback.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Revoke or explicitly reconsent', $e->getMessage());
        }

        try {
            $identityMigration->down();
            $this->fail('Reused historical identity was lost or uniqueness altered by rollback.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Historical MCP identity has been reused', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('mcp_linked_subjects', 'active_identity_fingerprint'));
        $this->assertContains('mcp_active_subject_identity_unique',
            array_column(Schema::getIndexes('mcp_linked_subjects'), 'name'));
        $this->assertNull($grant->fresh()->expires_at);
        $this->assertSame($fingerprint, $active->fresh()->active_identity_fingerprint);
        $this->assertNull($old->fresh()->active_identity_fingerprint);
        $this->assertSame($second->id, $grant->fresh()->user_id);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'subject_link_id' => $active->id,
            'grant_id' => $grant->id,
            'event_kind' => 'context_read',
        ]);
    }

    public function test_replaying_identity_migration_cannot_make_two_live_owners_of_a_corrupt_legacy_subject(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $fingerprint = hash('sha256', (string) Str::uuid());
        $live = McpLinkedSubject::query()->create([
            'user_id' => $first->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprint,
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
        ]);

        // Deliberately emulate an OLD binary or direct SQL writer that did
        // not populate the new active reservation. It must NEVER be allowed
        // to run alongside a live production migration.
        DB::table('mcp_linked_subjects')->insert([
            'user_id' => $second->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprint,
            'active_identity_fingerprint' => null,
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(2, McpLinkedSubject::query()
            ->where('identity_fingerprint', $fingerprint)
            ->where('status', McpLinkedSubject::STATUS_LINKED)->count());

        $migration = require base_path('database/migrations/2026_10_14_000200_preserve_historical_mcp_identity_links.php');
        try {
            // The unique active reservation must cause replay to FAIL,
            // not silently declare both accounts valid or delete history.
            $migration->up();
            $this->fail('Migration accepted two live owners for the same immutable subject.');
        } catch (QueryException $e) {
            $this->assertTrue(in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true));
        }

        $this->assertContains('mcp_active_subject_identity_unique',
            array_column(Schema::getIndexes('mcp_linked_subjects'), 'name'));
        $this->assertSame($fingerprint, $live->fresh()->active_identity_fingerprint);
        $this->assertSame(2, McpLinkedSubject::query()
            ->where('identity_fingerprint', $fingerprint)
            ->where('status', McpLinkedSubject::STATUS_LINKED)->count());
        $this->assertSame(2, McpLinkedSubject::query()
            ->where('identity_fingerprint', $fingerprint)->count());
        // This is a STOP signal for operators: do not "fix" the corrupt
        // fixture by deleting an audit-bearing historical identity.
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'DISPOSABLE_MIGRATION_PLAN',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(7),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }
}
