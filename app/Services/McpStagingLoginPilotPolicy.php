<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Fail-closed staging-only login, identity link and synthetic Plan consent.
 * No route in this allowlist permits an MCP JSON-RPC read.
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
            return $request->input('email') === McpStagingSyntheticActorBootstrap::EMAIL;
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

        if ($method === 'POST' && in_array($path, [
            'account/mcp/link/start',
            'account/mcp/link/confirm',
            'account/mcp/link/cancel',
        ], true)) {
            return true;
        }

        if (! $this->planConsentReady()) {
            return false;
        }

        if ($method === 'POST' && in_array($path, [
            'account/mcp/plans/consent/confirm',
            'account/mcp/plans/consent/cancel',
        ], true)) {
            return true;
        }

        if ($method === 'POST'
            && preg_match('#\Aaccount/mcp/plans/[1-9][0-9]*/consent/start\z#D', $path) === 1) {
            return true;
        }

        // Revocation is allowed only for an existing, personally owned grant;
        // the controller additionally enforces the one synthetic Plan.
        return $method === 'DELETE'
            && preg_match('#\Aaccount/mcp/grants/[1-9][0-9]*\z#D', $path) === 1;
    }

    public function accountLinkReady(): bool
    {
        // Setting consent ON without its own independent approval cannot
        // turn an earlier identity-link pilot into a Plan-sharing service.
        return $this->baseAccountLinkReady()
            && (
                (config('canovia_mcp.plan_consent_enabled') === false
                    && config('canovia_staging.plan_consent_pilot_enabled') === false)
                || $this->planConsentReady()
            );
    }

    public function planConsentReady(): bool
    {
        return config('canovia_staging.plan_consent_pilot_enabled') === true
            && config('canovia_mcp.plan_consent_enabled') === true
            && $this->baseAccountLinkReady();
    }

    public function isApprovedSyntheticPlan(User $actor, Plan $plan): bool
    {
        return $actor->email === McpStagingSyntheticActorBootstrap::EMAIL
            && (int) $plan->user_id === (int) $actor->id
            && $plan->creation_request_id === McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID
            && $plan->title === McpStagingSyntheticPlanFixtureBootstrap::TITLE
            && $plan->category === '個人開発'
            && ! (bool) $plan->is_public
            && ! (bool) $plan->is_collaborative;
    }

    private function baseAccountLinkReady(): bool
    {
        return config('canovia_staging.account_link_pilot_enabled') === true
            && config('canovia_mcp.discovery_enabled') === true
            && config('canovia_mcp.account_link_enabled') === true
            && config('canovia_mcp.auth0_jwt_verification_enabled') === true
            && config('canovia_mcp.token_verification_driver') === 'auth0_jwt'
            && config('canovia_mcp.token_introspection_enabled') === false
            && (
                (config('canovia_mcp.tools_enabled') === false
                    && config('canovia_mcp.delegated_policy_enabled') === false)
                || app(McpStagingSyntheticReadPilotPolicy::class)->isReady()
            );
    }
}
