<?php

namespace Tests\Feature;

use App\Services\McpAuth0SealedStagingWiringReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpAuth0SealedStagingWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'app.url' => 'https://canovia-mcp-staging.onrender.com',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.web_access_explicitly_approved' => false,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'canovia_mcp_staging_db',
            'canovia_mcp.discovery_enabled' => false,
            'canovia_mcp.tools_enabled' => false,
            'canovia_mcp.account_link_enabled' => false,
            'canovia_mcp.plan_consent_enabled' => false,
            'canovia_mcp.delegated_policy_enabled' => false,
            'canovia_mcp.auth0_jwt_verification_enabled' => false,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.oauth_issuer' => 'https://canovia-mcp-dev.jp.auth0.com/',
            'canovia_mcp.resource_url' => 'https://canovia-mcp-staging.onrender.com/api/mcp',
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.allowed_client_id' => 'tpc_synthetic_chatgpt_client',
            'canovia_mcp.account_link_client_id' => 'synthetic_confidential_link_id',
            'canovia_mcp.account_link_client_secret' => str_repeat('a', 32),
            'canovia_mcp.account_link_metadata_url' =>
                'https://canovia-mcp-dev.jp.auth0.com/.well-known/openid-configuration',
            'canovia_mcp.account_link_authorization_endpoint' =>
                'https://canovia-mcp-dev.jp.auth0.com/authorize',
            'canovia_mcp.account_link_token_endpoint' =>
                'https://canovia-mcp-dev.jp.auth0.com/oauth/token',
            'canovia_mcp.account_link_redirect_uri' =>
                'https://canovia-mcp-staging.onrender.com/account/mcp/link/callback',
            'canovia_mcp.identity_fingerprint_key' => str_repeat('z', 48),
        ]);
        Http::preventStrayRequests();
    }

    public function test_closed_stage_configuration_passes_without_exposing_settings(): void
    {
        $exit = Artisan::call('canovia:mcp-auth0-sealed-wiring-check', ['--json' => true]);
        $output = Artisan::output();
        $result = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertTrue($result['configuration_ready']);
        $this->assertCount(9, $result['checks']);
        $this->assertSame(['pass'], array_values(array_unique($result['checks'])));
        foreach ([
            'live_oauth_verified', 'client_grants_verified', 'issued_jwt_verified',
            'stage_activation_authorized', 'production_authorized',
        ] as $key) {
            $this->assertFalse($result[$key]);
        }
        foreach ([
            'tpc_synthetic_chatgpt_client', 'synthetic_confidential_link_id',
            str_repeat('a', 32), str_repeat('z', 48),
            'canovia-mcp-dev.jp.auth0.com',
        ] as $hidden) {
            $this->assertStringNotContainsString($hidden, $output);
        }
        Http::assertNothingSent();
    }

    public function test_production_foreign_clients_bad_oauth_endpoints_or_open_gates_are_blocked(): void
    {
        $cases = [
            ['app.env' => 'production'],
            ['canovia_staging.isolated' => false],
            ['canovia_staging.web_access_enabled' => true],
            ['canovia_staging.database_mode' => 'sqlite'],
            ['canovia_staging.postgres_id' => 'other'],
            ['database.default' => 'mysql'],
            ['canovia_mcp.token_verification_driver' => 'introspection'],
            ['canovia_mcp.oauth_issuer' => 'https://other.example.test/'],
            ['canovia_mcp.allowed_client_id' => 'https://chatgpt.com/oauth/client.json'],
            ['canovia_mcp.account_link_client_id' => 'tpc_synthetic_chatgpt_client'],
            ['canovia_mcp.account_link_client_secret' => 'short'],
            ['canovia_mcp.account_link_token_endpoint' => 'https://other.example.test/token'],
            ['canovia_mcp.account_link_redirect_uri' => 'https://other.example.test/callback'],
            ['canovia_mcp.identity_fingerprint_key' => 'short'],
        ];
        foreach ($cases as $change) {
            $old = [];
            foreach ($change as $key => $value) {
                $old[$key] = config($key);
            }
            config($change);
            $result = app(McpAuth0SealedStagingWiringReadiness::class)->check();
            $this->assertFalse($result['configuration_ready'], implode(',', array_keys($change)));
            $this->assertContains('blocked', array_values($result['checks']));
            $this->assertFalse($result['production_authorized']);
            config($old);
        }

        foreach ([
            'canovia_mcp.discovery_enabled', 'canovia_mcp.account_link_enabled',
            'canovia_mcp.auth0_jwt_verification_enabled',
            'canovia_mcp.token_introspection_enabled',
            'canovia_mcp.plan_consent_enabled',
            'canovia_mcp.delegated_policy_enabled',
            'canovia_mcp.tools_enabled',
        ] as $flag) {
            config([$flag => true]);
            $result = app(McpAuth0SealedStagingWiringReadiness::class)->check();
            $this->assertFalse($result['configuration_ready'], $flag);
            $this->assertSame('blocked', $result['checks']['oauth_mcp_capabilities_disabled']);
            config([$flag => false]);
        }
        Http::assertNothingSent();
    }
}
