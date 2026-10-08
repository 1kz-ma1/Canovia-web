<?php

namespace App\Services;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;

/**
 * Read-only eligibility evaluation for a FUTURE OAuth-linked MCP request.
 *
 * This is not an authentication middleware. The caller must first introspect
 * the bearer token; no HTTP endpoint currently creates links/consents or
 * invokes this policy. Default configuration always denies.
 */
final class McpDelegatedPlanAccessPolicy
{
    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function allows(
        McpVerifiedTokenPrincipal $principal,
        Plan $plan,
        string $requestedScope = 'overview',
    ): bool {
        if (config('canovia_mcp.delegated_policy_enabled') !== true
            || config('canovia_mcp.token_introspection_enabled') !== true
            || ! $this->resource->isReady()
            || ! in_array($requestedScope, ['overview', 'tasks'], true)) {
            return false;
        }

        $now = now();
        if ($principal->issuer !== config('canovia_mcp.oauth_issuer')
            || $principal->audience !== config('canovia_mcp.resource_url')
            || $principal->clientId !== config('canovia_mcp.allowed_client_id')
            || $principal->expiresAt <= $now->getTimestamp()
            || $principal->expiresAt > $now->getTimestamp() + 3600
            || ! in_array(McpProtectedResourceConfiguration::READ_SCOPE, $principal->scopes, true)) {
            return false;
        }

        $subjectFingerprint = $this->fingerprints->subject(
            $principal->issuer, $principal->subject,
        );
        $clientResourceFingerprint = $this->fingerprints->clientResource(
            $principal->clientId, $principal->audience,
        );

        if ($subjectFingerprint === null || $clientResourceFingerprint === null) {
            return false;
        }

        $link = McpLinkedSubject::query()
            ->where('identity_fingerprint', $subjectFingerprint)
            ->where('provider_key', 'chatgpt')
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
            ->whereNotNull('linked_at')
            ->where('linked_at', '<=', $now)
            ->first();

        if ($link === null
            || $plan->user_id === null
            || (int) $plan->user_id !== (int) $link->user_id
            || (bool) $plan->is_collaborative
            || $this->profiles->forPlan($plan)->key !== 'development') {
            return false;
        }

        $grant = McpDelegatedGrant::query()
            ->where('subject_link_id', $link->id)
            ->where('user_id', $link->user_id)
            ->where('plan_id', $plan->id)
            ->where('client_resource_fingerprint', $clientResourceFingerprint)
            ->where('status', McpDelegatedGrant::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->whereNotNull('consented_at')
            ->where('consented_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->first(['id', 'scope']);

        if ($grant === null || ! in_array($grant->scope, ['overview', 'tasks'], true)) {
            return false;
        }

        // An overview-only grant must NEVER authorize Task title/progress data.
        return $requestedScope === 'overview' || $grant->scope === 'tasks';
    }
}
