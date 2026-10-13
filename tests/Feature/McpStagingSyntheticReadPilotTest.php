<?php

namespace Tests\Feature;

use App\Services\McpStagingSyntheticReadPilotPolicy;
use App\Services\McpStagingLoginPilotPolicy;
use Illuminate\Http\Request;
use Tests\TestCase;

final class McpStagingSyntheticReadPilotTest extends TestCase
{
    public function test_all_activation_gates_must_be_true(): void
    {
        $valid = [
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.web_access_enabled' => true,
            'canovia_staging.web_access_explicitly_approved' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
            'canovia_staging.plan_consent_pilot_enabled' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true,
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
        ];
        config($valid);
        $policy = app(McpStagingSyntheticReadPilotPolicy::class);
        $this->assertTrue($policy->isReady());
        foreach ($valid as $name => $value) {
            $alternative = is_bool($value) ? ! $value : 'wrong';
            config([$name => $alternative]);
            $this->assertFalse($policy->isReady(), $name);
            config([$name => $value]);
        }
    }

    public function test_allowlist_and_identity_consent_continuity(): void
    {
        config($this->validConfig());
        $read = app(McpStagingSyntheticReadPilotPolicy::class);
        $login = app(McpStagingLoginPilotPolicy::class);
        foreach ([
            ['POST', '/api/mcp'],
            ['GET', '/.well-known/oauth-protected-resource'],
            ['GET', '/.well-known/oauth-protected-resource/api/mcp'],
        ] as [$method, $path]) {
            $this->assertTrue($read->allows(Request::create($path, $method)));
        }
        foreach ([
            ['GET', '/api/mcp'], ['DELETE', '/api/mcp'],
            ['POST', '/api/mcp/other'], ['GET', '/register'],
            ['GET', '/.well-known/openid-configuration'],
        ] as [$method, $path]) {
            $this->assertFalse($read->allows(Request::create($path, $method)));
        }
        $this->assertTrue($login->allows(Request::create('/account', 'GET')));
        $this->assertTrue($login->allows(Request::create('/account/mcp/link/callback', 'GET')));
        $this->assertTrue($login->allows(Request::create('/account/mcp/plans/consent/confirm', 'POST')));
        $this->assertTrue($login->allows(Request::create('/account/mcp/grants/1', 'DELETE')));
        $this->assertFalse($login->allows(Request::create('/api/mcp', 'POST')));

        config(['canovia_staging.synthetic_read_pilot_enabled' => false]);
        $this->assertFalse($read->allows(Request::create('/api/mcp', 'POST')));
        $this->assertFalse($login->allows(Request::create('/account/mcp/link/callback', 'GET')));
    }

    private function validConfig(): array
    {
        return [
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.web_access_enabled' => true,
            'canovia_staging.web_access_explicitly_approved' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => true,
            'canovia_staging.plan_consent_pilot_enabled' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true,
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.plan_consent_enabled' => true,
            'canovia_mcp.tools_enabled' => true,
            'canovia_mcp.delegated_policy_enabled' => true,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.token_introspection_enabled' => false,
        ];
    }
}
