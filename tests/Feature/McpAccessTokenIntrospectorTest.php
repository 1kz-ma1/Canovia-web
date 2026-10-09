<?php

namespace Tests\Feature;

use App\Services\McpAccessTokenIntrospector;
use App\Services\McpProtectedResourceConfiguration;
use App\Services\McpVerifiedTokenPrincipal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * No database or user sessions are needed. Token validation MUST NOT resolve
 * a Canovia User, produce a consent grant or return private Plan content.
 */
final class McpAccessTokenIntrospectorTest extends TestCase
{
    private const TOKEN = 'opaque-verified-access-token-example';
    private const ENDPOINT = 'https://auth.example.test/tenant/oauth2/introspect';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => 'https://canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://auth.example.test/tenant',
            'canovia_mcp.read_scope' => McpProtectedResourceConfiguration::READ_SCOPE,
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => self::ENDPOINT,
            'canovia_mcp.introspection_client_id' => 'canovia-api-verifier',
            'canovia_mcp.introspection_client_secret' => 'test-secret-introspection-only',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
        ]);
        Http::preventStrayRequests();
    }

    public function test_verified_opaque_token_requires_idp_basic_auth_and_exact_claims(): void
    {
        $this->respond($this->claims());

        $principal = app(McpAccessTokenIntrospector::class)->verify(self::TOKEN);

        $this->assertInstanceOf(McpVerifiedTokenPrincipal::class, $principal);
        $this->assertSame('https://auth.example.test/tenant', $principal->issuer);
        $this->assertSame('provider-immutable-subject-72', $principal->subject);
        $this->assertSame('https://chatgpt.com/oauth/client.json', $principal->clientId);
        $this->assertSame('https://canovia.example.test/api/mcp', $principal->audience);
        $this->assertSame(['canovia.development.read', 'openid'], $principal->scopes);
        $this->assertGreaterThan(now()->timestamp, $principal->expiresAt);

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request) =>
            $request->url() === self::ENDPOINT
            && $request->method() === 'POST'
            && $request['token'] === self::TOKEN
            && $request['token_type_hint'] === 'access_token'
            && $request->hasHeader(
                'Authorization',
                'Basic '.base64_encode('canovia-api-verifier:test-secret-introspection-only'),
            )
        );
    }

    public function test_resource_url_client_id_is_encoded_per_rfc6749_for_keycloak_audience_binding(): void
    {
        // Keycloak >=26.6.2 permits token introspection only when the
        // introspecting client appears in aud. The resource-server client
        // therefore uses the exact resource URL as its OAuth client ID;
        // ordinary unencoded Basic would misread only "https" as the ID.
        $resourceClientId = 'https://canovia.example.test/api/mcp';
        $clientSecret = 'stage-test-client-secret:/%+?';
        config([
            'canovia_mcp.introspection_client_id' => $resourceClientId,
            'canovia_mcp.introspection_client_secret' => $clientSecret,
        ]);
        $this->respond($this->claims());

        $principal = app(McpAccessTokenIntrospector::class)->verify(self::TOKEN);
        $this->assertInstanceOf(McpVerifiedTokenPrincipal::class, $principal);
        $this->assertSame($resourceClientId, $principal->audience);

        $expected = 'Basic '.base64_encode(
            rawurlencode($resourceClientId).':'.rawurlencode($clientSecret)
        );
        Http::assertSent(static fn (Request $request) =>
            $request->method() === 'POST'
            && $request->url() === self::ENDPOINT
            && $request->hasHeader('Authorization', $expected)
        );
        Http::assertSentCount(1);
    }

    public function test_disabled_or_incomplete_idp_settings_fail_without_network_activity(): void
    {
        $variants = [
            ['canovia_mcp.token_introspection_enabled' => false],
            ['canovia_mcp.discovery_enabled' => false],
            ['canovia_mcp.resource_url' => 'http://canovia.example.test/api/mcp'],
            ['canovia_mcp.oauth_issuer' => 'http://auth.example.test/tenant'],
            ['canovia_mcp.introspection_url' => 'http://auth.example.test/token'],
            ['canovia_mcp.introspection_url' => 'https://evil.example.test/introspect'],
            ['canovia_mcp.introspection_url' => 'https://auth.example.test/introspect?token=evil'],
            ['canovia_mcp.introspection_url' => 'https://user:pass@auth.example.test/introspect'],
            ['canovia_mcp.introspection_url' => 'https://auth.example.test:8443/introspect'],
            ['canovia_mcp.introspection_url' => 'https://auth.example.test/introspect#fragment'],
            ['canovia_mcp.introspection_client_id' => ''],
            ['canovia_mcp.introspection_client_secret' => 'short'],
            ['canovia_mcp.allowed_client_id' => ''],
        ];

        foreach ($variants as $changes) {
            $baseline = [];
            foreach ($changes as $key => $value) {
                $baseline[$key] = config($key);
            }
            config($changes);
            $this->assertNull(
                app(McpAccessTokenIntrospector::class)->verify(self::TOKEN),
                'A missing or untrusted IdP setting must fail closed.',
            );
            config($baseline);
        }
        Http::assertNothingSent();
    }

    public function test_active_response_must_bind_issuer_resource_client_expiry_scope_and_subject(): void
    {
        $valid = $this->claims();
        $variants = [
            ['active' => false],
            ['active' => 'true'],
            ['iss' => 'https://evil.example.test'],
            ['iss' => null],
            ['client_id' => 'https://attacker.example.test/client.json'],
            ['client_id' => null],
            ['aud' => 'https://other.example.test/api/mcp'],
            ['aud' => ['https://canovia.example.test/api/mcp', 'https://other.example.test/api/mcp']],
            ['aud' => null],
            ['sub' => ''],
            ['sub' => '  trimmed-sub  '],
            ['sub' => null],
            ['scope' => 'canovia.development.read:write'],
            ['scope' => 'canovia.development'],
            ['scope' => ['canovia.development.read']],
            ['scope' => 'openid\ncanovia.development.read'],
            ['exp' => now()->timestamp - 1],
            ['exp' => now()->timestamp + 7200],
            ['exp' => (string) (now()->timestamp + 1200)],
            ['iat' => now()->timestamp + 50],
            ['iat' => now()->timestamp - 5000],
            ['nbf' => now()->timestamp + 50],
            ['token_type' => 'refresh_token'],
        ];

        $sequence = Http::fakeSequence();
        foreach ($variants as $difference) {
            $sequence->push(array_replace($valid, $difference), 200, [
                'Content-Type' => 'application/json',
            ]);
        }
        foreach ($variants as $difference) {
            $this->assertNull(
                app(McpAccessTokenIntrospector::class)->verify(self::TOKEN),
                'An invalid/missing claim must never become a principal: '.json_encode($difference),
            );
        }
    }

    public function test_non_json_redirect_provider_failure_and_excess_response_all_fail_closed(): void
    {
        $sequence = Http::fakeSequence();
        foreach ([
            [200, 'text/html', '{"active":true}'],
            [302, 'application/json', '{"active":true}'],
            [401, 'application/json', '{"active":true}'],
            [503, 'application/json', '{"active":true}'],
            [200, 'application/json', str_repeat('a', 13000)],
            [200, 'application/json', '{"active":true}'],
        ] as [$status, $contentType, $body]) {
            $sequence->push($body, $status, ['Content-Type' => $contentType]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->assertNull(app(McpAccessTokenIntrospector::class)->verify(self::TOKEN));
        }
    }

    public function test_idp_network_exception_is_not_exposed_or_trusted(): void
    {
        Http::fake([self::ENDPOINT => static function () {
            throw new RuntimeException('Simulated network failure with potentially sensitive details');
        }]);
        $this->assertNull(app(McpAccessTokenIntrospector::class)->verify(self::TOKEN));
    }

    public function test_invalid_token_is_never_sent_to_the_provider(): void
    {
        foreach ([
            '',
            'tiny',
            "forged-bearer-token\r\nInjected: 1",
            'Bearer '.self::TOKEN,
            str_repeat('x', 8200),
        ] as $token) {
            $this->assertNull(app(McpAccessTokenIntrospector::class)->verify($token));
        }
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'active' => true,
            'iss' => 'https://auth.example.test/tenant',
            'sub' => 'provider-immutable-subject-72',
            'client_id' => 'https://chatgpt.com/oauth/client.json',
            'aud' => 'https://canovia.example.test/api/mcp',
            'token_type' => 'Bearer',
            'exp' => now()->timestamp + 1200,
            'iat' => now()->timestamp - 60,
            'nbf' => now()->timestamp - 20,
            'scope' => 'canovia.development.read openid',
        ];
    }

    /** @param array<string, mixed> $claims */
    private function respond(array $claims): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            $claims, 200, ['Content-Type' => 'application/json'],
        )]);
    }
}
