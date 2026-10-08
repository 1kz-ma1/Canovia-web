<?php

namespace Tests\Feature;

use App\Services\McpStagingProviderPreflightService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A staging metadata smoke test is NOT a real ChatGPT OAuth integration.
 * All provider responses here are synthetic and no bearer is ever created.
 */
final class McpStagingProviderPreflightTest extends TestCase
{
    private const METADATA = 'https://idp.example.test/.well-known/oauth-authorization-server';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.resource_url' => 'https://staging-canovia.example.test/api/mcp',
            'canovia_mcp.oauth_issuer' => 'https://idp.example.test/tenant',
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.token_introspection_enabled' => true,
            'canovia_mcp.introspection_url' => 'https://idp.example.test/oauth/introspect',
            'canovia_mcp.introspection_client_id' => 'staging-mcp-introspection-client',
            'canovia_mcp.introspection_client_secret' => 'introspection-STAGING-private-secret',
            'canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json',
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.identity_fingerprint_key' => str_repeat('x', 48),
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.account_link_client_id' => 'staging-canovia-confidential-client',
            'canovia_mcp.account_link_client_secret' => 'STAGING-very-private-client-secret',
            'canovia_mcp.account_link_metadata_url' => self::METADATA,
            'canovia_mcp.account_link_authorization_endpoint' => 'https://idp.example.test/oauth/authorize',
            'canovia_mcp.account_link_token_endpoint' => 'https://idp.example.test/oauth/token',
            'canovia_mcp.account_link_redirect_uri' => 'https://staging-canovia.example.test/account/mcp/link/callback',
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    public function test_default_cli_checks_local_only_and_never_calls_provider(): void
    {
        $exit = Artisan::call('canovia:mcp-staging-preflight', ['--json' => true]);
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('canovia.mcp.staging_provider_preflight.v1', $result['schema']);
        $this->assertSame('pass', $result['local_checks']['resource_discovery']);
        $this->assertSame('not_probed', $result['provider_checks']['oauth_discovery']);
        $this->assertFalse($result['preflight_passed']);
        $this->assertFalse($result['production_authorized']);
        $this->assertContains('test_actual_chatgpt_resource_bound_tokens_and_introspection_claims',
            $result['remaining_external_checks']);
        Http::assertNothingSent();

        $this->assertStringNotContainsString('STAGING-private-secret', Artisan::output());
        $this->assertStringNotContainsString('private-client-secret', Artisan::output());
        $this->assertStringNotContainsString('introspection-STAGING', Artisan::output());
        $this->assertStringNotContainsString('staging-canovia-confidential-client', Artisan::output());
    }

    public function test_compatible_metadata_reports_cimd_and_dcr_without_claiming_prod_authorization(): void
    {
        Http::fake([self::METADATA => Http::response(
            $this->metadata(), 200, ['Content-Type' => 'application/json'],
        )]);

        $exit = Artisan::call('canovia:mcp-staging-preflight', [
            '--probe-metadata' => true, '--json' => true,
        ]);
        $result = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($result['preflight_passed']);
        $this->assertFalse($result['production_authorized']);
        $this->assertSame('pass', $result['provider_checks']['oauth_discovery']);
        $this->assertSame('pass', $result['provider_checks']['issuer_and_endpoints']);
        $this->assertSame('pass', $result['provider_checks']['pkce_and_callback_issuer']);
        $this->assertSame('pass', $result['provider_checks']['introspection_endpoint']);
        $this->assertSame('pass', $result['provider_checks']['chatgpt_client_registration']);
        $this->assertSame(['cimd', 'dcr'], $result['supported_client_modes']);

        Http::assertSentCount(1);
        Http::assertSent(static fn ($request) =>
            $request->url() === self::METADATA
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization')
        );

        $output = Artisan::output();
        foreach (['private-secret', 'private-client-secret', 'idp.example.test',
            'chatgpt.com', 'staging-canovia.example.test'] as $neverPrint) {
            $this->assertStringNotContainsString($neverPrint, $output);
        }
    }

