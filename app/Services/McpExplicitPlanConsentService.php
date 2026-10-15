<?php

namespace App\Services;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, session-owner-initiated consent after fresh external PKCE proof.
 * A saved preparation preference and a mere linked subject are never consent.
 * No bearer tokens or Context bodies are persisted, and no MCP tools are on.
 */
final class McpExplicitPlanConsentService
{
    public const SCOPES = ['overview', 'tasks', 'study_progress', 'learning_context'];
    public const DURATIONS_DAYS = [1, 7, 30];
    public const UNTIL_REVOKED = 'until_revoked';

    public function __construct(
        private readonly McpOAuthAccountLinkConfiguration $linkConfiguration,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
        private readonly PlanCategoryProfileService $profiles,
        private readonly McpStudyPilotAccessPolicy $apPilot,
    ) {}

    public function isEnabled(): bool
    {
        // The generic feature flag alone cannot authorize staging grants.
        if (config('app.env') === 'staging'
            && ! app(McpStagingLoginPilotPolicy::class)->planConsentReady()) {
            return false;
        }

        $client = config('canovia_mcp.allowed_client_id');
        $resource = config('canovia_mcp.resource_url');

        return config('canovia_mcp.plan_consent_enabled') === true
            && $this->linkConfiguration->settings() !== null
            && is_string($client) && $client !== ''
            && is_string($resource) && $resource !== ''
            && $this->fingerprints->clientResource($client, $resource) !== null;
    }

    public function isPersonalDevelopmentOwner(User $actor, Plan $plan): bool
    {
        return $plan->user_id !== null
            && (int) $plan->user_id === (int) $actor->id
            && ! (bool) $plan->is_collaborative
            && $this->profiles->forPlan($plan)->key === 'development';
    }

    /**
     * Reuse existing explicit per-Plan OAuth consent only for supported
     * domains. Study requires a second, OFF-by-default switch and is never
     * permitted through the isolated synthetic-only staging account.
     */
    public function isEligibleMcpPlanOwner(User $actor, Plan $plan): bool
    {
        if ($this->isPersonalDevelopmentOwner($actor, $plan)) {
            return true;
        }

        return config('canovia_mcp.study_read_enabled') === true
            && config('app.env') !== 'staging'
            && $plan->user_id !== null
            && (int) $plan->user_id === (int) $actor->id
            && ! (bool) $plan->is_collaborative
            && $this->profiles->forPlan($plan)->key === 'study'
            && $this->apPilot->allowsPlan($plan);
    }

    /**
     * Detailed practice summaries require a stronger, separate grant.
     * It must never be accepted for Development or a synthetic staging Plan.
     */
    public function isScopeEligibleForPlan(Plan $plan, string $scope): bool
    {
        if (! in_array($scope, ['study_progress', 'learning_context'], true)) {
            return in_array($scope, ['overview', 'tasks'], true);
        }

        if ($scope === 'learning_context'
            && config('canovia_mcp.learning_context_snapshot_enabled') !== true) {
            return false;
        }

        return config('canovia_mcp.study_read_enabled') === true
            && config('canovia_mcp.study_summary_read_enabled') === true
            && config('app.env') !== 'staging'
            && $this->profiles->forPlan($plan)->key === 'study'
            && $this->apPilot->allowsPlan($plan);
    }

    public function hasActiveLinkedIdentity(User $actor): bool
    {
        return McpLinkedSubject::query()
            ->where('user_id', $actor->id)
            ->where('provider_key', 'chatgpt')
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
            ->whereColumn('identity_fingerprint', 'active_identity_fingerprint')
            ->whereNotNull('linked_at')
            ->where('linked_at', '<=', now())
            ->exists();
    }

