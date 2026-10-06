<?php

namespace App\Jobs;

use App\Enums\FeatureKey;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Services\FeatureAccessService;
use App\Services\GitHubDevelopmentEvidenceService;
use App\Services\GitHubDevelopmentObservationService;
use App\Services\GitHubReturnEvidenceService;
use App\Services\GitHubWorkflowService;
use App\Services\PlanCategoryProfileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessGitHubWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    public function handle(
        GitHubReturnEvidenceService $returns,
        GitHubWorkflowService $workflow,
        FeatureAccessService $access,
        ?GitHubDevelopmentEvidenceService $developmentEvidence = null,
        ?GitHubDevelopmentObservationService $developmentObservations = null,
        ?DevelopmentAdaptiveActionService $developmentActions = null,
        ?PlanCategoryProfileService $profiles = null,
    ): void {
        $delivery = GitHubWebhookDelivery::query()->find($this->deliveryId);
        if (! $delivery instanceof GitHubWebhookDelivery) {
            return;
        }

        if (in_array($delivery->status, ['processed', 'ignored'], true)) {
            return;
        }

        $delivery->update([
            'status' => 'processing',
            'attempts' => min(65535, (int) $delivery->attempts + 1),
            'last_error' => null,
        ]);

        $repoFullName = (string) $delivery->repo_full_name;
        $installationId = (int) $delivery->installation_id;
        $pullRequestNumbers = collect((array) $delivery->pull_request_numbers)
            ->map(fn ($number) => (int) $number)
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->values();
        $routingTargets = collect((array) $delivery->routing_targets)
            ->filter(fn ($target) => is_array($target))
            ->values();

        if (
            $repoFullName === ''
            || $installationId <= 0
            || ($pullRequestNumbers->isEmpty() && $routingTargets->isEmpty())
        ) {
            $delivery->update([
                'status' => 'ignored',
                'processed_at' => now(),
            ]);

            return;
        }

        $artifacts = PlanArtifact::query()
            ->with(['plan.user', 'tasks'])
            ->where('provider', 'github')
            ->whereIn('external_id', $pullRequestNumbers->map(fn (int $number) => (string) $number)->all())
            ->get()
            ->filter(function (PlanArtifact $artifact) use ($workflow, $repoFullName) {
                $parsed = $workflow->parseUrl((string) $artifact->url);

                return ($parsed['kind'] ?? null) === 'pull_request'
                    && strcasecmp((string) ($parsed['repo_full_name'] ?? ''), $repoFullName) === 0;
            })
            ->values();

        $matchedArtifacts = 0;
        $syncedTasks = 0;
        $observedActivities = 0;
        $skippedEntitlement = 0;
        $syncedPlanIds = [];

        try {
            foreach ($artifacts as $artifact) {
                $plan = $artifact->plan;
                if (! $plan) {
                    continue;
                }

                $repository = $this->repositoryArtifact(
                    $artifact,
                    $repoFullName,
                    $installationId,
                    $workflow,
                );

                if (! $repository instanceof PlanArtifact) {
                    continue;
                }

                $matchedArtifacts++;

                if (! $access->canUse(
                    $plan->user,
                    FeatureKey::DeveloperGithubEvidence,
                    [
                        'plan_id' => (int) $plan->id,
                        'artifact_id' => (int) $artifact->id,
                        'source' => 'github_webhook',
                    ],
                )) {
                    $skippedEntitlement++;
                    continue;
                }

                foreach ($artifact->tasks as $task) {
                    if ((int) $task->plan_id !== (int) $plan->id) {
                        continue;
                    }

                    $returns->sync($plan, $task, $artifact);
                    $syncedTasks++;
                    $syncedPlanIds[(int) $plan->id] = true;
                }
            }

            if ($developmentObservations) {
                $observationCounts = $developmentObservations->observeDelivery(
                    $delivery,
                );
                $observedActivities += (int) ($observationCounts['observed'] ?? 0);
            }

            if ($developmentEvidence) {
                $developmentCounts = $developmentEvidence->syncDelivery($delivery);
                $matchedArtifacts += (int) $developmentCounts['matched_artifacts'];
                $syncedTasks += (int) $developmentCounts['synced_tasks'];
                $skippedEntitlement += (int) $developmentCounts['skipped_entitlement'];

                foreach ((array) ($developmentCounts['plan_ids'] ?? []) as $planId) {
                    $planId = (int) $planId;
                    if ($planId > 0) {
                        $syncedPlanIds[$planId] = true;
                    }
                }
            }

            if ($developmentActions && $profiles) {
                foreach (array_keys($syncedPlanIds) as $planId) {
                    $plan = Plan::query()->find((int) $planId);

                    if (
                        $plan instanceof Plan
                        && $profiles->forPlan($plan)->key === 'development'
                    ) {
                        $developmentActions->tryRefresh($plan, now());
                    }
                }
            }
        } catch (Throwable $exception) {
            $delivery->update([
                'matched_artifacts' => $matchedArtifacts,
                'synced_tasks' => $syncedTasks,
                'skipped_entitlement' => $skippedEntitlement,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            Log::warning('GitHub webhook return sync will retry.', [
                'delivery_id' => $delivery->delivery_id,
                'repo_full_name' => $repoFullName,
                'event_name' => $delivery->event_name,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $status = ($syncedTasks > 0 || $observedActivities > 0)
            ? 'processed'
            : 'ignored';

        $delivery->update([
            'status' => $status,
            'matched_artifacts' => $matchedArtifacts,
            'synced_tasks' => $syncedTasks,
            'skipped_entitlement' => $skippedEntitlement,
            'last_error' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = GitHubWebhookDelivery::query()->find($this->deliveryId);
        if (! $delivery instanceof GitHubWebhookDelivery) {
            return;
        }

        $delivery->update([
            'status' => 'failed',
            'last_error' => $exception
                ? mb_substr($exception->getMessage(), 0, 1000)
                : 'GitHub webhook processing failed.',
            'processed_at' => now(),
        ]);
    }

    private function repositoryArtifact(
        PlanArtifact $pullRequestArtifact,
        string $repoFullName,
        int $installationId,
        GitHubWorkflowService $workflow,
    ): ?PlanArtifact {
        $originRepositoryId = (int) data_get(
            $pullRequestArtifact->metadata,
            'github_write_origin.repository_artifact_id',
            0,
        );

        $query = PlanArtifact::query()
            ->where('plan_id', $pullRequestArtifact->plan_id)
            ->where('provider', 'github')
            ->where('artifact_type', 'repository');

        if ($originRepositoryId > 0) {
            $query->whereKey($originRepositoryId);
        }

        return $query
            ->get()
            ->first(function (PlanArtifact $repository) use (
                $repoFullName,
                $installationId,
                $workflow,
            ) {
                $parsed = $workflow->parseUrl((string) $repository->url);
                $connectedInstallationId = (int) data_get(
                    $repository->metadata,
                    'github_app_connection.installation_id',
                    0,
                );

                return ($parsed['kind'] ?? null) === 'repository'
                    && strcasecmp((string) ($parsed['repo_full_name'] ?? ''), $repoFullName) === 0
                    && data_get($repository->metadata, 'github_app_connection.status') === 'connected'
                    && $connectedInstallationId === $installationId;
            });
    }
}
