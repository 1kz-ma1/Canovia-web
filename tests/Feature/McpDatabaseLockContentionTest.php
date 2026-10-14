<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedRevocationService;
use App\Services\McpReadOnlyContextTool;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two REAL connections against a migrated, disposable PostgreSQL/MySQL CI DB.
 *
 * No RefreshDatabase transaction wrapper: the contender must see committed
 * fixture rows, and the read's outer transaction must hold real row locks.
 * This test MUST NEVER run against production or a real staging database.
 */
final class McpDatabaseLockContentionTest extends TestCase
{
    private const DATABASE = 'canovia_mcp_lock_ci';
    private const ISSUER = 'https://auth.example.test/tenant';
    private const RESOURCE = 'https://canovia.example.test/api/mcp';
    private const CLIENT = 'disposable-chatgpt-client';

    protected function setUp(): void
    {
        parent::setUp();

        $driver = (string) config('database.default');
        $connection = config('database.connections.'.$driver);
        if (getenv('CANOVIA_MCP_DISPOSABLE_LOCK_CI') !== '1'
            || ! in_array($driver, ['mysql', 'pgsql'], true)
            || ! is_array($connection)
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || ($connection['database'] ?? null) !== self::DATABASE
            || config('app.env') !== 'testing') {
            $this->markTestSkipped('Only the pinned disposable, loopback MCP database race CI may run this test.');
        }

        $this->withoutVite();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_verification_driver' => 'introspection',
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.auth0_jwt_verification_enabled' => false,
            'canovia_mcp.allowed_client_id' => self::CLIENT,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('m', 48),
        ]);

        // A second distinct physical connection with NO DB transaction on
        // the PHPUnit/Laravel default connection.
        config(['database.connections.mcp_lock_contender' => $connection]);
        DB::purge('mcp_lock_contender');
    }

    protected function tearDown(): void
    {
        DB::disconnect('mcp_lock_contender');
        parent::tearDown();
    }

    public function test_live_mcp_read_locks_plan_subject_and_grant_until_commit(): void
    {
        [$owner, $plan, $link, $grant, $principal] = $this->fixture();
        $tool = app(McpReadOnlyContextTool::class);
        $contender = DB::connection('mcp_lock_contender');

        DB::beginTransaction();
        try {
            // The nested transaction inside read() must not release the
            // three locked authorization rows before this outer commit.
            $context = $tool->read($principal, $plan->id, 'overview', 1);
            $this->assertIsArray($context);
            $this->assertSame('DISPOSABLE_ONLY_DEVELOPMENT_PLAN', $context['plan']['title']);
            $this->assertSame([], $context['tasks']);

            $this->assertNowaitBlocked($contender, 'plans', $plan->id);
            $this->assertNowaitBlocked($contender, 'mcp_linked_subjects', $link->id);
            $this->assertNowaitBlocked($contender, 'mcp_delegated_grants', $grant->id);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        // The contender can acquire the same row after the read commits;
        // now a revoke must make every later tool read fail without audit.
        $this->assertNotNull($contender->table('mcp_delegated_grants')
            ->where('id', $grant->id)->lock('FOR UPDATE NOWAIT')->first());
        $this->assertTrue(app(McpDelegatedRevocationService::class)
            ->revokeGrant($owner, $grant->id));
        $this->assertNull($tool->read($principal, $plan->id, 'overview', 1));

        $this->assertSame(1, DB::table('mcp_delegated_access_events')
            ->where('event_kind', 'context_read')->where('grant_id', $grant->id)->count());
        $this->assertSame(1, DB::table('mcp_delegated_access_events')
            ->where('event_kind', 'grant_revoked')->where('grant_id', $grant->id)->count());
    }

    public function test_owner_unlink_after_locked_read_stops_later_access(): void
    {
        [$owner, $plan, $link, $grant, $principal] = $this->fixture();
        $tool = app(McpReadOnlyContextTool::class);
        $contender = DB::connection('mcp_lock_contender');

        DB::beginTransaction();
        try {
            $this->assertIsArray($tool->read($principal, $plan->id, 'tasks', 1));
            $this->assertNowaitBlocked($contender, 'mcp_linked_subjects', $link->id);
            $this->assertNowaitBlocked($contender, 'mcp_delegated_grants', $grant->id);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertTrue(app(McpDelegatedRevocationService::class)
            ->revokeSubject($owner, $link->id));
        $this->assertNull($link->fresh()->active_identity_fingerprint);
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertNull($tool->read($principal, $plan->id, 'tasks', 1));
        $this->assertSame(1, DB::table('mcp_delegated_access_events')
            ->where('event_kind', 'context_read')->where('grant_id', $grant->id)->count());
    }

    public function test_uncommitted_identity_reservation_blocks_racing_second_owner(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $fingerprint = str_repeat('c', 64);
        $contender = DB::connection('mcp_lock_contender');

        // A NOWAIT lock is not applicable to a unique-index insert. Use
        // a bounded one-second DB lock wait to prove the second writer
        // cannot steal a reservation before the first writer commits.
        if (config('database.default') === 'pgsql') {
            $contender->statement("SET lock_timeout = '1s'");
        } else {
            $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        $secondRow = [
            'user_id' => $second->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprint,
            'active_identity_fingerprint' => $fingerprint,
            'status' => 'linked',
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::beginTransaction();
        try {
            McpLinkedSubject::query()->create([
                'user_id' => $first->id,
                'provider_key' => 'chatgpt',
                'identity_fingerprint' => $fingerprint,
                'status' => 'linked',
                'linked_at' => now(),
            ]);

            try {
                $contender->table('mcp_linked_subjects')->insert($secondRow);
                $this->fail('A competing owner inserted the same active identity during an uncommitted claim.');
            } catch (QueryException $exception) {
                $this->assertSame(config('database.default') === 'pgsql' ? '55P03' : '1205',
                    (string) (config('database.default') === 'pgsql'
                        ? ($exception->errorInfo[0] ?? '')
                        : ($exception->errorInfo[1] ?? '')));
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        try {
            $contender->table('mcp_linked_subjects')->insert($secondRow);
            $this->fail('A second owner inserted the same committed active identity.');
        } catch (QueryException $exception) {
            $this->assertSame(config('database.default') === 'pgsql' ? '23505' : '1062',
                (string) (config('database.default') === 'pgsql'
                    ? ($exception->errorInfo[0] ?? '')
                    : ($exception->errorInfo[1] ?? '')));
        }

        $this->assertSame(1, McpLinkedSubject::query()
            ->where('identity_fingerprint', $fingerprint)
            ->where('status', 'linked')->count());
        $this->assertSame(0, McpDelegatedGrant::query()
            ->where('user_id', $second->id)->count());
    }

    private function assertNowaitBlocked(ConnectionInterface $connection, string $table, int $id): void
    {
        try {
            $connection->table($table)->where('id', $id)
                ->lock('FOR UPDATE NOWAIT')->first();
            $this->fail('Competing update could acquire a row locked by an MCP read.');
        } catch (QueryException $exception) {
            $this->assertSame(config('database.default') === 'pgsql' ? '55P03' : '3572',
                (string) (config('database.default') === 'pgsql'
                    ? ($exception->errorInfo[0] ?? '')
                    : ($exception->errorInfo[1] ?? '')));
        }
    }

    /** @return array{User, Plan, McpLinkedSubject, McpDelegatedGrant, McpVerifiedTokenPrincipal} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'DISPOSABLE_ONLY_DEVELOPMENT_PLAN',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(7),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $principal = new McpVerifiedTokenPrincipal(
            self::ISSUER, 'disposable-external-'.(string) Str::uuid(),
            self::CLIENT, self::RESOURCE, ['canovia.development.read'],
            now()->timestamp + 1200,
        );
        $fingerprints = app(McpDelegatedIdentityFingerprintService::class);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprints->subject($principal->issuer, $principal->subject),
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $owner->id,
            'subject_link_id' => $link->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => $fingerprints->clientResource($principal->clientId, $principal->audience),
            'scope' => 'tasks',
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => null,
        ]);

        return [$owner, $plan, $link, $grant, $principal];
    }
}
