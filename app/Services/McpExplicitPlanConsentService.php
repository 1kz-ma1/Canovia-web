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
    public const SCOPES = ['overview', 'tasks'];
    public const DURATIONS_DAYS = [1, 7, 30];

    public function __construct(
        private readonly McpOAuthAccountLinkConfiguration $linkConfiguration,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    public function isEnabled(): bool
    {
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

    public function hasActiveLinkedIdentity(User $actor): bool
    {
        return McpLinkedSubject::query()
            ->where('user_id', $actor->id)
            ->where('provider_key', 'chatgpt')
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
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
            ->where('status', McpLinkedSubject::STATUS_LINKED)
            ->whereNull('revoked_at')
            ->whereNotNull('linked_at')
            ->where('linked_at', '<=', now())
            ->exists();
    }

    public function isScopeAndDurationAllowed(string $scope, int $days): bool
    {
        return in_array($scope, self::SCOPES, true)
            && in_array($days, self::DURATIONS_DAYS, true);
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
        int $days,
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
                || ! $this->isPersonalDevelopmentOwner($owner, $plan)) {
                return false;
            }

            $link = McpLinkedSubject::query()
                ->where('user_id', $owner->id)
                ->where('provider_key', 'chatgpt')
                ->where('identity_fingerprint', $identityFingerprint)
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
                'expires_at' => now()->addDays($days),
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
