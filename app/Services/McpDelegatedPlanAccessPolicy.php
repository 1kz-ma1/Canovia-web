<?php

namespace App\Services;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;

/**
 * Read-only eligibility evaluation for a FUTURE OAuth-linked MCP request.
 *
 * This is not an authentication middleware. The caller must first introspect
 * the bearer token. The separately disabled MCP resource invokes this
 * policy in a locked transaction; no user email or session grants access.
 * Default configuration always denies.
 */
final class McpDelegatedPlanAccessPolicy
{
    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
        private readonly McpExternalTokenVerifier $verifier,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function allows(
        McpVerifiedTokenPrincipal $principal,
        Plan $plan,
        string $requestedScope = 'overview',
    ): bool {
        return $this->authorizedGrant($principal, $plan, $requestedScope) !== null;
    }

    /**
     * Return only grant metadata, never private Plan content.
     *
     * For tool reads call this INSIDE a DB transaction with lockForRead=true
     * and a locked Plan row, so concurrent revocation cannot race the
     * private projection. Legacy callers continue to use allows().
     */
    public function authorizedGrant(
        McpVerifiedTokenPrincipal $principal,
        Plan $plan,
        string $requestedScope = 'overview',
        bool $lockForRead = false,
    ): ?McpDelegatedGrant {
        // The calling MCP controller has ALREADY authenticated the token.
        // Require exactly one valid verifier mode; Auth0 signed JWT is not
        // RFC7662 introspection and must not be rejected for disabling it.
        // Never accept both modes, neither mode, or an unknown driver.
        if (config('canovia_mcp.delegated_policy_enabled') !== true
            || $this->verifier->mode() === null
            || ! $this->resource->isReady()
            || ! in_array($requestedScope, ['overview', 'tasks'], true)) {
            McpStagingPlanReadDenialDiagnostic::record('policy_precondition');
            return null;
        }

        $now = now();
        if ($principal->issuer !== config('canovia_mcp.oauth_issuer')
            || $principal->audience !== config('canovia_mcp.resource_url')
            || $principal->clientId !== config('canovia_mcp.allowed_client_id')
            || $principal->expiresAt <= $now->getTimestamp()
            || $principal->expiresAt > $now->getTimestamp() + 3600
            || ! in_array(McpProtectedResourceConfiguration::READ_SCOPE, $principal->scopes, true)) {
            McpStagingPlanReadDenialDiagnostic::record('principal_constraints');
            return null;
        }

        $subjectFingerprint = $this->fingerprints->subject(
            $principal->issuer, $principal->subject,
        );
        $clientResourceFingerprint = $this->fingerprints->clientResource(
            $principal->clientId, $principal->audience,
        );

        if ($subjectFingerprint === null || $clientResourceFingerprint === null) {
            McpStagingPlanReadDenialDiagnostic::record('fingerprint_unavailable');
            return null;
        }

        $link = McpLinkedSubject::query()
            ->where('identity_fingerprint', $subjectFingerprint)
            ->where('provider_key', 'chatgpt')
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
            ->whereNotNull('linked_at')
            ->where('linked_at', '<=', $now)
            ->when($lockForRead, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($link === null) {
            McpStagingPlanReadDenialDiagnostic::record('linked_identity_mismatch');
            return null;
        }

        if ($plan->user_id === null
            || (int) $plan->user_id !== (int) $link->user_id
            || (bool) $plan->is_collaborative
            || $this->profiles->forPlan($plan)->key !== 'development') {
            McpStagingPlanReadDenialDiagnostic::record('plan_owner_or_category');
            return null;
        }

        // A staging read, once independently activated, must NEVER expose
        // another actor's Plan or a Plan injected into the isolated database.
        // Only the one immutable synthetic fixture may cross this boundary.
        if (config('app.env') === 'staging') {
            if (config('canovia_staging.isolated') !== true) {
                McpStagingPlanReadDenialDiagnostic::record('stage_fixture_mismatch');
                return null;
            }
            $owner = User::query()->whereKey($link->user_id)
                ->when($lockForRead, fn ($query) => $query->lockForUpdate())
                ->first();
            if ($owner === null
                || ! app(McpStagingLoginPilotPolicy::class)
                    ->isApprovedSyntheticPlan($owner, $plan)) {
                McpStagingPlanReadDenialDiagnostic::record('stage_fixture_mismatch');
                return null;
            }
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
            ->when($lockForRead, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'user_id', 'subject_link_id', 'scope']);

        if ($grant === null) {
            // Only on the isolated, owner-approved staging read pilot, check
            // whether an otherwise active grant exists for this verified link
            // and Plan. No metadata or IDs are emitted into logs or HTTP.
            $bindingMismatch = McpStagingPlanReadDenialDiagnostic::enabled()
                && McpDelegatedGrant::query()
                    ->where('subject_link_id', $link->id)
                    ->where('user_id', $link->user_id)
                    ->where('plan_id', $plan->id)
                    ->where('status', McpDelegatedGrant::STATUS_ACTIVE)
                    ->whereNull('revoked_at')
                    ->whereNotNull('consented_at')
                    ->where('consented_at', '<=', $now)
                    ->where('expires_at', '>', $now)
                    ->exists();
            McpStagingPlanReadDenialDiagnostic::record($bindingMismatch
                ? 'grant_client_binding_mismatch'
                : 'grant_missing_or_inactive');
            return null;
        }
        if (! in_array($grant->scope, ['overview', 'tasks'], true)
            || ($requestedScope === 'tasks' && $grant->scope !== 'tasks')) {
            McpStagingPlanReadDenialDiagnostic::record('scope_denied');
            return null;
        }

        // An overview-only grant must NEVER authorize Task title/progress data.
        return $grant;
    }
}
