<?php

namespace Tests\Unit;

use App\Models\DevelopmentOperationalTarget;
use App\Services\DevelopmentOperationalProviderCapabilityService;
use Tests\TestCase;

final class DevelopmentOperationalProviderCapabilityV51Test extends TestCase
{
    private function target(string $provider = 'render'): DevelopmentOperationalTarget
    {
        return new DevelopmentOperationalTarget([
            'plan_id' => 42,
            'provider' => $provider,
            'environment' => 'production',
            'resource_ref' => 'srv-test12345',
            'revision' => 1,
        ]);
    }

    public function test_absent_target_does_not_attempt_a_connection(): void
    {
        $result = (new DevelopmentOperationalProviderCapabilityService())->status(null);
        $this->assertSame('unconfigured', $result['state']);
        $this->assertSame('no_target', $result['reason']);
        $this->assertFalse($result['can_connect']);
        $this->assertFalse($result['can_read']);
    }

    public function test_declared_render_target_is_not_a_connected_provider(): void
    {
        $result = (new DevelopmentOperationalProviderCapabilityService())->status(
            $this->target(),
        );

        $this->assertSame('unavailable', $result['state']);
        $this->assertSame('render', $result['provider']);
        $this->assertSame('provider_enforced_read_only_unavailable', $result['reason']);
        $this->assertFalse($result['can_connect']);
        $this->assertFalse($result['can_read']);
    }

    public function test_arbitrary_other_provider_is_not_implicitly_supported(): void
    {
        $result = (new DevelopmentOperationalProviderCapabilityService())->status(
            $this->target('other'),
        );

        $this->assertSame('unsupported', $result['state']);
        $this->assertFalse($result['can_connect']);
        $this->assertFalse($result['can_read']);
    }

    public function test_broad_render_api_key_and_mutation_capable_mcp_oauth_are_denied(): void
    {
        $policy = new DevelopmentOperationalProviderCapabilityService();
        foreach ([
            DevelopmentOperationalProviderCapabilityService::RENDER_API_KEY,
            DevelopmentOperationalProviderCapabilityService::RENDER_MCP_OAUTH,
            'fake_provider_enforced_read_only',
            'services.read',
            '',
        ] as $method) {
            $this->assertFalse($policy->canActivate('render', $method), $method);
        }
    }

    public function test_unknown_provider_or_auth_method_cannot_fail_open(): void
    {
        $policy = new DevelopmentOperationalProviderCapabilityService();
        foreach (['other', 'github', '', 'Render'] as $provider) {
            $this->assertFalse($policy->canActivate($provider, 'render_mcp_oauth'));
            $this->assertFalse($policy->canActivate($provider, 'provider_enforced_read_only'));
        }
    }
}
