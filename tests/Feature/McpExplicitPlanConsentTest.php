<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpDelegatedPlanAccessPolicy;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpExplicitPlanConsentTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://auth.example.test/tenant';
    private const RESOURCE = 'https://canovia.example.test/api/mcp';
    private const METADATA = 'https://auth.example.test/.well-known/oauth-authorization-server';
    private const AUTHORIZE = 'https://auth.example.test/oauth/authorize';
    private const TOKEN = 'https://auth.example.test/oauth/token';
    private const INTROSPECT = 'https://auth.example.test/oauth/introspect';
    private const ACCOUNT_CLIENT = 'canovia-account-link-client';
    private const CHATGPT_CLIENT = 'https://chatgpt.com/oauth/client.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // This suite intentionally exercises multiple login attempts in a
        // single test; production routes retain their throttle middlewares.
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::INTROSPECT,
            'canovia_mcp.introspection_client_id' => 'canovia-introspection-server',
            'canovia_mcp.introspection_client_secret' => 'server-only-introspection-password',
            'canovia_mcp.allowed_client_id' => self::CHATGPT_CLIENT,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('k', 48),
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => self::ACCOUNT_CLIENT,
            'canovia_mcp.account_link_client_secret' => 'verified-link-client-secret',
            'canovia_mcp.account_link_metadata_url' => self::METADATA,
            'canovia_mcp.account_link_authorization_endpoint' => self::AUTHORIZE,
            'canovia_mcp.account_link_token_endpoint' => self::TOKEN,
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
        ]);
        Http::preventStrayRequests();
    }

    public function test_only_linked_owner_with_enabled_provider_can_start_plan_consent(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);

        DevelopmentAiSharingPreference::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'provider_key' => 'chatgpt', 'scope' => 'tasks',
            'status' => 'prepared', 'expires_at' => now()->addDays(7),
        ]);
        $this->actingAs($owner)->post($this->startUrl($plan), [
            'scope' => 'tasks', 'duration_days' => 7,
        ])->assertRedirect(route('auth.account'));

        $link = $this->linked($owner);
        $this->actingAs($other)->post($this->startUrl($plan), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertNotFound();

        $this->actingAs($owner)->post($this->startUrl($plan), [
            'scope' => 'write', 'duration_days' => 7,
        ])->assertSessionHasErrors('scope');

        $this->actingAs($owner)->post($this->startUrl($plan), [
            'scope' => 'tasks', 'duration_days' => 365,
        ])->assertSessionHasErrors('duration_days');

        foreach ([
            $this->plan($owner, '制作活動'),
            $this->plan($owner, '資格学習'),
            $this->plan($owner, '個人開発', true),
        ] as $forbidden) {
            $this->actingAs($owner)->post($this->startUrl($forbidden), [
                'scope' => 'overview', 'duration_days' => 7,
            ])->assertNotFound();
        }

        config(['canovia_mcp.plan_consent_enabled' => false]);
        $this->actingAs($owner)->post($this->startUrl($plan), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertRedirect(route('auth.account'));

        $this->assertSame('linked', $link->fresh()->status);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        Http::assertNothingSent();
    }

    public function test_scope_and_duration_are_displayed_and_grant_created_only_after_fresh_pkce_and_final_confirm(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $link = $this->linked($owner);
        $this->provider();

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()
            ->assertSee('data-mcp-plan-consent', false)
            ->assertSee('選択したPlanの本人確認へ');

        $query = $this->start($owner, $plan, 'tasks', 1);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::ACCOUNT_CLIENT, $query['client_id']);
        $this->assertSame(self::RESOURCE, $query['resource']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->oauthCallback($query['state'])->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.account_link.pending'));
        $this->assertNull(session('mcp.account_link.verified'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-mcp-plan-consent-confirm', false)
            ->assertSee('対象Plan：Sensitive Development Plan')
            ->assertSee('概要＋進行中Task')
            ->assertSee('承認から1日')
            ->assertSee('このPlanの共有許可を確定')
            ->assertDontSee('immutable-subject-for-owner')
            ->assertDontSee('opaque-access-token-verified');

        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $grant = McpDelegatedGrant::query()->firstOrFail();
        $this->assertSame($owner->id, $grant->user_id);
        $this->assertSame($link->id, $grant->subject_link_id);
        $this->assertSame($plan->id, $grant->plan_id);
        $this->assertSame('active', $grant->status);
        $this->assertSame('tasks', $grant->scope);
        $this->assertNull($grant->revoked_at);
        $this->assertTrue($grant->expires_at->between(
            now()->addDay()->subMinute(), now()->addDay()->addMinute(),
        ));
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'actor_user_id' => $owner->id,
            'subject_link_id' => $link->id,
            'grant_id' => $grant->id,
            'event_kind' => 'grant_consented',
            'scope' => 'tasks',
        ]);
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)->allows(
            $this->chatgptPrincipal(), $plan, 'tasks',
        ));

        // Callback+confirmation evidence cannot be replayed to create grants.
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseCount('mcp_delegated_access_events', 1);

        Http::assertSentCount(3);
        Http::assertSent(static fn (ClientRequest $r) =>
            $r->url() === self::INTROSPECT
            && $r['token'] === 'opaque-access-token-verified'
        );

        // Still NOT connected to an MCP tool, even with valid consent.
        $this->actingAs($owner)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ])->assertUnauthorized();
    }

    public function test_explicit_new_oauth_and_confirm_required_to_downgrade_or_renew_grant(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linked($owner);
        $this->provider();

        $this->oauthCallback($this->start($owner, $plan, 'tasks', 30)['state']);
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();
        $grant = McpDelegatedGrant::query()->firstOrFail();
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)->allows($this->chatgptPrincipal(), $plan, 'tasks'));

        $this->oauthCallback($this->start($owner, $plan, 'overview', 1)['state']);
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();

        $this->assertSame($grant->id, McpDelegatedGrant::query()->firstOrFail()->id);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseHas('mcp_delegated_access_events', [
            'grant_id' => $grant->id, 'event_kind' => 'grant_reconsented', 'scope' => 'overview',
        ]);
        $this->assertTrue(app(McpDelegatedPlanAccessPolicy::class)->allows($this->chatgptPrincipal(), $plan, 'overview'));
        $this->assertFalse(app(McpDelegatedPlanAccessPolicy::class)->allows($this->chatgptPrincipal(), $plan, 'tasks'));
        $this->assertTrue($grant->fresh()->expires_at->between(
            now()->addDay()->subMinute(), now()->addDay()->addMinute(),
        ));
    }

    public function test_cancelled_and_expired_pending_consents_never_issue_access(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linked($owner);
        $this->provider();

        $query = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($query['state'])->assertRedirect();
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.cancel'))->assertRedirect();
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();

        $query = $this->start($owner, $plan, 'tasks', 7);
        $this->travel(6)->minutes();
        $this->oauthCallback($query['state'])->assertRedirect();
        $this->travelBack();

        $query = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($query['state'])->assertRedirect();
        $this->travel(6)->minutes();
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();
        $this->travelBack();

        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_wrong_subject_actor_issuer_or_changed_plan_is_refused_without_grant(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linked($owner);
        $this->provider(subject: 'attacker-other-subject');

        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($q['state'])->assertRedirect();
        $this->assertNull(session('mcp.plan_consent.verified'));

        $this->provider();
        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($q['state'], 'https://wrong.example.test')->assertRedirect();
        $this->assertNull(session('mcp.plan_consent.verified'));

        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->actingAs($other);
        $this->oauthCallback($q['state'])->assertRedirect();
        $this->assertNull(session('mcp.plan_consent.verified'));

        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($q['state'])->assertRedirect();
        $plan->update(['is_collaborative' => true]);
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $plan->update(['is_collaborative' => false]);
        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($q['state'])->assertRedirect();
        $plan->update(['user_id' => $other->id, 'title' => 'NEW_OWNER_PRIVATE_NAME']);
        $this->actingAs($owner)->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('NEW_OWNER_PRIVATE_NAME')
            ->assertDontSee('data-mcp-plan-consent-confirm', false);
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))->assertRedirect();
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_unlink_between_idp_callback_and_confirm_fails_closed(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $link = $this->linked($owner);
        $this->provider();

        $q = $this->start($owner, $plan, 'overview', 7);
        $this->oauthCallback($q['state'])->assertRedirect();
        $this->actingAs($owner)->delete(route('auth.account.mcp_subject.revoke', ['subject' => $link->id]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect();
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
    }

    public function test_introspection_claims_for_the_wrong_client_cannot_become_plan_consent(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $this->linked($owner);
        $this->provider(client: self::CHATGPT_CLIENT);

        $q = $this->start($owner, $plan, 'tasks', 7);
        $this->oauthCallback($q['state'])->assertRedirect();
        $this->assertNull(session('mcp.plan_consent.verified'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    private function plan(
        User $owner,
        string $category = '個人開発',
        bool $collaborative = false,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Sensitive Development Plan',
            'category' => $category,
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(20),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function linked(User $user): McpLinkedSubject
    {
        return McpLinkedSubject::query()->create([
            'user_id' => $user->id,
            'identity_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->subject(self::ISSUER, 'immutable-subject-for-owner'),
            'provider_key' => 'chatgpt', 'status' => 'linked',
            'linked_at' => now(),
        ]);
    }

    private function chatgptPrincipal(): McpVerifiedTokenPrincipal
    {
        return new McpVerifiedTokenPrincipal(
            issuer: self::ISSUER,
            subject: 'immutable-subject-for-owner',
            clientId: self::CHATGPT_CLIENT,
            audience: self::RESOURCE,
            scopes: ['canovia.development.read'],
            expiresAt: now()->timestamp + 1200,
        );
    }

    /** @return array<string, string> */
    private function start(User $user, Plan $plan, string $scope, int $days): array
    {
        $response = $this->actingAs($user)->post($this->startUrl($plan), [
            'scope' => $scope, 'duration_days' => $days,
        ])->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::AUTHORIZE.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        return $query;
    }

    private function startUrl(Plan $plan): string
    {
        return route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]);
    }

    private function oauthCallback(
        string $state,
        ?string $issuer = self::ISSUER,
    ): \Illuminate\Testing\TestResponse {
        $query = ['state' => $state, 'code' => 'valid-provider-consent-code-12345'];
        if ($issuer !== null) $query['iss'] = $issuer;
        return $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query($query));
    }

    private function provider(
        string $subject = 'immutable-subject-for-owner',
        string $client = self::ACCOUNT_CLIENT,
    ): void {
        Http::fake([
            self::METADATA => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::AUTHORIZE,
                'token_endpoint' => self::TOKEN,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['canovia.development.read'],
            ], 200, ['Content-Type' => 'application/json']),
            self::TOKEN => Http::response([
                'token_type' => 'Bearer',
                'access_token' => 'opaque-access-token-verified',
                'expires_in' => 1200,
            ], 200, ['Content-Type' => 'application/json']),
            self::INTROSPECT => Http::response([
                'active' => true, 'iss' => self::ISSUER,
                'sub' => $subject,
                'client_id' => $client,
                'aud' => self::RESOURCE,
                'token_type' => 'Bearer',
                'exp' => now()->timestamp + 1200,
                'iat' => now()->timestamp - 60,
                'nbf' => now()->timestamp - 5,
                'scope' => 'canovia.development.read',
            ], 200, ['Content-Type' => 'application/json']),
        ]);
    }
}