    public function matchesLinkedIdentity(User $actor, string $fingerprint): bool
    {
        return McpLinkedSubject::query()
            ->where('user_id', $actor->id)
            ->where('provider_key', 'chatgpt')
            ->where('identity_fingerprint', $fingerprint)
            ->where('active_identity_fingerprint', $fingerprint)
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
            ->whereNotNull('linked_at')
            ->where('linked_at', '<=', now())
            ->exists();
    }

    public function isScopeAndDurationAllowed(string $scope, int|string $days): bool
    {
        return in_array($scope, self::SCOPES, true)
            && (in_array($days, self::DURATIONS_DAYS, true)
                || $days === self::UNTIL_REVOKED);
    }

    /**
     * Only a verified 5-minute callback session can reach this method in
     * production. It additionally checks every mutable DB permission under
     * locks at the exact moment consent is recorded.
     */
    public function grant(
        User $actor,
        int $planId,
        string $identityFingerprint,
        string $scope,
        int|string $days,
    ): bool {
        if (! $this->isEnabled()
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $identityFingerprint)
            || ! $this->isScopeAndDurationAllowed($scope, $days)) {
            return false;
        }

        return DB::transaction(function () use ($actor, $planId, $identityFingerprint, $scope, $days): bool {
            $owner = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($owner === null || $plan === null
                || ! $this->isEligibleMcpPlanOwner($owner, $plan)
                || ! $this->isScopeEligibleForPlan($plan, $scope)) {
                return false;
            }
            // Recheck the immutable synthetic fixture UNDER the database
            // locks: a swapped Plan between OAuth and consent cannot pass.
            if (config('app.env') === 'staging'
                && ! app(McpStagingLoginPilotPolicy::class)
                    ->isApprovedSyntheticPlan($owner, $plan)) {
                return false;
            }

            $link = McpLinkedSubject::query()
                ->where('user_id', $owner->id)
                ->where('provider_key', 'chatgpt')
                ->where('identity_fingerprint', $identityFingerprint)
                ->where('active_identity_fingerprint', $identityFingerprint)
                ->where('status', McpLinkedSubject::STATUS_LINKED)
                ->whereNull('revoked_at')
                ->whereNotNull('linked_at')
                ->where('linked_at', '<=', now())
                ->lockForUpdate()->first();

            if ($link === null) {
                return false;
            }

            $clientFingerprint = $this->fingerprints->clientResource(
                (string) config('canovia_mcp.allowed_client_id'),
                (string) config('canovia_mcp.resource_url'),
            );
            if ($clientFingerprint === null) {
                return false;
            }

            $grant = McpDelegatedGrant::query()
                ->where('subject_link_id', $link->id)
                ->where('user_id', $owner->id)
                ->where('plan_id', $plan->id)
                ->where('client_resource_fingerprint', $clientFingerprint)
                ->lockForUpdate()->first();

            $values = [
                'scope' => $scope,
                'status' => McpDelegatedGrant::STATUS_ACTIVE,
                'consented_at' => now(),
                'expires_at' => $days === self::UNTIL_REVOKED
                    ? null : now()->addDays($days),
                'revoked_at' => null,
            ];
            if ($grant !== null) {
                $grant->forceFill($values)->save();
                $event = 'grant_reconsented';
            } else {
                $grant = McpDelegatedGrant::query()->create([
                    'subject_link_id' => $link->id,
                    'user_id' => $owner->id,
                    'plan_id' => $plan->id,
                    'client_resource_fingerprint' => $clientFingerprint,
                    ...$values,
                ]);
                $event = 'grant_consented';
            }

            // Refreshing/downgrading consent invalidates ALL earlier
            // unapproved proposals, even after a rapid revoke/re-consent.
            DB::table('mcp_study_result_proposals')
                ->where('grant_id', $grant->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected', 'decided_at' => now(), 'updated_at' => now()]);

            DB::table('mcp_delegated_access_events')->insert([
                'actor_user_id' => $owner->id,
                'subject_link_id' => $link->id,
                'grant_id' => $grant->id,
                'event_kind' => $event,
                'scope' => $scope,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }
}
