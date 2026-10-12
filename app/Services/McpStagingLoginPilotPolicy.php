<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Explicit staged login -> account -> IdP link route policy.
 *
 * Do not treat the two public Web flags as permission to expose the
 * full Canovia application. Production does not use this pilot.
 */
final class McpStagingLoginPilotPolicy
{
    public function allows(Request $request): bool
    {
        if (config('canovia_staging.login_pilot_enabled') !== true) {
            return false;
        }

        $method = $request->method();
        $path = $request->path();

        if (in_array($method, ['GET', 'HEAD'], true)
            && in_array($path, ['login', 'account'], true)) {
            return true;
        }

        if ($method === 'POST' && $path === 'login') {
            // No account creation and no non-synthetic actor may log in.
            $email = $request->input('email');

            return is_string($email)
                && $email === McpStagingSyntheticActorBootstrap::EMAIL;
        }

        if ($method === 'POST' && $path === 'logout') {
            return true;
        }

        if (! $this->accountLinkReady()) {
            return false;
        }

        if ($method === 'GET' && $path === 'account/mcp/link/callback') {
            return true;
        }

        return $method === 'POST' && in_array($path, [
            'account/mcp/link/start',
            'account/mcp/link/confirm',
            'account/mcp/link/cancel',
        ], true);
    }

    private function accountLinkReady(): bool
    {
        return config('canovia_staging.account_link_pilot_enabled') === true
            && config('canovia_mcp.discovery_enabled') === true
            && config('canovia_mcp.account_link_enabled') === true
            && config('canovia_mcp.auth0_jwt_verification_enabled') === true
            && config('canovia_mcp.token_verification_driver') === 'auth0_jwt'
            && config('canovia_mcp.token_introspection_enabled') === false
            && config('canovia_mcp.tools_enabled') === false
            && config('canovia_mcp.plan_consent_enabled') === false
            && config('canovia_mcp.delegated_policy_enabled') === false;
    }
}
