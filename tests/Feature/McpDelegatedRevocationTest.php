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

    public function test_owner_connection_state_is_truthful_before_and_after_identity_unlink(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $outsider = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-connection-state', false)
            ->assertSee('data-mcp-linked-identity-absent', false)
            ->assertSee('ChatGPT側の接続状態は、この画面からは確認できません。')
            ->assertDontSee('data-mcp-linked-identity-present', false);

        $link = $this->link($owner);
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-linked-identity-present', false)
            ->assertDontSee('data-mcp-linked-identity-absent', false)
            ->assertDontSee($link->identity_fingerprint)
            ->assertDontSee('immutable-test-subject');

        $this->actingAs($outsider)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-linked-identity-absent', false)
            ->assertDontSee('data-mcp-linked-identity-present', false);

        $this->actingAs($owner)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id]),
        )->assertRedirect(route('auth.account'));

        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-linked-identity-absent', false)
            ->assertDontSee('data-mcp-linked-identity-present', false);
        $this->assertNull($link->fresh()->active_identity_fingerprint);
    }

    public function test_owner_onboarding_shows_only_real_local_state_without_external_connection_claim(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);

        // All sensitive connection switches OFF: no actionable connection
        // flow even if the account has old consent records.
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-unavailable', false)
            ->assertDontSee('data-mcp-owner-guide-next-link', false)
            ->assertDontSee('data-mcp-owner-guide-next-chatgpt', false);

        $link = $this->link($owner);
        $plan = $this->plan($owner, 'APPROVED_LOCAL_RECORD_ONLY');
        $grant = $this->grant($owner, $link, $plan);
        $this->actingAs($other)->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('APPROVED_LOCAL_RECORD_ONLY')
            ->assertSee('data-mcp-owner-guide-unavailable', false);

        // A saved grant alone never implies the remote ChatGPT OAuth client
        // connected, or that MCP reads are currently possible.
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-unavailable', false)
            ->assertDontSee('data-mcp-owner-guide-next-chatgpt', false);

        $this->actingAs($owner)->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect(route('auth.account'));
        $this->assertSame('revoked', $grant->fresh()->status);
    }

    public function test_owner_onboarding_next_step_requires_real_link_and_unexpired_grant_record(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);

        // A server-ready account-link flow alone never constitutes consent.
        config([
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'disposable-owner-link-client',
            'canovia_mcp.account_link_client_secret' => str_repeat('s', 32),
            'canovia_mcp.account_link_metadata_url' => 'https://auth.example.test/.well-known/oauth-authorization-server',
            'canovia_mcp.account_link_authorization_endpoint' => 'https://auth.example.test/oauth/authorize',
            'canovia_mcp.account_link_token_endpoint' => 'https://auth.example.test/oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
        ]);

        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-next-link', false)
            ->assertDontSee('data-mcp-owner-guide-next-consent', false)
            ->assertDontSee('data-mcp-owner-guide-next-chatgpt', false);

        $link = $this->link($owner);
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-next-consent', false)
            ->assertDontSee('data-mcp-owner-guide-next-link', false);

        $grant = $this->grant($owner, $link, $this->plan($owner));
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-read-disabled', false)
            ->assertDontSee('data-mcp-owner-guide-next-chatgpt', false);

        $grant->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-next-consent', false)
            ->assertDontSee('data-mcp-owner-guide-read-disabled', false);

        $grant->update(['expires_at' => null]);
        config(['canovia_mcp.tools_enabled' => true]);
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-next-chatgpt', false)
            ->assertSee('接続状態は未確認')
            ->assertDontSee($link->identity_fingerprint)
            ->assertDontSee('immutable-test-subject');

        config(['canovia_mcp.tools_enabled' => false]);
        $this->actingAs($owner)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id]),
        )->assertRedirect(route('auth.account'));
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-owner-guide-next-link', false)
            ->assertDontSee('data-mcp-owner-guide-next-chatgpt', false);
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

    public function test_study_owner_guide_and_plan_id_are_scoped_to_the_eligible_owner(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        config([
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'disposable-owner-link-client',
            'canovia_mcp.account_link_client_secret' => str_repeat('s', 32),
            'canovia_mcp.account_link_metadata_url' => 'https://auth.example.test/.well-known/oauth-authorization-server',
            'canovia_mcp.account_link_authorization_endpoint' => 'https://auth.example.test/oauth/authorize',
            'canovia_mcp.account_link_token_endpoint' => 'https://auth.example.test/oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.study_read_enabled' => true,
            'canovia_mcp.ap_pilot_owner_id' => (string) $owner->id,
        ]);
        $link = $this->link($owner);
        $plan = $this->plan($owner, 'SYNTHETIC_OWNER_PRIVATE_AP');
        $plan->update(['category' => '資格学習']);
        $this->actingAs($owner)->get(route('auth.account'))->assertOk()
            ->assertSee('次へ：Studyで共有する計画を選ぶ')
            ->assertSee(route('workspace.study.index'), false)
            ->assertDontSee('data-mcp-owner-plan-id', false);
        $grant = $this->grant($owner, $link, $plan, 'overview');
        $this->actingAs($owner)->get(route('auth.account'))->assertOk()
            ->assertSee('data-mcp-owner-plan-id', false)
            ->assertSee('接続状態は未確認')
            ->assertDontSee($link->identity_fingerprint);
        $this->actingAs($other)->get(route('auth.account'))->assertOk()
            ->assertDontSee('SYNTHETIC_OWNER_PRIVATE_AP')
            ->assertDontSee('data-mcp-owner-plan-id', false)
            ->assertDontSee('次へ：Studyで共有する計画を選ぶ');
        $plan->update(['is_public' => true]);
        $this->actingAs($owner)->get(route('auth.account'))->assertOk()
            ->assertDontSee('data-mcp-owner-plan-id', false);
        $plan->update(['is_public' => false]);
        $grant->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($owner)->get(route('auth.account'))->assertOk()
            ->assertDontSee('data-mcp-owner-plan-id', false);
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

    public function test_history_preserving_identity_switch_never_resurrects_old_plan_grants(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $oldPlan = $this->plan($first, 'FIRST_PRIVATE_PLAN');
        $newPlan = $this->plan($second, 'SECOND_PRIVATE_PLAN');
        $oldLink = $this->link($first);
        $oldGrant = $this->grant($first, $oldLink, $oldPlan);
        $principal = $this->principal();

        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $oldPlan, 'tasks'));
        $this->actingAs($first)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $oldLink->id]),
        )->assertRedirect(route('auth.account'));

        $this->assertSame('revoked', $oldGrant->fresh()->status);
        $this->assertNull($oldLink->fresh()->active_identity_fingerprint);
        $newLink = $this->link($second);
        $this->assertNotSame($oldLink->id, $newLink->id);
        $this->assertSame($oldLink->identity_fingerprint, $newLink->identity_fingerprint);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);

        // The ChatGPT token for that same issuer+subject may now identify
        // only the second account. It must never retrieve the first's Plan,
        // and new owner needs its own independent per-Plan approval.
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $oldPlan, 'tasks'));
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $newPlan, 'overview'));

        $newGrant = $this->grant($second, $newLink, $newPlan, 'overview');
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $newPlan, 'overview'));
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $newPlan, 'tasks'));
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $oldPlan, 'overview'));

        $this->actingAs($second)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $newLink->id]),
        )->assertRedirect();
        $this->assertSame('revoked', $newGrant->fresh()->status);
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)
            ->allows($principal, $newPlan, 'overview'));
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
