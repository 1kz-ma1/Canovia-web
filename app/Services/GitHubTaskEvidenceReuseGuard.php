<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\TaskEvidence;

/**
 * A factual TaskEvidence row is durable history, not perpetual permission
 * to project it as current GitHub-backed Execution/Intelligence evidence.
 *
 * Legacy unsigned evidence is history-only until GitHub is re-fetched and
 * the event is re-signed by GitHubTaskEvidenceCommitGuard.
 */
final class GitHubTaskEvidenceReuseGuard
{
    public function __construct(
        private readonly GitHubWorkflowService $workflow,
        private readonly GitHubSourceEpochService $epochs,
        private readonly PlanCategoryProfileService $profiles,
        private readonly FeatureAccessService $access,
    ) {}

    public function eligible(Plan $plan, TaskEvidence $evidence): bool
    {
        if ($evidence->source !== EvidenceSource::GitHub) {
            // User-confirmed native quality gates and other domains are not
            // invalidated solely by GitHub App disconnection.
            return true;
        }

        if ((int) $evidence->plan_id !== (int) $plan->id
            || $evidence->task_id === null
            || $plan->user_id === null) {
            return false;
        }

        $livePlan = Plan::query()->with('user')->find($plan->id);
        if ($livePlan === null || $this->profiles->forPlan($livePlan)->key !== 'development'
            || $livePlan->user === null) {
            return false;
        }

        $metadata = (array) $evidence->metadata;
        $epoch = (string) ($metadata['github_source_epoch'] ?? '');
        $rootId = (int) ($metadata['github_repository_artifact_id'] ?? 0);
        $repo = mb_strtolower(trim((string) ($metadata['repo_full_name'] ?? '')));
        if ($epoch === '' || $rootId <= 0 || $repo === '') {
            return false;
        }

        $root = PlanArtifact::query()->find($rootId);
        if ($root === null || (int) $root->plan_id !== (int) $livePlan->id
            || $root->provider !== 'github' || $root->artifact_type !== 'repository'
            || $this->epochs->current((array) $root->metadata) !== $epoch) {
            return false;
        }

        $parsed = $this->workflow->parseUrl((string) $root->url);
        if (($parsed['kind'] ?? null) !== 'repository'
            || mb_strtolower((string) ($parsed['repo_full_name'] ?? '')) !== $repo) {
            return false;
        }

        return $this->access->canUse(
            $livePlan->user,
            FeatureKey::DeveloperGithubEvidence,
            ['plan_id' => (int) $livePlan->id, 'artifact_id' => (int) $rootId],
        );
    }
}