    public function test_off_switch_or_dangerous_url_never_causes_any_external_request(): void
    {
        foreach ([
            ['canovia_mcp.account_link_enabled' => false],
            ['canovia_mcp.discovery_enabled' => false],
            ['canovia_mcp.account_link_metadata_url' => 'http://idp.example.test/metadata'],
            ['canovia_mcp.account_link_metadata_url' => 'https://evil.example.test/metadata'],
            ['canovia_mcp.account_link_metadata_url' => 'https://idp.example.test/metadata?url=http://169.254.169.254'],
            ['canovia_mcp.account_link_client_secret' => 'short'],
            ['canovia_mcp.identity_fingerprint_key' => 'weak'],
        ] as $changes) {
            $previous = [];
            foreach ($changes as $key => $value) {
                $previous[$key] = config($key);
            }
            config($changes);
            $out = app(McpStagingProviderPreflightService::class)->check(true);
            $this->assertFalse($out['preflight_passed']);
            $this->assertSame('not_probed', $out['provider_checks']['oauth_discovery']);
            config($previous);
        }

        Http::assertNothingSent();
    }

    public function test_spoofed_or_incompatible_issuer_metadata_fails_for_each_security_dimension(): void
    {
        $changes = [
            ['issuer' => 'https://other-idp.example.test/tenant'],
            ['authorization_endpoint' => 'https://evil.example.test/authorize'],
            ['token_endpoint' => 'https://evil.example.test/token'],
            ['authorization_response_iss_parameter_supported' => false],
            ['code_challenge_methods_supported' => ['plain']],
            ['token_endpoint_auth_methods_supported' => ['none']],
            ['scopes_supported' => ['unrelated.read']],
            ['introspection_endpoint' => 'https://idp.example.test/oauth/unknown'],
            [
                'client_id_metadata_document_supported' => false,
                'registration_endpoint' => 'https://evil.example.test/register',
            ],
        ];

        $sequence = Http::fakeSequence();
        foreach ($changes as $diff) {
            $sequence->push(array_replace($this->metadata(), $diff), 200, [
                'Content-Type' => 'application/json',
            ]);
        }

        foreach ($changes as $diff) {
            $report = app(McpStagingProviderPreflightService::class)->check(true);
            $this->assertFalse($report['preflight_passed'],
                'Provider metadata must fail on: '.implode(',', array_keys($diff)));
            $this->assertContains('blocked', array_values($report['provider_checks']));
            $this->assertFalse($report['production_authorized']);
        }
        Http::assertSentCount(count($changes));
    }

    public function test_malformed_redirect_and_unavailable_provider_fail_without_credential_leak(): void
    {
        $sequence = Http::fakeSequence();
        $sequence->push('not JSON', 200, ['Content-Type' => 'text/html']);
        $sequence->push('{"issuer":}', 200, ['Content-Type' => 'application/json']);
        $sequence->push(str_repeat('x', 16000), 200, ['Content-Type' => 'application/json']);
        $sequence->push('{}', 302, ['Content-Type' => 'application/json']);

        for ($i = 0; $i < 4; $i++) {
            $r = app(McpStagingProviderPreflightService::class)->check(true);
            $this->assertFalse($r['preflight_passed']);
            $this->assertSame('blocked', $r['provider_checks']['oauth_discovery']);
            $this->assertFalse($r['production_authorized']);
        }
    }

    public function test_unavailable_network_returns_blocked_without_exception_detail(): void
    {
        Http::fake([self::METADATA => static function () {
            throw new RuntimeException('Token and client secret should never be printed');
        }]);

        $exit = Artisan::call('canovia:mcp-staging-preflight', [
            '--probe-metadata' => true, '--json' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('client secret', Artisan::output());
        $this->assertSame('blocked',
            json_decode(Artisan::output(), true)['provider_checks']['oauth_discovery']);
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        return [
            'issuer' => 'https://idp.example.test/tenant',
            'authorization_endpoint' => 'https://idp.example.test/oauth/authorize',
            'token_endpoint' => 'https://idp.example.test/oauth/token',
            'introspection_endpoint' => 'https://idp.example.test/oauth/introspect',
            'authorization_response_iss_parameter_supported' => true,
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [
                'none', 'client_secret_basic', 'private_key_jwt',
            ],
            'scopes_supported' => ['canovia.development.read', 'openid'],
            'client_id_metadata_document_supported' => true,
            'registration_endpoint' => 'https://idp.example.test/oauth/register',
        ];
    }
}
