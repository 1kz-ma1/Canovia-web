<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Support\Facades\DB;

/**
 * Keep a GitHub TaskEvidence write within the original actor/source grant.
 * Upstream REST calls must finish before entering this short transaction.
 */
final class GitHubTaskEvidenceCommitGuard
{
    public function __construct(
        private readonly GitHubWorkflowService $workflow,
        private readonly FeatureAccessService $access,
        private readonly PlanCategoryProfileService $profiles,
        private readonly GitHubSourceEpochService $epochs,
    ) {}

    /**
     * @param array<int,int> $artifactIds IDs of explicit Task-linked targets
     * @param callable(Task,array<int,int>):mixed $write
     */
    public function execute(
        Task $task,
        array $artifactIds,
        string $repoFullName,
        int $installationId,
        ?int $sourceOwnerId,
        callable $write,
        ?string $expectedSourceEpoch = null,
    ): mixed {
        if ($installationId <= 0 || $sourceOwnerId === null
            || trim($repoFullName) === '' || $artifactIds === []) {
            return null;
        }

        return DB::transaction(function () use (
            $task, $artifactIds, $repoFullName, $installationId,
            $sourceOwnerId, $write, $expectedSourceEpoch,
        ) {
            $plan = Plan::query()
                ->whereKey($task->plan_id)
                ->lockForUpdate()
                ->first();
            if (! $plan || (int) $plan->user_id !== $sourceOwnerId
                || $this->profiles->forPlan($plan)->key !== 'development') {
                return null;
            }
            $plan->load('user');

            $liveTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();
            if (! $liveTask || (int) $liveTask->plan_id !== (int) $plan->id) {
                return null;
            }

            $roots = PlanArtifact::query()
                ->where('plan_id', $plan->id)
                ->where('provider', 'github')
                ->where('artifact_type', 'repository')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sourceRoot = $roots->first(function (PlanArtifact $root) use (
                $repoFullName, $installationId, $expectedSourceEpoch,
            ): bool {
                $parsed = $this->workflow->parseUrl((string) $root->url);

                return ($parsed['kind'] ?? null) === 'repository'
                    && strcasecmp(
                        (string) ($parsed['repo_full_name'] ?? ''),
                        $repoFullName,
                    ) === 0
                    && data_get($root->metadata, 'github_app_connection.status') === 'connected'
                    && (int) data_get($root->metadata, 'github_app_connection.installation_id', 0)
                        === $installationId
                    // The provider read was authorized for one specific
                    // connection epoch; never re-sign it after reconnect.
                    && $this->epochs->current((array) $root->metadata) === $expectedSourceEpoch;
            });

            if (! $sourceRoot
                || ! $this->access->canUse(
                    $plan->user,
                    FeatureKey::DeveloperGithubEvidence,
                    ['plan_id' => (int) $plan->id, 'source' => 'github_evidence_commit'],
                )) {
                return null;
            }

            $targets = PlanArtifact::query()
                ->where('plan_id', $plan->id)
                ->where('provider', 'github')
                ->whereIn('id', array_values(array_unique($artifactIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(function (PlanArtifact $artifact) use (
                    $repoFullName, $liveTask,
                ): bool {
                    $parsed = $this->workflow->parseUrl((string) $artifact->url);

                    return ($parsed['valid'] ?? false)
                        && ($parsed['kind'] ?? null) !== 'repository'
                        && strcasecmp(
                            (string) ($parsed['repo_full_name'] ?? ''),
                            $repoFullName,
                        ) === 0
                        && $artifact->tasks()->whereKey($liveTask->id)->exists();
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($targets === []) {
                return null;
            }

            $result = $write($liveTask, $targets, $sourceRoot);
            if ($expectedSourceEpoch !== null) {
                $ids = $result instanceof TaskEvidence
                    ? [(int) $result->id]
                    : (is_array($result) ? (array) ($result['evidence_ids'] ?? []) : []);

                // Signed provenance is part of the same DB transaction as
                // the new/re-fetched Evidence, never a background backfill.
                TaskEvidence::query()
                    ->where('plan_id', $plan->id)
                    ->where('task_id', $liveTask->id)
                    ->where('source', 'github')
                    ->whereIn('id', array_filter(array_map('intval', $ids)))
                    ->get()
                    ->each(function (TaskEvidence $row) use (
                        $expectedSourceEpoch, $sourceRoot,
                    ): void {
                        $metadata = is_array($row->metadata) ? $row->metadata : [];
                        $metadata['github_source_epoch'] = $expectedSourceEpoch;
                        $metadata['github_repository_artifact_id'] = (int) $sourceRoot->id;
                        $row->update(['metadata' => $metadata]);
                    });
            }

            return $result;
        }, 3);
    }
}
