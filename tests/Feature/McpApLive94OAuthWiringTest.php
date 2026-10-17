<?php

namespace Tests\Feature;

use App\Services\McpOAuthAccountLinkProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No real Auth0/ChatGPT credentials, user records or production requests.
 * The provider must be pinned to the production-style Auth0 metadata, issue
 * GitHub-only PKCE S256 authorization, and refuse mixed verifier modes.
 */
final class McpApLive94OAuthWiringTest extends TestCase
{
    private const ISSUER = 'https://synthetic-prod.jp.auth0.com/';
    private const RESOURCE = 'https://synthetic-canovia.example.test/api/mcp';
    private const META = self::ISSUER.'.well-known/openid-configuration';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.allowed_client_id' => 'tpc_synthetic_chatgpt',
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.ap_pilot_enabled' => true,
            'canovia_mcp.ap_pilot_owner_id' => '1',
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_github_only_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'synthetic-private-link-client',
            'canovia_mcp.account_link_client_secret' => 'synthetic-never-production-secret',
            'canovia_mcp.account_link_metadata_url' => self::META,
            'canovia_mcp.account_link_authorization_endpoint' => self::ISSUER.'authorize',
            'canovia_mcp.account_link_token_endpoint' => self::ISSUER.'oauth/token',
            'canovia_mcp.account_link_redirect_uri' =>
                'https://synthetic-canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('x', 48),
        ]);
    }

    private function metadata(bool $withS256 = true): void
    {
        Http::fake([
            self::META => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'authorize',
                'token_endpoint' => self::ISSUER.'oauth/token',
                'authorization_response_iss_parameter_supported' => true,
                'code_challenge_methods_supported' => $withS256 ? ['S256'] : ['plain'],
                'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
                // Normal Auth0 OIDC discovery omits the custom API permission.
                'scopes_supported' => ['openid', 'profile', 'email'],
            ], 200, ['Content-Type' => 'application/json']),
        ]);
    }

    public function test_auth0_private_link_uses_exact_resource_pkce_and_github_selection(): void
    {
        $this->metadata();
        $provider = app(McpOAuthAccountLinkProvider::class);
        $this->assertTrue($provider->isProviderVerified());

        $url = $provider->authorizationUrl(str_repeat('s', 43), str_repeat('c', 43));
        $this->assertNotNull($url);
        $this->assertSame(self::ISSUER.'authorize', substr($url, 0, strpos($url, '?')));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('github', $query['connection']);
        $this->assertSame('login', $query['prompt']);
        $this->assertSame(self::RESOURCE, $query['resource']);
        $this->assertSame(self::RESOURCE, $query['audience']);
        $this->assertSame('canovia.development.read', $query['scope']);
        $this->assertSame('synthetic-private-link-client', $query['client_id']);
        $this->assertSame('https://synthetic-canovia.example.test/account/mcp/link/callback', $query['redirect_uri']);
        Http::assertSentCount(1);
    }

    public function test_bad_pkce_metadata_is_rejected(): void
    {
        $this->metadata(false);
        $this->assertFalse(app(McpOAuthAccountLinkProvider::class)->isProviderVerified());
    }

    public function test_disabled_and_mixed_verifier_modes_fail_closed_without_requests(): void
    {
        foreach ([
            ['canovia_mcp.auth0_jwt_verification_enabled' => false],
            ['canovia_mcp.token_introspection_enabled' => true],
            ['canovia_mcp.token_verification_driver' => 'introspection'],
            ['canovia_mcp.account_link_enabled' => false],
        ] as $flags) {
            $defaults = config('canovia_mcp');
            config($flags);
            $this->assertNull(
                app(McpOAuthAccountLinkProvider::class)
                    ->authorizationUrl(str_repeat('s', 43), str_repeat('c', 43))
            );
            config(['canovia_mcp' => $defaults]);
        }
        Http::assertNothingSent();
    }
}
