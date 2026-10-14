<?php

namespace Tests\Feature;

use App\Http\Middleware\CloseUnopenedMcpStaging;
use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpStagingLoginPilotPolicy;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpStagingSyntheticPlanFixtureBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpStagingSyntheticPlanConsentPilotTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://canovia-mcp-dev.jp.auth0.com/';
    private const RESOURCE = 'https://canovia-mcp-staging.onrender.com/api/mcp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(CloseUnopenedMcpStaging::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
            'canovia_staging.plan_consent_pilot_enabled' => false,
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => false,
            'canovia_mcp.delegated_policy_enabled' => false,
            'canovia_mcp.tools_enabled' => false,
            'canovia_mcp.allowed_client_id' => 'tpc_staging-synthetic-chatgpt',
            'canovia_mcp.account_link_client_id' => 'stage-first-party-client',
            'canovia_mcp.account_link_client_secret' => 'stage-first-party-synthetic-secret',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('k', 48),
            'canovia_mcp.account_link_metadata_url' => self::ISSUER.'.well-known/openid-configuration',
            'canovia_mcp.account_link_authorization_endpoint' => self::ISSUER.'authorize',
            'canovia_mcp.account_link_token_endpoint' => self::ISSUER.'oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia-mcp-staging.onrender.com/account/mcp/link/callback',
        ]);
        Http::preventStrayRequests();
    }

    public function test_linked_actor_sees_no_consent_form_without_two_independent_flags(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $this->link($owner);

        $this->actingAs($owner)->get('/account')->assertOk()
            ->assertSee('本人確認が済んでいます')
            ->assertDontSee('data-mcp-staging-plan-consent', false);

        config(['canovia_mcp.plan_consent_enabled' => true]);
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-plan-consent', false);
        $this->post(route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]), [
            'scope' => 'tasks', 'duration_days' => 7,
        ])->assertNotFound();

        config(['canovia_mcp.plan_consent_enabled' => false,
            'canovia_staging.plan_consent_pilot_enabled' => true]);
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-plan-consent', false);

        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        Http::assertNothingSent();
    }

    public function test_only_approved_synthetic_plan_has_a_staged_consent_form(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $this->link($owner);
        $this->enableConsent();

        $this->actingAs($owner)->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-plan-consent', false)
            ->assertSee(McpStagingSyntheticPlanFixtureBootstrap::TITLE)
            ->assertSee('name="scope"', false)
            ->assertSee('name="duration_days"', false)
            ->assertDontSee('resources/js/app.js')
            ->assertDontSee('/build/assets/');

        $foreignPlan = $this->fixture($owner, 'Other user plan', (string) Str::uuid());
        $this->post(route('auth.account.mcp_plan_consent.start', ['plan' => $foreignPlan->id]), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertNotFound();

        $plan->forceFill(['title' => 'tampered fixture'])->save();
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-plan-consent', false);
        $this->post(route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertNotFound();

        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        Http::assertNothingSent();
    }

    public function test_fresh_oauth_start_is_possible_only_for_linked_synthetic_owner(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $this->enableConsent();

        $this->actingAs($owner)->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-plan-consent', false);

        $this->post(route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertRedirect(route('auth.account'));

        $this->link($owner);
        $this->metadata();
        $r = $this->post(route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]), [
            'scope' => 'tasks', 'duration_days' => 1,
        ]);
        $this->assertStringStartsWith(self::ISSUER.'authorize?', $r->headers->get('Location'));
        parse_str((string) parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY), $oauthQuery);
        // Auth0 needs an explicit API audience even when RFC8707 resource
        // compatibility is disabled; neither value may be caller-supplied.
        $this->assertSame(self::RESOURCE, $oauthQuery['resource']);
        $this->assertSame(self::RESOURCE, $oauthQuery['audience']);
        $pending = session('mcp.account_link.pending');
        $this->assertSame('plan_consent', $pending['purpose']);
        $this->assertSame((int) $plan->id, $pending['plan_id']);
        $this->assertSame('tasks', $pending['scope']);
        $this->assertSame(1, $pending['duration_days']);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_confirm_and_revoke_are_bounded_to_single_fixture_without_mcp_read(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $fingerprint = $this->link($owner);
        $this->enableConsent();

        $this->actingAs($owner)->withSession(['mcp.plan_consent.verified' => [
            'actor_id' => (int) $owner->id,
            'identity_fingerprint' => $fingerprint,
            'plan_id' => (int) $plan->id,
            'scope' => 'tasks',
            'duration_days' => 7,
            'verified_at' => now()->timestamp,
        ]])->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-consent-confirm', false)
            ->assertSee('data-mcp-staging-consent-preview', false)
            ->assertSee('この内容で共有を許可する')
            ->assertDontSee('data-mcp-staging-plan-consent', false)
            ->assertDontSee('data-mcp-staging-dual-client-guide', false)
            ->assertDontSee('data-mcp-staging-share-complete', false);

        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'))
            ->assertSessionHas(
                'status',
                'この計画をChatGPTに共有する設定を保存しました。ChatGPT側から確認できるかは、接続後にお試しください。',
            );

        $grant = McpDelegatedGrant::query()->sole();
        $this->assertSame($owner->id, $grant->user_id);
        $this->assertSame($plan->id, $grant->plan_id);
        $this->assertSame('tasks', $grant->scope);
        $this->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-active-grant', false)
            ->assertSee('data-mcp-staging-share-complete', false)
            ->assertSee('ChatGPTでできること')
            ->assertSee('data-mcp-staging-next-step', false)
            ->assertSee('data-mcp-staging-change-settings', false)
            ->assertSee('この計画の共有をやめる')
            ->assertDontSee('data-mcp-staging-plan-consent', false)
            ->assertDontSee('data-mcp-staging-final-confirm-button', false);

        // Even after consent, stage Nginx excludes /api/mcp and the app
        // flag must stay OFF. The controller itself also denies the read.
        $this->assertFalse(config('canovia_mcp.tools_enabled'));
        $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertUnauthorized();

        $this->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect(route('auth.account'));
        $this->assertSame(McpDelegatedGrant::STATUS_REVOKED, $grant->fresh()->status);
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-active-grant', false)
            ->assertDontSee('data-mcp-staging-share-complete', false)
            ->assertSee('data-mcp-staging-plan-consent', false);
    }

    public function test_verified_consent_shows_only_whitelisted_preview_and_primary_confirmation(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $fingerprint = $this->link($owner);
        $this->enableConsent();

        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Review staging scenario',
            'description' => 'PRIVATE_DESCRIPTION_MUST_NOT_APPEAR',
            'status' => 'doing',
            'progress_percent' => 25,
            'priority' => 1,
            'sort_order' => 1,
        ]);

        $session = [
            'actor_id' => (int) $owner->id,
            'identity_fingerprint' => $fingerprint,
            'plan_id' => (int) $plan->id,
            'scope' => 'tasks',
            'duration_days' => 7,
            'verified_at' => now()->timestamp,
        ];
        $this->actingAs($owner)->withSession(['mcp.plan_consent.verified' => $session])
            ->get('/account')->assertOk()
            ->assertSee('共有内容のプレビュー')
            ->assertSee($plan->title)
            ->assertSee('Review staging scenario')
            ->assertSee('記録上の進捗：25%')
            ->assertSee('共有期間：')
            ->assertSee('data-mcp-staging-final-confirm-button', false)
            ->assertSee('今は共有しない')
            ->assertDontSee('PRIVATE_DESCRIPTION_MUST_NOT_APPEAR')
            ->assertDontSee('data-mcp-staging-dual-client-guide', false)
            ->assertDontSee('data-mcp-staging-plan-consent', false)
            ->assertDontSee('data-mcp-staging-share-complete', false)
            ->assertDontSee('data-mcp-staging-change-settings', false)
            ->assertDontSee('name="scope"', false)
            ->assertDontSee('name="duration_days"', false);

        // Overview grants must not preview task metadata before approval.
        $session['scope'] = 'overview';
        $this->withSession(['mcp.plan_consent.verified' => $session])
            ->get('/account')->assertOk()
            ->assertSee('計画の名前のみ')
            ->assertDontSee('Review staging scenario')
            ->assertDontSee('PRIVATE_DESCRIPTION_MUST_NOT_APPEAR')
            ->assertDontSee('data-mcp-staging-task-preview', false);

        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_expired_verified_consent_does_not_show_preview_or_create_grant(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $fingerprint = $this->link($owner);
        $this->enableConsent();

        $this->actingAs($owner)->withSession(['mcp.plan_consent.verified' => [
            'actor_id' => (int) $owner->id,
            'identity_fingerprint' => $fingerprint,
            'plan_id' => (int) $plan->id,
            'scope' => 'tasks',
            'duration_days' => 7,
            'verified_at' => now()->subMinutes(6)->timestamp,
        ]])->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-consent-preview', false)
            ->assertDontSee('data-mcp-staging-final-confirm-button', false)
            ->assertSee('data-mcp-staging-plan-consent', false);
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_staging_owner_can_preview_and_revoke_opt_in_until_revoked_consent(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $fingerprint = $this->link($owner);
        $this->enableConsent();

        $this->actingAs($owner)->withSession(['mcp.plan_consent.verified' => [
            'actor_id' => (int) $owner->id,
            'identity_fingerprint' => $fingerprint,
            'plan_id' => (int) $plan->id,
            'scope' => 'overview',
            'duration_days' => \App\Services\McpExplicitPlanConsentService::UNTIL_REVOKED,
            'verified_at' => now()->timestamp,
        ]])->get('/account')->assertOk()
            ->assertSee('共有する内容を確認')
            ->assertSee('取り消すまで（いつでも解除できます）')
            ->assertSee('data-mcp-staging-final-confirm-button', false);

        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect(route('auth.account'));
        $grant = McpDelegatedGrant::query()->sole();
        $this->assertSame('active', $grant->status);
        $this->assertNull($grant->expires_at);
        $this->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-share-complete', false)
            ->assertSee('取り消すまで')
            ->assertSee('data-mcp-staging-revoke', false)
            ->assertDontSee('data-mcp-staging-plan-consent', false);

        $this->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect(route('auth.account'));
        $this->assertSame('revoked', $grant->fresh()->status);
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-share-complete', false)
            ->assertSee('data-mcp-staging-plan-consent', false);
    }

    public function test_stage_owner_can_unlink_subject_and_revoke_indefinite_grant(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $fingerprint = $this->link($owner);
        $this->enableConsent();
        $link = McpLinkedSubject::query()->where('user_id', $owner->id)->sole();
        $this->assertSame($fingerprint, $link->active_identity_fingerprint);

        $this->actingAs($owner)->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-subject-unlink', false)
            ->assertSee('本人確認の連携を解除する');

        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $owner->id, 'subject_link_id' => $link->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => str_repeat('f', 64),
            'scope' => 'overview', 'status' => McpDelegatedGrant::STATUS_ACTIVE,
            'consented_at' => now(), 'expires_at' => null,
        ]);
        $this->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-share-complete', false)
            ->assertSee('data-mcp-staging-subject-unlink', false);
        $outsider = User::factory()->create(['first_run_completed_at' => now()]);
        // The isolated stage actor gate blocks this request before the
        // controller's owner-specific 404 check (generic stage 503).
        $this->actingAs($outsider)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id]),
        )->assertStatus(503);
        $this->assertSame(McpLinkedSubject::STATUS_LINKED, $link->fresh()->status);
        $this->assertSame(McpDelegatedGrant::STATUS_ACTIVE, $grant->fresh()->status);

        $this->actingAs($owner)->delete(
            route('auth.account.mcp_subject.revoke', ['subject' => $link->id]),
        )->assertRedirect(route('auth.account'));
        $this->assertSame(McpLinkedSubject::STATUS_REVOKED, $link->fresh()->status);
        $this->assertNull($link->fresh()->active_identity_fingerprint);
        $this->assertSame(McpDelegatedGrant::STATUS_REVOKED, $grant->fresh()->status);
        $this->get('/account')->assertOk()
            ->assertSee('本人確認をはじめる')
            ->assertDontSee('data-mcp-staging-share-complete', false)
            ->assertDontSee('data-mcp-staging-subject-unlink', false);
    }

    public function test_stale_or_tampered_verified_session_cannot_grant_another_plan(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $foreignPlan = $this->fixture($owner, 'not-the-fixture', (string) Str::uuid());
        $fingerprint = $this->link($owner);
        $this->enableConsent();

        foreach ([
            ['plan_id' => $foreignPlan->id],
            ['plan_id' => $plan->id, 'verified_at' => now()->subMinutes(6)->timestamp],
        ] as $tamper) {
            $session = array_replace([
                'actor_id' => (int) $owner->id,
                'identity_fingerprint' => $fingerprint,
                'plan_id' => (int) $plan->id,
                'scope' => 'overview',
                'duration_days' => 7,
                'verified_at' => now()->timestamp,
            ], $tamper);
            $this->actingAs($owner)->withSession(['mcp.plan_consent.verified' => $session])
                ->post(route('auth.account.mcp_plan_consent.confirm'))
                ->assertRedirect(route('auth.account'));
        }
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_dual_client_connection_guide_is_status_honest_and_stage_only(): void
    {
        $owner = $this->actor();
        $plan = $this->fixture($owner);
        $this->link($owner);
        $this->enableConsent();
        $this->actingAs($owner)->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-dual-client-guide', false);

        // Synthetic read pilot must be explicitly approved. The test remains
        // in-memory; these flags do not open a network endpoint or real DB.
        config([
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.web_access_enabled' => true,
            'canovia_staging.web_access_explicitly_approved' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
        ]);
        $this->assertTrue(app(\App\Services\McpStagingSyntheticReadPilotPolicy::class)->isReady());
        $this->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-dual-client-guide', false)
            ->assertSee('data-mcp-staging-link-state', false)
            ->assertSee('data-mcp-staging-connection-help', false)
            ->assertSee('接続の進め方')
            ->assertSee('うまく接続できないとき')
            ->assertSee('本人確認で選んだものと同じアカウント')
            ->assertDontSee('Auth0')
            ->assertDontSee('外部ID')
            ->assertSee('本人確認：済み')
            ->assertSee('計画の共有：許可していません')
            ->assertSee('同じアカウント')
            ->assertSee('ChatGPT側の接続が完了したとは限りません')
            ->assertDontSee('github|', false)
            ->assertDontSee('google-oauth2|', false)
            ->assertDontSee('data-mcp-staging-active-grant', false);

        $link = McpLinkedSubject::query()->where('user_id', $owner->id)->sole();
        $fingerprint = app(McpDelegatedIdentityFingerprintService::class)
            ->clientResource(
                config('canovia_mcp.allowed_client_id'),
                config('canovia_mcp.resource_url'),
            );
        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $owner->id,
            'subject_link_id' => $link->id,
            'plan_id' => $plan->id,
            'client_resource_fingerprint' => $fingerprint,
            'scope' => 'tasks',
            'status' => McpDelegatedGrant::STATUS_ACTIVE,
            'consented_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->get('/account')->assertOk()
            ->assertSee('data-mcp-staging-share-complete', false)
            ->assertSee('ChatGPTが確認できる内容')
            ->assertSee('計画名・タスクの名前・状態・記録上の進捗')
            ->assertSee('ChatGPT側が接続済みかどうか')
            ->assertDontSee('data-mcp-staging-dual-client-guide', false)
            ->assertDontSee('data-mcp-staging-plan-consent', false);

        // Revocation updates the honest status; the guide cannot report a
        // completed ChatGPT login solely from a Canovia-side grant.
        $grant->update([
            'status' => McpDelegatedGrant::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
        $this->get('/account')->assertOk()
            ->assertSee('計画の共有：許可していません')
            ->assertDontSee('data-mcp-staging-active-grant', false)
            ->assertDontSee('data-mcp-staging-share-complete', false);

        config(['canovia_staging.synthetic_read_pilot_enabled' => false]);
        $this->get('/account')->assertOk()
            ->assertDontSee('data-mcp-staging-dual-client-guide', false);
    }

    private function enableConsent(): void
    {
        config(['canovia_staging.plan_consent_pilot_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => true]);
        $this->assertTrue(app(McpStagingLoginPilotPolicy::class)->planConsentReady());
    }

    private function actor(): User
    {
        return User::factory()->create([
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'first_run_completed_at' => now(),
        ]);
    }

    private function fixture(User $actor, string $title = McpStagingSyntheticPlanFixtureBootstrap::TITLE,
        string $requestId = McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID): Plan
    {
        return Plan::query()->create([
            'user_id' => $actor->id,
            'owner_token' => Str::random(64),
            'creation_request_id' => $requestId,
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => '個人開発',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(14),
            'is_public' => false, 'is_collaborative' => false,
        ]);
    }

    private function link(User $owner): string
    {
        $fingerprint = app(McpDelegatedIdentityFingerprintService::class)
            ->subject(self::ISSUER, 'stage-synthetic-external-subject');
        McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'identity_fingerprint' => $fingerprint,
            'provider_key' => 'chatgpt',
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
        ]);

        return $fingerprint;
    }

    private function metadata(): void
    {
        Http::fake([self::ISSUER.'.well-known/openid-configuration' => Http::response([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER.'authorize',
            'token_endpoint' => self::ISSUER.'oauth/token',
            'authorization_response_iss_parameter_supported' => true,
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ], 200, ['Content-Type' => 'application/json'])]);
    }
}
