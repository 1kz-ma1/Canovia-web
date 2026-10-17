<?php

namespace App\Services;

use App\Models\Plan;

/**
 * Private AP Study MCP pilot: single explicitly chosen Canovia owner.
 *
 * This is a SERVER-side allowlist, not a trust claim from ChatGPT, a Plan ID,
 * the browser, an OAuth email or any externally supplied metadata. Until
 * the real account owner has approved the deployment and provided their
 * Canovia numeric ID to an operator, all Study MCP data fails closed.
 */
final class McpStudyPilotAccessPolicy
{
    public function configured(): bool
    {
        $id = config('canovia_mcp.ap_pilot_owner_id');

        return config('app.env') !== 'staging'
            && is_string($id)
            && preg_match('/\A[1-9][0-9]{0,15}\z/D', $id) === 1
            && filter_var($id, FILTER_VALIDATE_INT) !== false;
    }

    public function allowsOwnerId(?int $id): bool
    {
        return $this->configured()
            && $id !== null
            && $id > 0
            && (string) $id === (string) config('canovia_mcp.ap_pilot_owner_id');
    }

    public function allowsPlan(Plan $plan): bool
    {
        return $plan->user_id !== null
            && $this->allowsOwnerId((int) $plan->user_id)
            && ! (bool) $plan->is_collaborative
            && ! (bool) $plan->is_public;
    }
}
