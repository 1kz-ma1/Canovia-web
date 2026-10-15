<?php

namespace Tests\Feature;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpExternalTokenVerifier;
use App\Services\McpOAuthAccountLinkProvider;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpAuth0JwtProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://canovia-mcp-dev.jp.auth0.com/';
    private const RESOURCE = 'https://canovia-mcp-staging.onrender.com/api/mcp';
    private const CHATGPT_CLIENT = 'internal-auth0-cimd-client-test';
    private const LINK_CLIENT = 'canovia-link-private-client-test';
    private const JWKS = self::ISSUER.'.well-known/jwks.json';
    private const META = self::ISSUER.'.well-known/oauth-authorization-server';
    private const EXCHANGE = self::ISSUER.'oauth/token';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        Http::preventStrayRequests();

        config([
            'native_ai.driver' => 'disabled',
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.allowed_client_id' => self::CHATGPT_CLIENT,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('k', 48),
            'canovia_mcp.account_link_client_id' => self::LINK_CLIENT,
            'canovia_mcp.account_link_client_secret' => 'synthetic-private-client-test-secret',
            'canovia_mcp.account_link_metadata_url' => self::META,
            'canovia_mcp.account_link_authorization_endpoint' => self::ISSUER.'authorize',
            'canovia_mcp.account_link_token_endpoint' => self::EXCHANGE,
            'canovia_mcp.account_link_redirect_uri' => 'https://canovia-mcp-staging.onrender.com/account/mcp/link/callback',
        ]);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->privateKey = $pem;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $this->jwk = [
            'kid' => 'synthetic-provider-key',
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => self::b64($rsa['n']),
            'e' => self::b64($rsa['e']),
        ];
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $changes */
    private function token(string $client = self::CHATGPT_CLIENT, array $changes = []): string
    {
        return JWT::encode(array_replace([
            'iss' => self::ISSUER,
            'sub' => 'auth0|synthetic-user-1',
            'aud' => self::RESOURCE,
            'azp' => $client,
            'scope' => 'canovia.development.read',
            'iat' => time() - 10,
            'nbf' => time() - 5,
            'exp' => time() + 900,
        ], $changes), $this->privateKey, 'RS256', $this->jwk['kid']);
    }

    private function stubJwks(): void
    {
        Http::fake([
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);
    }

    private function listTools(?string $token = null)
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ], $headers);
    }

    public function test_signed_chatgpt_jwt_reaches_only_existing_read_only_tool_descriptor(): void
    {
        $this->stubJwks();
        $this->assertTrue(app(McpExternalTokenVerifier::class)->isConfigured());
        $this->listTools($this->token())
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'canovia_get_development_plan_context')
            ->assertJsonPath('result.tools.0.securitySchemes.0.scopes', ['canovia.development.read']);
        Http::assertSentCount(1);
    }

    public function test_verified_signed_auth0_jwt_requires_approved_plan_and_revocation_takes_effect(): void
    {
        $this->stubJwks();
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Synthetic JWT-approved development',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(4),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Synthetic signed token task',
            'status' => 'doing',
            'progress_percent' => 30,
            'priority' => 1,
            'sort_order' => 1,
        ]);

        $token = $this->token();
        $invoke = fn (?string $bearer = null) => $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'canovia_get_development_plan_context',
                'arguments' => ['plan_id' => $plan->id, 'scope' => 'tasks', 'limit' => 2],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.($bearer ?? $token),
        ]);

        // A valid signed token alone never authorizes private Plan content.
        $invoke()->assertOk()->assertJsonPath('result.isError', true);

        $fingerprints = app(McpDelegatedIdentityFingerprintService::class);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'identity_fingerprint' => $fingerprints->subject(self::ISSUER, 'auth0|synthetic-user-1'),
            'provider_key' => 'chatgpt',
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'subject_link_id' => $link->id,
            'client_resource_fingerprint' => $fingerprints->clientResource(self::CHATGPT_CLIENT, self::RESOURCE),
            'scope' => 'tasks',
            'status' => 'active',
            'consented_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $invoke()->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', 'Synthetic JWT-approved development')
            ->assertJsonPath('result.structuredContent.tasks.0.title', 'Synthetic signed token task');

        $invoke($this->token(self::LINK_CLIENT))->assertUnauthorized();
        $grant->update(['scope' => 'overview']);
        $invoke()->assertOk()->assertJsonPath('result.isError', true);
        $grant->update(['scope' => 'tasks', 'revoked_at' => now(), 'status' => 'revoked']);
        $invoke()->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_signed_wrong_client_and_wrong_scope_fail_with_auth_challenge(): void
    {
        $this->stubJwks();

        $this->listTools($this->token(self::LINK_CLIENT))
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate');
        $this->listTools($this->token(self::CHATGPT_CLIENT, ['scope' => 'openid']))
            ->assertStatus(401);
        $this->listTools()
            ->assertStatus(401);
    }

    public function test_invalid_modes_and_switch_overlap_reject_even_valid_signature_without_outbound_requests(): void
    {
        $cases = [
            ['canovia_mcp.token_verification_driver' => 'unrecognized'],
            ['canovia_mcp.token_verification_driver' => 'introspection'],
            ['canovia_mcp.token_introspection_enabled' => true],
            ['canovia_mcp.auth0_jwt_verification_enabled' => false],
        ];

        $jwt = $this->token();
        foreach ($cases as $values) {
            $before = [];
            foreach (array_keys($values) as $key) {
                $before[$key] = config($key);
            }
            config($values);
            $this->assertFalse(app(McpExternalTokenVerifier::class)->isConfigured());
            $this->assertNotEquals(200, $this->listTools($jwt)->status());
            config($before);
        }
        Http::assertNothingSent();

        config(['canovia_mcp.tools_enabled' => false]);
        $this->assertNotEquals(200, $this->listTools($jwt)->status());
        Http::assertNothingSent();
    }

    public function test_confidential_link_token_cannot_be_reused_for_chatgpt_resource(): void
    {
        $this->stubJwks();

        $service = app(McpExternalTokenVerifier::class);
        $link = $this->token(self::LINK_CLIENT);
        $chatgpt = $this->token();

        $this->assertNull($service->verify($link));
        $this->assertNull($service->verifyAccountLink($chatgpt));
        $principal = $service->verifyAccountLink($link);
        $this->assertNotNull($principal);
        $this->assertSame(self::LINK_CLIENT, $principal->clientId);
        $this->assertSame('auth0|synthetic-user-1', $principal->subject);
    }

    public function test_auth0_public_metadata_does_not_need_api_specific_scope_but_signed_link_does(): void
    {
        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::EXCHANGE,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'none'],
                // Real Auth0 OIDC discovery lists only standard OIDC scopes.
                'scopes_supported' => ['openid', 'profile', 'email'],
            ], 200, ['Content-Type' => 'application/json']),
            self::EXCHANGE => Http::response([
                'access_token' => $this->token(self::LINK_CLIENT),
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ], 200, ['Content-Type' => 'application/json']),
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);

        $provider = app(McpOAuthAccountLinkProvider::class);
        $this->assertTrue($provider->isProviderVerified());

        $verified = $provider->exchangeAndVerify(str_repeat('c', 30), str_repeat('v', 43));
        $this->assertNotNull($verified);
        $this->assertSame(self::LINK_CLIENT, $verified->clientId);

        // A grant still requires the separate session-bound, explicit
        // per-Plan human confirmation; this flow does not create one.
    }

    public function test_github_only_oauth_route_requires_signed_github_primary_subject(): void
    {
        $provider = app(McpOAuthAccountLinkProvider::class);
        $state = str_repeat('s', 43);
        $challenge = str_repeat('c', 43);
        $generic = $provider->authorizationUrl($state, $challenge);
        $this->assertNotNull($generic);
        parse_str((string) parse_url($generic, PHP_URL_QUERY), $genericParams);
        $this->assertArrayNotHasKey('connection', $genericParams);
        $this->assertArrayNotHasKey('prompt', $genericParams);

        config(['canovia_mcp.account_link_github_only_enabled' => true]);
        $this->assertTrue(app(\App\Services\McpOAuthAccountLinkConfiguration::class)->githubOnlyEnabled());

        $target = $provider->authorizationUrl($state, $challenge);
        $this->assertNotNull($target);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame('github', $query['connection']);
        $this->assertSame('login', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(self::LINK_CLIENT, $query['client_id']);
        $this->assertSame(self::RESOURCE, $query['audience']);
        $this->assertSame(self::RESOURCE, $query['resource']);

        // A single HTTP fake must derive a fresh signed token for each
        // case. Repeated Http::fake calls append callbacks and can reuse the
        // first response, incorrectly making wrong-provider cases pass.
        $currentSubject = 'github|123456789';
        Http::fake([
            self::EXCHANGE => function () use (&$currentSubject) {
                return Http::response([
                    'access_token' => $this->token(self::LINK_CLIENT, ['sub' => $currentSubject]),
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                ], 200, ['Content-Type' => 'application/json']);
            },
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);
        foreach ([
            ['github|123456789', true],
            ['auth0|123456789', false],
            ['google-oauth2|123456789', false],
            ['github|not-numeric', false],
            ['github|', false],
        ] as [$subject, $accepted]) {
            $currentSubject = $subject;
            Cache::flush();
            $principal = $provider->exchangeAndVerify(str_repeat('c', 30), str_repeat('v', 43));
            $this->assertSame($accepted, $principal !== null, 'unexpected GitHub-only subject decision');
            if ($accepted) {
                $this->assertSame($subject, $principal->subject);
            }
        }

        // A GitHub-only operator setting must not silently fall back to a
        // different, unverified OAuth mechanism.
        config(['canovia_mcp.token_verification_driver' => 'introspection']);
        $this->assertNull($provider->authorizationUrl($state, $challenge));
    }

    public function test_github_only_link_requires_callback_and_second_explicit_owner_confirmation(): void
    {
        config(['canovia_mcp.account_link_github_only_enabled' => true]);
        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::EXCHANGE,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['openid', 'profile'],
            ], 200, ['Content-Type' => 'application/json']),
            self::EXCHANGE => Http::response([
                'access_token' => $this->token(self::LINK_CLIENT, ['sub' => 'github|34567890']),
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ], 200, ['Content-Type' => 'application/json']),
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);

        $start = $this->actingAs($owner)
            ->post(route('auth.account.mcp_link.start'))->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('github', $query['connection']);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->actingAs($owner)->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $query['state'],
            'iss' => self::ISSUER,
            'code' => 'synthetic-valid-github-code-123456789',
        ]))->assertRedirect(route('auth.account'));

        $this->assertTrue(session('mcp.account_link.verified.github_only_proof'));
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->actingAs($owner)->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $link = McpLinkedSubject::query()->sole();
        $this->assertSame(
            app(McpDelegatedIdentityFingerprintService::class)->subject(self::ISSUER, 'github|34567890'),
            $link->identity_fingerprint,
        );
    }

    public function test_fresh_github_only_plan_consent_requires_distinct_verified_callback_and_final_post(): void
    {
        config(['canovia_mcp.account_link_github_only_enabled' => true]);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_GITHUB_CONSENT_ONLY',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(4),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => app(McpDelegatedIdentityFingerprintService::class)
                ->subject(self::ISSUER, 'github|34567890'),
            'status' => 'linked',
            'linked_at' => now(),
        ]);
        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::EXCHANGE,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['openid', 'profile'],
            ], 200, ['Content-Type' => 'application/json']),
            self::EXCHANGE => Http::response([
                'access_token' => $this->token(self::LINK_CLIENT, ['sub' => 'github|34567890']),
                'token_type' => 'Bearer', 'expires_in' => 900,
            ], 200, ['Content-Type' => 'application/json']),
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);

        $start = $this->actingAs($owner)->post(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'overview', 'duration_days' => 7],
        )->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('github', $query['connection']);
        $this->assertSame('login', $query['prompt']);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->actingAs($owner)->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $query['state'], 'iss' => self::ISSUER,
            'code' => 'synthetic-fresh-consent-123456789',
        ]))->assertRedirect(route('auth.account'));
        $this->assertTrue(session('mcp.plan_consent.verified.github_only_proof'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $this->actingAs($owner)->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $grant = McpDelegatedGrant::query()->sole();
        $this->assertSame($owner->id, $grant->user_id);
        $this->assertSame($plan->id, $grant->plan_id);
        $this->assertSame('overview', $grant->scope);
    }

    public function test_github_only_wrong_signed_identity_cannot_inherit_existing_grant_and_revoke_denies_old_token(): void
    {
        // Full OAuth controller + signed third-party JWT read flow in an
        // isolated in-memory database. NEVER use real GitHub identities,
        // Auth0 credentials, Render staging data, or production access.
        // Repeated callback/consent attempts are synthetic adversarial cases,
        // not a rate-limit acceptance test. Preserve the real middleware in
        // production; bypass only this test's HTTP request throttle, as the
        // separate staging consent tests already do.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['canovia_mcp.account_link_github_only_enabled' => true]);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'SYNTHETIC_GITHUB_OVERVIEW_IDENTITY_FENCE',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(4),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
        $originalSubject = 'github|410001';
        $otherSubject = 'github|410002';
        $fingerprints = app(McpDelegatedIdentityFingerprintService::class);
        $link = McpLinkedSubject::query()->create([
            'user_id' => $owner->id,
            'provider_key' => 'chatgpt',
            'identity_fingerprint' => $fingerprints->subject(self::ISSUER, $originalSubject),
            'status' => McpLinkedSubject::STATUS_LINKED,
            'linked_at' => now(),
        ]);
        $grant = McpDelegatedGrant::query()->create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'subject_link_id' => $link->id,
            'client_resource_fingerprint' => $fingerprints->clientResource(self::CHATGPT_CLIENT, self::RESOURCE),
            'scope' => 'overview',
            'status' => McpDelegatedGrant::STATUS_ACTIVE,
            'consented_at' => now(),
            'expires_at' => null,
        ]);

        // Make both synthetic identities validly RS256-signed with identical
        // issuer, audience, scope and APPROVED ChatGPT client. Only the
        // original immutable GitHub subject may use the existing grant.
        $callbackSubject = $otherSubject;
        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::EXCHANGE,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['openid', 'profile'],
            ], 200, ['Content-Type' => 'application/json']),
            self::EXCHANGE => function () use (&$callbackSubject) {
                return Http::response([
                    'access_token' => $this->token(self::LINK_CLIENT, ['sub' => $callbackSubject]),
                    'token_type' => 'Bearer',
                    'expires_in' => 900,
                ], 200, ['Content-Type' => 'application/json']);
            },
            self::JWKS => Http::response(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']),
        ]);

        $read = fn (string $subject) => $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 85,
            'method' => 'tools/call',
            'params' => [
                'name' => 'canovia_get_development_plan_context',
                'arguments' => ['plan_id' => $plan->id, 'scope' => 'overview'],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$this->token(self::CHATGPT_CLIENT, ['sub' => $subject]),
        ]);
        $read($originalSubject)->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.plan.title', $plan->title);
        $read($otherSubject)->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee($plan->title);

        // Follow the exact user-facing path: an existing grant, fresh
        // GitHub-only Plan consent OAuth, callback from ANOTHER GitHub
        // subject. Deny preview; do not replace or revoke the old grant.
        $start = $this->actingAs($owner)->post(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'overview', 'duration_days' => 'until_revoked'],
        )->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('github', $query['connection']);
        $this->assertSame('login', $query['prompt']);

        $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $query['state'],
            'iss' => self::ISSUER,
            'code' => 'synthetic-wrong-github-callback-123456',
        ]))->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.plan_consent.verified'));
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertSame(McpDelegatedGrant::STATUS_ACTIVE, $grant->fresh()->status);
        $this->assertNull($grant->fresh()->expires_at);
        $this->assertSame($link->id, $grant->fresh()->subject_link_id);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $read($originalSubject)->assertOk()->assertJsonPath('result.isError', false);
        $read($otherSubject)->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee($plan->title);

        // The correctly linked GitHub can repeat OAuth, view the preview,
        // and cancel instead of granting. Cancellation must leave the
        // already-consented overview-only grant intact.
        $callbackSubject = $originalSubject;
        $start = $this->post(
            route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]),
            ['scope' => 'overview', 'duration_days' => 'until_revoked'],
        )->assertRedirect();
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $correctQuery);
        $this->get(route('auth.account.mcp_link.callback').'?'.http_build_query([
            'state' => $correctQuery['state'],
            'iss' => self::ISSUER,
            'code' => 'synthetic-correct-github-callback-123456',
        ]))->assertRedirect(route('auth.account'));
        $this->assertTrue(session('mcp.plan_consent.verified.github_only_proof'));
        $this->post(route('auth.account.mcp_plan_consent.cancel'))
            ->assertRedirect(route('auth.account'));
        $this->assertNull(session('mcp.plan_consent.verified'));
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertSame(McpDelegatedGrant::STATUS_ACTIVE, $grant->fresh()->status);
        $read($originalSubject)->assertOk()->assertJsonPath('result.isError', false);

        // Only a separately authorized grant-revocation operation (here
        // restricted to disposable in-memory fixture data) disables even a
        // still-valid ChatGPT bearer. Replay cannot revive that grant.
        $this->delete(route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]))
            ->assertRedirect(route('auth.account'));
        $this->assertSame(McpDelegatedGrant::STATUS_REVOKED, $grant->fresh()->status);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $read($originalSubject)->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee($plan->title);
        $read($otherSubject)->assertOk()->assertJsonPath('result.isError', true)
            ->assertDontSee($plan->title);
        $this->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertSame(McpDelegatedGrant::STATUS_REVOKED, $grant->fresh()->status);
        $this->assertDatabaseCount('mcp_delegated_grants', 1);
        $this->assertDatabaseCount('mcp_linked_subjects', 1);
        $this->assertDatabaseCount('plans', 1);
    }

    public function test_enabling_github_only_after_generic_oauth_does_not_reuse_earlier_confirmation(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($owner)->withSession([
            'mcp.account_link.verified' => [
                'actor_id' => $owner->id,
                'identity_fingerprint' => str_repeat('a', 64),
                'verified_at' => now()->timestamp,
                'github_only_proof' => false,
            ],
        ]);

        config(['canovia_mcp.account_link_github_only_enabled' => true]);
        $this->actingAs($owner)->post(route('auth.account.mcp_link.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_linked_subjects', 0);

        $this->actingAs($owner)->withSession([
            'mcp.plan_consent.verified' => [
                'actor_id' => $owner->id,
                'identity_fingerprint' => str_repeat('a', 64),
                'plan_id' => 1,
                'scope' => 'overview',
                'duration_days' => 7,
                'verified_at' => now()->timestamp,
                'github_only_proof' => false,
            ],
        ])->post(route('auth.account.mcp_plan_consent.confirm'))
            ->assertRedirect(route('auth.account'));
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
    }

    public function test_metadata_missing_scope_never_allows_legacy_introspection_linking(): void
    {
        config([
            'canovia_mcp.token_verification_driver' => 'introspection',
            'canovia_mcp.auth0_jwt_verification_enabled' => false,
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::ISSUER.'oauth/introspect',
            'canovia_mcp.introspection_client_id' => 'synthetic-inspection-client',
            'canovia_mcp.introspection_client_secret' => 'synthetic-inspection-secret',
        ]);

        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::EXCHANGE,
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                'scopes_supported' => ['openid', 'email'],
            ], 200, ['Content-Type' => 'application/json']),
        ]);
        $this->assertFalse(app(McpOAuthAccountLinkProvider::class)->isProviderVerified());
    }
}
