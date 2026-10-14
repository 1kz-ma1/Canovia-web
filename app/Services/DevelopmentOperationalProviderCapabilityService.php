<?php

namespace App\Services;

use App\Models\DevelopmentOperationalTarget;

/**
 * Provider-enforced authorization capabilities, NOT user-declared scopes.
 *
 * Internal allowlists cannot turn a Render API key or a Render MCP OAuth
 * session into a provider-side read-only credential. No live adapter is
 * enabled until a separately reviewed provider-enforced authorization exists.
 */
final class DevelopmentOperationalProviderCapabilityService
{
    public const RENDER_API_KEY = 'render_api_key';
    public const RENDER_MCP_OAUTH = 'render_mcp_oauth';

    /**
     * @return array{state:string,can_connect:bool,can_read:bool,reason:string,provider:string}
     */
    public function status(?DevelopmentOperationalTarget $target): array
    {
        if (! $target) {
            return $this->disabled('unconfigured', 'no_target', 'unknown');
        }

        if ($target->provider === 'render') {
            return $this->disabled(
                'unavailable',
                'provider_enforced_read_only_unavailable',
                'render',
            );
        }

        // V4 allows a human-declared "other" provider, not arbitrary APIs.
        return $this->disabled('unsupported', 'unsupported_provider', 'other');
    }

    /**
     * This is a provider-credential capability check, not a Canovia UI scope.
     * Render's API keys span all user workspaces; Render MCP OAuth currently
     * exposes deploy and env mutation tools. Both must fail closed.
     */
    public function canActivate(string $provider, string $authMethod): bool
    {
        if ($provider === 'render' && in_array($authMethod, [
            self::RENDER_API_KEY,
            self::RENDER_MCP_OAUTH,
        ], true)) {
            return false;
        }

        // Do not treat unrecognized or newly introduced methods as approved.
        // A future provider-enforced, sufficiently scoped method requires
        // explicit reviewed implementation and dedicated security tests.
        return false;
    }

    /**
     * @return array{state:string,can_connect:bool,can_read:bool,reason:string,provider:string}
     */
    private function disabled(string $state, string $reason, string $provider): array
    {
        return [
            'state' => $state,
            'can_connect' => false,
            'can_read' => false,
            'reason' => $reason,
            'provider' => $provider,
        ];
    }
}
