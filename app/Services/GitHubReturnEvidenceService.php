<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GitHubReturnEvidenceService
{
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        private readonly GitHubRepositoryWriter $github,
        private readonly GitHubWorkflowService $workflow,
        private readonly TaskEvidenceService $evidence,
    ) {}

    /**
     * Fetch authoritative PR / review / optional CI state and project factual
     * observations into TaskEvidence for Tasks already linked to the PR Artifact.
     *
     * This service never mutates Task progress/status or Canovia workflow lanes.
     *
     * @return array<string,mixed>
     */
    public function sync(
        Plan $plan,
        Task $task,
        PlanArtifact $pullRequestArtifact,
    ): array {
        if (
            (int) $task->plan_id !== (int) $plan->id
            || (int) $pullRequestArtifact->plan_id !== (int) $plan->id
            || $pullRequestArtifact->provider !== 'github'
        ) {
            throw ValidationException::withMessages([
                'github_return' => 'このPlan / Taskに紐づくGitHub Pull Requestを選んでください。',
            ]);
        }

        $parsed = $this->workflow->parseUrl((string) $pullRequestArtifact->url);
        if (
            ($parsed['kind'] ?? null) !== 'pull_request'
            || blank($parsed['repo_full_name'] ?? null)
            || (int) ($parsed['number'] ?? 0) <= 0
        ) {
            throw ValidationException::withMessages([
                'github_return' => 'Pull Request URLを確認できませんでした。',
            ]);
        }

        $linked = $pullRequestArtifact->tasks()
            ->whereKey($task->id)
            ->exists();

        if (! $linked) {
            throw ValidationException::withMessages([
                'github_return' => 'このPull Requestは現在のTaskへ明示リンクされていません。',
            ]);
        }

        $snapshot = $this->github->inspectPullRequestReturn(
            (string) $parsed['repo_full_name'],
            (int) $parsed['number'],
        );

        return DB::transaction(function () use ($task, $pullRequestArtifact, $snapshot) {
            $this->storeSnapshot($pullRequestArtifact, $snapshot);

            $recorded = $this->recordEvidence(
                $task,
                $pullRequestArtifact,
                $snapshot,
            );

            return [
                'snapshot' => $snapshot,
                'evidence_ids' => $recorded->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'evidence_count' => $recorded->count(),
            ];
        });
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function storeSnapshot(PlanArtifact $artifact, array $snapshot): void
    {
        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        $metadata['github_return_snapshot'] = [
            'version' => self::SNAPSHOT_VERSION,
            'source' => 'github_app_rest',
            'repo_full_name' => (string) ($snapshot['repo_full_name'] ?? ''),
            'fetched_at' => (string) ($snapshot['fetched_at'] ?? now()->toIso8601String()),
            'pull_request' => (array) ($snapshot['pull_request'] ?? []),
            'review_summary' => (array) ($snapshot['review_summary'] ?? []),
            'ci' => (array) ($snapshot['ci'] ?? []),
            'warnings' => array_values((array) ($snapshot['warnings'] ?? [])),
        ];

        $artifact->update(['metadata' => $metadata]);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return Collection<int,\App\Models\TaskEvidence>
     */
    private function recordEvidence(
        Task $task,
        PlanArtifact $artifact,
        array $snapshot,
    ): Collection {
        $repo = (string) ($snapshot['repo_full_name'] ?? '');
        $pull = (array) ($snapshot['pull_request'] ?? []);
        $number = (int) ($pull['number'] ?? 0);
        $records = collect();

        foreach ((array) ($snapshot['reviews'] ?? []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $reviewId = (int) ($review['id'] ?? 0);
            $state = strtoupper((string) ($review['state'] ?? ''));

            if ($reviewId <= 0 || ! in_array($state, ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED', 'DISMISSED'], true)) {
                continue;
            }

            $records->push($this->evidence->record(
                $task,
                EvidenceSource::GitHub,
                'pull_request_review_submitted',
                [
                    'plan_artifact_id' => (int) $artifact->id,
                    'repo_full_name' => $repo,
                    'pull_request_number' => $number,
                    'pull_request_url' => $pull['url'] ?? $artifact->url,
                    'review_id' => $reviewId,
                    'review_state' => $state,
                    'reviewer' => (string) ($review['reviewer'] ?? ''),
                    'review_url' => $review['url'] ?? null,
                    'commit_id' => (string) ($review['commit_id'] ?? ''),
                ],
                confidence: 1.0,
                externalKey: 'github:'.$repo.':pull:'.$number.':review:'.$reviewId,
                occurredAt: $this->date($review['submitted_at'] ?? null),
            ));
        }

        if ((bool) ($pull['merged'] ?? false)) {
            $records->push($this->evidence->record(
                $task,
                EvidenceSource::GitHub,
                'pull_request_merged',
                [
                    'plan_artifact_id' => (int) $artifact->id,
                    'repo_full_name' => $repo,
                    'pull_request_number' => $number,
                    'pull_request_url' => $pull['url'] ?? $artifact->url,
                    'merged_by' => (string) ($pull['merged_by'] ?? ''),
                    'merge_commit_sha' => (string) ($pull['merge_commit_sha'] ?? ''),
                    'head_sha' => (string) ($pull['head_sha'] ?? ''),
                    'base_ref' => (string) ($pull['base_ref'] ?? ''),
                    'head_ref' => (string) ($pull['head_ref'] ?? ''),
                ],
                confidence: 1.0,
                externalKey: 'github:'.$repo.':pull:'.$number.':merged',
                occurredAt: $this->date($pull['merged_at'] ?? null),
            ));
        }

        $ci = (array) ($snapshot['ci'] ?? []);
        $ciState = (string) ($ci['state'] ?? 'unknown');
        $headSha = (string) ($pull['head_sha'] ?? '');

        if ($headSha !== '' && in_array($ciState, ['success', 'failure', 'pending'], true)) {
            $records->push($this->evidence->record(
                $task,
                EvidenceSource::GitHub,
                'pull_request_ci_observed',
                [
                    'plan_artifact_id' => (int) $artifact->id,
                    'repo_full_name' => $repo,
                    'pull_request_number' => $number,
                    'pull_request_url' => $pull['url'] ?? $artifact->url,
                    'head_sha' => $headSha,
                    'ci_state' => $ciState,
                    'actions_runs' => array_values((array) ($ci['actions_runs'] ?? [])),
                    'check_runs' => array_values((array) ($ci['check_runs'] ?? [])),
                    'combined_status' => is_array($ci['combined_status'] ?? null)
                        ? $ci['combined_status']
                        : null,
                ],
                confidence: 1.0,
                externalKey: 'github:'.$repo.':pull:'.$number.':ci:'.$headSha,
                occurredAt: $this->latestCiDate($ci) ?? now(),
            ));
        }

        return $records->filter()->values();
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $ci
     */
    private function latestCiDate(array $ci): ?CarbonImmutable
    {
        $dates = collect();

        foreach ((array) ($ci['actions_runs'] ?? []) as $run) {
            if (is_array($run) && filled($run['updated_at'] ?? null)) {
                $dates->push((string) $run['updated_at']);
            }
        }

        foreach ((array) ($ci['check_runs'] ?? []) as $check) {
            if (is_array($check) && filled($check['completed_at'] ?? null)) {
                $dates->push((string) $check['completed_at']);
            }
        }

        foreach ((array) data_get($ci, 'combined_status.statuses', []) as $status) {
            if (is_array($status) && filled($status['updated_at'] ?? null)) {
                $dates->push((string) $status['updated_at']);
            }
        }

        return $dates
            ->map(fn ($value) => $this->date($value))
            ->filter()
            ->sortByDesc(fn (CarbonImmutable $date) => $date->timestamp)
            ->first();
    }
}
