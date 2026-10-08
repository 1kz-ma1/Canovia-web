<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedPlanAccessPolicy;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpDelegatedRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.resource_url' => 'https://canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://auth.example.test/tenant',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('h', 48),
        ]);
    }

    public function test_account_revocation_section_shows_no_connection_before_oauth_linking(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-delegated-revocation', false)
            ->assertSee('現在、取り消し対象のPlan共有許可はありません。')
            ->assertSee('現在、外部IDの紐付け記録はありません。')
            ->assertDontSee('data-mcp-revoke-grant', false)
            ->assertDontSee('data-mcp-revoke-subject', false);

        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_owner_can_revoke_one_grant_without_revoking_others_and_without_duplicate_events(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'ONE_PLAN_ONLY');
        $anotherPlan = $this->plan($owner, 'OTHER_PLAN');
        $link = $this->link($owner);
        $grant = $this->grant($owner, $link, $plan, 'tasks');
        $otherGrant = $this->grant($owner, $link, $anotherPlan, 'overview');

        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('ONE_PLAN_ONLY')
            ->assertSee('OTHER_PLAN')
            ->assertSee('data-mcp-revoke-grant', false)
            ->assertDontSee('immutable-test-subject');

        $route = route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]);
        $this->actingAs($owner)->delete($route)
            ->assertRedirect(route('auth.account'));

        $grant->refresh();
        $otherGrant->refresh();
        $this->assertSame('revoked', $grant->status);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame('active', $otherGrant->status);
        $this->assertNull($otherGrant->revoked_at);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $owner->id,
            'subject_link_id' => $link->id,
            'grant_id' => $grant->id,
            'event_kind' => 'grant_revoked',
            'scope' => 'tasks',
        ]);

        $this->actingAs($owner)->delete($route)
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_access_events', 1);
        $this->assertDatabaseCount('mcp_delegated_grants', 2);
    }

    public function test_unlink_revokes_all_grants_and_immediately_denies_future_delegated_reads(): void
    {
        $owner = User::factory()->create();
        $planA = $this->plan($owner, 'FIRST_PLAN');
        $planB = $this->plan($owner, 'SECOND_PLAN');
        $link = $this->link($owner);
        $first = $this->grant($owner, $link, $planA, 'tasks');
        $second = $this->grant($owner, $link, $planB, 'overview');

        $principal = $this->principal();
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $planA, 'tasks'));

        $route = route('auth.account.mcp_subject.revoke', ['subject' => $link->id]);
        $this->actingAs($owner)->delete($route)
            ->assertRedirect(route('auth.account'));

        $this->assertSame('revoked', $link->fresh()->status);
        $this->assertNotNull($link->fresh()->revoked_at);
        $this->assertSame('revoked', $first->fresh()->status);
        $this->assertSame('revoked', $second->fresh()->status);
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $planA, 'tasks'));

        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $owner->id,
            'subject_link_id' => $link->id,
            'grant_id' => null,
            'event_kind' => 'subject_unlinked',
        ]);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'grant_id' => $first->id, 'event_kind' => 'grant_revoked_by_unlink',
        ]);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'grant_id' => $second->id, 'event_kind' => 'grant_revoked_by_unlink',
        ]);

        $this->actingAs($owner)->delete($route)
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_access_events', 3);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseCount('mcp_delegated_grants', 2);
    }

    public function test_revoke_is_available_after_plan_transfer_without_exposing_new_owner_title(): void
    {
        $original = User::factory()->create(['first_run_completed_at' => now()]);
        $next = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($original, 'ORIGINAL_TITLE');
        $link = $this->link($original);
        $grant = $this->grant($original, $link, $plan);

        $plan->update(['user_id' => $next->id, 'title' => 'NEW_OWNER_SECRET_TITLE']);

        $this->actingAs($original)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('以前のPlan（現在の所有者の情報は非表示）')
            ->assertDontSee('NEW_OWNER_SECRET_TITLE');

        $this->actingAs($next)->delete(
            route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]),
        )->assertNotFound();

        $this->actingAs($original)->delete(
            route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]),
        )->assertRedirect(route('auth.account'));

        $this->assertSame('revoked', $grant->fresh()->status);
        $this->assertDatabaseCount('mcp_delegated_access_events', 1);
    }

    public function test_guests_and_other_accounts_cannot_read_or_revoke_linked_grants(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $outsider = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $link = $this->link($owner);
        $grant = $this->grant($owner, $link, $plan);

        $this->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect();
        $this->delete(route('auth.account.mcp_subject.revoke', ['subject' => $link->id]))
            ->assertRedirect();

        $this->actingAs($outsider)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('data-mcp-grant-entry', false)
            ->assertDontSee('data-mcp-subject-entry', false);

        $this->actingAs($outsider)->delete(
            route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]),
        )->assertNotFound();
        $this->actingAs($outsider)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id]),
        )->assertNotFound();

        $this->assertSame('active', $grant->fresh()->status);
        $this->assertSame('linked', $link->fresh()->status);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_unlink_revokes_grants_even_if_the_plan_became_shared_or_study(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $link = $this->link($owner);
        $grant = $this->grant($owner, $link, $plan);
        $plan->update(['is_collaborative' => true, 'category' => '資格学習']);

        $this->actingAs($owner)
            ->delete(route('auth.account.mcp_subject.revoke', ['subject' => $link->id]))
            ->assertRedirect(route('auth.account'));

        $this->assertSame('revoked', $grant->fresh()->status);
    }

    public function test_migration_retry_preserves_audit_and_current_grants(): void
    {
        $owner = User::factory()->create();
        $link = $this->link($owner);
        $grant = $this->grant($owner, $link, $this->plan($owner));
        $this->actingAs($owner)->delete(
            route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]),
        )->assertRedirect();

        $migration = require base_path(
            'database/migrations/2026_10_09_000300_create_mcp_delegated_access_events_table.php',
        );
        $migration->up();

        $this->assertDatabaseCount('mcp_delegated_access_events', 1);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'grant_id' => $grant->id, 'event_kind' => 'grant_revoked',
        ]);
    }

    private function plan(User $owner, string $title = 'DEV_PLAN'): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(20),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function principal(): McpVerifiedTokenPrincipal
    {
        return new McpVerifiedTokenPrincipal(
            issuer: 'https://auth.example.test/tenant',
            subject: 'immutable-test-subject',
            clientId: 'https://chatgpt.com/oauth/client.json',
            audience: 'https://canovia.example.test/api/mcp',
            scopes: ['canovia.development.read'],
            expiresAt: now()->timestamp + 1200,
        );
    }

    private function link(User $owner): McpLinkedSubject
    {
        $principal = $this->principal();
        return McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->subject($principal->issuer, $principal->subject),
            'status' => 'linked',
            'linked_at' => now(),
        ]);
    }

    private function grant(
        User $owner,
        McpLinkedSubject $link,
        Plan $plan,
        string $scope = 'tasks',
    ): McpDelegatedGrant {
        $principal = $this->principal();
        return McpDelegatedGrant::query()->create([
            'subject_link_id' => $link->id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->clientResource($principal->clientId, $principal->audience),
            'scope' => $scope,
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
