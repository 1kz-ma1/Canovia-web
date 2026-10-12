<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use Illuminate\Validation\ValidationException;

/**
 * G3-R2: an old GitHub Observation is historical until its recorded
 * repository remains locally connected and entitled for current reuse.
 *
 * This is not a fresh REST verification, deletion grant or proof of the
 * original installation epoch of pre-G3 observations.
 */
final class DevelopmentObservationReuseGuard
{
    public function __construct(
        private readonly GitHubWorkflowService $workflow,
        private readonly PlanCategoryProfileService $profiles,
        private readonly FeatureAccessService $access,
    ) {}

    /**
     * Always read the latest rows; a caller's eager-loaded Repository or
     * Plan can predate a disconnect/owner transfer in another request.
     */
    public function eligible(Plan $plan, DevelopmentActivityObservation $observation): bool
    {
        $livePlan = Plan::query()->with('user')->find($plan->id);
        $root = PlanArtifact::query()->find($observation->repository_artifact_id);

        return $this->isEligible($livePlan, $root, $observation);
    }

    /** Call within a transaction with Plan, Repository, Observation row locks. */
    public function assertLocked(
        ?Plan $plan,
        ?PlanArtifact $root,
        DevelopmentActivityObservation $observation,
    ): void {
        if (! $this->isEligible($plan, $root, $observation)) {
            throw ValidationException::withMessages([
                'observation' => 'GitHubの接続または権限を確認できません。過去の活動は履歴として残りますが、現在のTaskには関連付けられません。',
            ]);
        }
    }

    private function isEligible(
        ?Plan $plan,
        ?PlanArtifact $root,
        DevelopmentActivityObservation $observation,
    ): bool {
        if ($plan === null || $root === null
            || (int) $observation->plan_id !== (int) $plan->id
            || (int) $root->plan_id !== (int) $plan->id
            || (int) $root->id !== (int) $observation->repository_artifact_id
            || $observation->provider !== 'github'
            || $root->provider !== 'github'
            || $root->artifact_type !== 'repository'
            || $this->profiles->forPlan($plan)->key !== 'development'
            || data_get($root->metadata, 'github_app_connection.status') !== 'connected'
            || (int) data_get($root->metadata, 'github_app_connection.installation_id', 0) <= 0
            || $plan->user_id === null) {
            return false;
        }

        $parsed = $this->workflow->parseUrl((string) $root->url);
        $repo = mb_strtolower(trim((string) ($parsed['repo_full_name'] ?? '')));
        if (($parsed['kind'] ?? null) !== 'repository' || $repo === ''
            || ! str_starts_with(
                mb_strtolower((string) $observation->external_key),
                'github:'.$repo.':',
            )) {
            // Legacy hashed external_key rows have no verifiable repo
            // lineage and must not be promoted as current observations.
            return false;
        }

        $owner = $plan->relationLoaded('user') ? $plan->user : $plan->user()->first();

        return $owner !== null
            && $this->access->canUse(
                $owner, FeatureKey::DeveloperGithubEvidence,
                ['plan_id' => (int) $plan->id, 'artifact_id' => (int) $root->id],
            );
    }
}
