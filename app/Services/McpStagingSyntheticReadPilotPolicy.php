<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Independent, OFF-by-default network exposure gate for one isolated
 * synthetic Development Plan. Never enables any production route.
 */
final class McpStagingSyntheticReadPilotPolicy
{
    public function isReady(): bool
    {
        return config('app.env') === 'staging'
            && config('canovia_staging.isolated') === true
            && config('canovia_staging.database_mode') === 'render_postgres'
            && config('canovia_staging.postgres_id') === 'dpg-db43rbbncjis73bmigi0-a'
            && config('canovia_staging.web_access_enabled') === true
            && config('canovia_staging.web_access_explicitly_approved') === true
            && config('canovia_staging.login_pilot_enabled') === true
            && config('canovia_staging.account_link_pilot_enabled') === true
            && config('canovia_staging.plan_consent_pilot_enabled') === true
            && config('canovia_staging.synthetic_read_pilot_enabled') === true
            && config('canovia_staging.mcp_explicitly_approved') === true
            && config('canovia_mcp.discovery_enabled') === true
            && config('canovia_mcp.account_link_enabled') === true
            && config('canovia_mcp.plan_consent_enabled') === true
            && config('canovia_mcp.tools_enabled') === true
            && config('canovia_mcp.delegated_policy_enabled') === true
            && config('canovia_mcp.token_verification_driver') === 'auth0_jwt'
            && config('canovia_mcp.auth0_jwt_verification_enabled') === true
            && config('canovia_mcp.token_introspection_enabled') === false;
    }

    public function allows(Request $request): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        // Only the protected-resource discovery metadata is unauthenticated.
        if (in_array($request->method(), ['GET', 'HEAD'], true)
            && in_array($request->path(), [
                '.well-known/oauth-protected-resource',
                '.well-known/oauth-protected-resource/api/mcp',
            ], true)) {
            return true;
        }

        // MCP requires a signed bearer even for initialize and tools/list.
        // GET/SSE, DELETE, dynamic registration and all unrelated API paths
        // remain blocked by both Laravel and the staging-only Nginx allowlist.
        return $request->method() === 'POST'
            && $request->path() === 'api/mcp';
    }
}
