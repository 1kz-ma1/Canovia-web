<?php

namespace Tests\Feature;

use App\Services\McpExternalTokenVerifier;
use App\Services\McpOAuthAccountLinkProvider;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpAuth0JwtProviderRoutingTest extends TestCase
{
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
