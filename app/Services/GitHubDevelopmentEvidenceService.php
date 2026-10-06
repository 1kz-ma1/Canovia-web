<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\GitHubWebhookDelivery;
use App\Models\PlanArtifact;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class GitHubDevelopmentEvidenceService
{
    public function __construct(
        private readonly GitHubRepositoryWriter $github,
        private readonly GitHubWorkflowService $workflow,
        private readonly TaskEvidenceService $evidence,
        private readonly FeatureAccessService $access,
    ) {}

    /**
     * Synchronize non-PR Development facts routed from an authenticated GitHub
     * webhook. The webhook is only a routing signal; every fact is re-read from
     * GitHub before TaskEvidence is written.
     *
     * @return array{matched_artifacts:int,synced_tasks:int,skipped_entitlement:int,plan_ids:array<int,int>}
     */
    /**
     * Synchronize one human-confirmed V57.2 Observation into the existing
     * non-PR Evidence pipeline.
     */
    public function syncObservation(
        DevelopmentActivityObservation $observation,
        Task $task,
        PlanArtifact $artifact,
    ): ?\App\Models\TaskEvidence {
        if (
            (int) $observation->plan_id !== (int) $task->plan_id
            || (int) $artifact->plan_id !== (int) $task->plan_id
            || $artifact->provider !== 'github'
            || ! $artifact->tasks()->whereKey($task->id)->exists()
        ) {
            return null;
        }

        $repository = $observation->repositoryArtifact;
        $parsedRepository = $repository instanceof PlanArtifact
            ? $this->workflow->parseUrl((string) $repository->url)
            : [];

        $repoFullName = trim((string) ($parsedRepository['repo_full_name'] ?? ''));
        if ($repoFullName === '') {
            return null;
        }

        $target = match ((string) $observation->kind) {
            'issue' => [
                'kind' => 'issue',
                'number' => (int) $observation->provider_number,
            ],
            'branch' => [
                'kind' => 'branch',
                'branch' => (string) $observation->ref,
            ],
            'commit' => [
                'kind' => 'commit',
                'commit_sha' => (string) $observation->sha,
                'branch' => (string) $observation->ref,
            ],
            default => null,
        };

        if (! is_array($target)) {
            return null;
        }

        $snapshot = $this->authoritativeSnapshot($repoFullName, $target);

        return $this->recordTargetEvidence(
            $task,
            [(int) $artifact->id],
            $target,
            $snapshot,
        );
    }

    public function syncDelivery(
        GitHubWebhookDelivery $delivery,
    ): array {
        $repoFullName = trim((string) $delivery->repo_full_name);
        $installationId = (int) $delivery->installation_id;

        $targets = collect((array) $delivery->routing_targets)
            ->filter(fn ($target) => is_array($target))
            ->reject(
                fn (array $target) =>
                    (string) ($target['kind'] ?? '') === 'pull_request'
            )
            ->values();

        if (
            $repoFullName === ''
            || $installationId <= 0
            || $targets->isEmpty()
        ) {
            return $this->emptyCounts();
        }

        $artifacts = PlanArtifact::query()
            ->with(['plan.user', 'tasks'])
            ->where('provider', 'github')
            ->get()
            ->filter(function (PlanArtifact $artifact) use ($repoFullName) {
                $parsed = $this->workflow->parseUrl((string) $artifact->url);

                return ($parsed['valid'] ?? false)
                    && strcasecmp(
                        (string) ($parsed['repo_full_name'] ?? ''),
                        $repoFullName,
                    ) === 0;
            })
            ->values();

        if ($artifacts->isEmpty()) {
            return $this->emptyCounts();
        }

        $connectedPlanIds = $artifacts
            ->filter(function (PlanArtifact $artifact) use ($installationId) {
                if (
                    ($this->workflow->parseUrl((string) $artifact->url)['kind'] ?? null)
                    !== 'repository'
                ) {
                    return false;
                }

                return data_get(
                    $artifact->metadata,
                    'github_app_connection.status',
                ) === 'connected'
                    && (int) data_get(
                        $artifact->metadata,
                        'github_app_connection.installation_id',
                        0,
                    ) === $installationId;
            })
            ->pluck('plan_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($connectedPlanIds->isEmpty()) {
            return $this->emptyCounts();
        }

        $counts = $this->emptyCounts();
        $syncedTaskKeys = [];
        $syncedPlanIds = [];

        foreach ($targets as $target) {
            $preCandidates = $this->preCandidates(
                $artifacts,
                $connectedPlanIds,
                $target,
            );

            if ($preCandidates->isEmpty()) {
                continue;
            }

            $allowedPlanIds = collect();
            foreach ($preCandidates
                ->pluck('plan_id')
                ->map(fn ($id) => (int) $id)
                ->unique() as $planId) {
                $planArtifact = $preCandidates->firstWhere('plan_id', $planId);
                $plan = $planArtifact?->plan;

                if (! $plan) {
                    continue;
                }

                if (! $this->access->canUse(
                    $plan->user,
                    FeatureKey::DeveloperGithubEvidence,
                    [
                        'plan_id' => (int) $plan->id,
                        'source' => 'github_webhook',
                        'event_name' => (string) $delivery->event_name,
                    ],
                )) {
                    $counts['skipped_entitlement']++;
                    continue;
                }

                $allowedPlanIds->push((int) $plan->id);
            }

            $allowedPlanIds = $allowedPlanIds->unique()->values();
            if ($allowedPlanIds->isEmpty()) {
                continue;
            }

            $snapshot = $this->authoritativeSnapshot(
                $repoFullName,
                $target,
            );

            $matched = $this->exactCandidates(
                $preCandidates,
                $target,
                $snapshot,
            )
                ->filter(
                    fn (PlanArtifact $artifact) =>
                        $allowedPlanIds->contains((int) $artifact->plan_id)
                )
                ->unique('id')
                ->values();

            if ($matched->isEmpty()) {
                continue;
            }

            $counts['matched_artifacts'] += $matched->count();

            $taskLinks = $this->taskLinks($matched);
            foreach ($taskLinks as $link) {
                /** @var Task $task */
                $task = $link['task'];
                $artifactIds = $link['artifact_ids'];

                $evidence = $this->recordTargetEvidence(
                    $task,
                    $artifactIds,
                    $target,
                    $snapshot,
                );

                if (! $evidence) {
                    continue;
                }

                $syncKey = $task->id.'|'.$evidence->type.'|'.$evidence->external_key;
                if (! isset($syncedTaskKeys[$syncKey])) {
                    $counts['synced_tasks']++;
                    $syncedTaskKeys[$syncKey] = true;
                    $syncedPlanIds[(int) $task->plan_id] = true;
                }
            }
        }

        $counts['plan_ids'] = array_values(array_map(
            'intval',
            array_keys($syncedPlanIds),
        ));

        return $counts;
    }

    /**
     * @param Collection<int,PlanArtifact> $artifacts
     * @param Collection<int,int> $connectedPlanIds
     * @param array<string,mixed> $target
     * @return Collection<int,PlanArtifact>
     */
    private function preCandidates(
        Collection $artifacts,
        Collection $connectedPlanIds,
        array $target,
    ): Collection {
        $kind = (string) ($target['kind'] ?? '');

        return $artifacts
            ->filter(
                fn (PlanArtifact $artifact) =>
                    $connectedPlanIds->contains((int) $artifact->plan_id)
                    && $artifact->tasks->isNotEmpty()
                    && ($this->workflow->parseUrl((string) $artifact->url)['kind'] ?? null)
                        !== 'repository'
            )
            ->filter(function (PlanArtifact $artifact) use ($target, $kind) {
                $parsed = $this->workflow->parseUrl((string) $artifact->url);

                return match ($kind) {
                    'issue' => ($parsed['kind'] ?? null) === 'issue'
                        && (int) ($parsed['number'] ?? 0)
                            === (int) ($target['number'] ?? 0),
                    'branch' => ($parsed['kind'] ?? null) === 'branch'
                        && $this->sameRef(
                            (string) ($parsed['branch'] ?? ''),
                            (string) ($target['branch'] ?? ''),
                        ),
                    'push' => (
                        (($parsed['kind'] ?? null) === 'branch'
                            && $this->sameRef(
                                (string) ($parsed['branch'] ?? ''),
                                (string) ($target['branch'] ?? ''),
                            ))
                        || (($parsed['kind'] ?? null) === 'commit'
                            && $this->commitArtifactMatches(
                                $artifact,
                                (string) ($target['commit_sha'] ?? ''),
                            ))
                    ),
                    // Deployment SHA/ref are authoritative facts that are only
                    // known after re-fetch. Preselect connected development
                    // artifacts, then narrow them in exactCandidates().
                    'deployment' => in_array(
                        (string) ($parsed['kind'] ?? ''),
                        ['pull_request', 'branch', 'commit'],
                        true,
                    ),
                    default => false,
                };
            })
            ->values();
    }

    /**
     * @param Collection<int,PlanArtifact> $candidates
     * @param array<string,mixed> $target
     * @param array<string,mixed> $snapshot
     * @return Collection<int,PlanArtifact>
     */
    private function exactCandidates(
        Collection $candidates,
        array $target,
        array $snapshot,
    ): Collection {
        if ((string) ($target['kind'] ?? '') !== 'deployment') {
            return $candidates;
        }

        $sha = mb_strtolower(trim((string) data_get(
            $snapshot,
            'deployment.sha',
            '',
        )));
        $ref = trim((string) data_get(
            $snapshot,
            'deployment.ref',
            '',
        ));

        if ($sha === '' && $ref === '') {
            return collect();
        }

        return $candidates
            ->filter(function (PlanArtifact $artifact) use ($sha, $ref) {
                $parsed = $this->workflow->parseUrl((string) $artifact->url);
                $kind = (string) ($parsed['kind'] ?? '');

                if (
                    $kind === 'branch'
                    && $ref !== ''
                    && $this->sameRef(
                        (string) ($parsed['branch'] ?? ''),
                        $ref,
                    )
                ) {
                    return true;
                }

                if (
                    $kind === 'commit'
                    && $sha !== ''
                    && $this->commitArtifactMatches($artifact, $sha)
                ) {
                    return true;
                }

                if ($kind !== 'pull_request' || $sha === '') {
                    return false;
                }

                $pull = (array) data_get(
                    $artifact->metadata,
                    'github_return_snapshot.pull_request',
                    [],
                );

                return $this->sameSha(
                    (string) ($pull['head_sha'] ?? ''),
                    $sha,
                ) || $this->sameSha(
                    (string) ($pull['merge_commit_sha'] ?? ''),
                    $sha,
                );
            })
            ->values();
    }

    /**
     * @param Collection<int,PlanArtifact> $artifacts
     * @return Collection<int,array{task:Task,artifact_ids:array<int,int>}>
     */
    private function taskLinks(Collection $artifacts): Collection
    {
        return $artifacts
            ->flatMap(function (PlanArtifact $artifact) {
                return $artifact->tasks
                    ->filter(
                        fn (Task $task) =>
                            (int) $task->plan_id === (int) $artifact->plan_id
                    )
                    ->map(fn (Task $task) => [
                        'task' => $task,
                        'artifact_id' => (int) $artifact->id,
                    ]);
            })
            ->groupBy(fn (array $link) => (int) $link['task']->id)
            ->map(function (Collection $links) {
                return [
                    'task' => $links->first()['task'],
                    'artifact_ids' => $links
                        ->pluck('artifact_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private function authoritativeSnapshot(
        string $repoFullName,
        array $target,
    ): array {
        return match ((string) ($target['kind'] ?? '')) {
            'issue' => $this->github->inspectIssue(
                $repoFullName,
                (int) ($target['number'] ?? 0),
            ),
            'branch' => $this->github->inspectBranch(
                $repoFullName,
                (string) ($target['branch'] ?? ''),
            ),
            'push' => [
                'repo_full_name' => $repoFullName,
                'fetched_at' => now()->toIso8601String(),
                'branch_snapshot' => $this->github->inspectBranch(
                    $repoFullName,
                    (string) ($target['branch'] ?? ''),
                ),
                'commit_snapshot' => $this->github->inspectCommit(
                    $repoFullName,
                    (string) ($target['commit_sha'] ?? ''),
                ),
            ],
            'commit' => $this->github->inspectCommit(
                $repoFullName,
                (string) ($target['commit_sha'] ?? ''),
            ),
            'deployment' => $this->github->inspectDeployment(
                $repoFullName,
                (int) ($target['deployment_id'] ?? 0),
            ),
            default => [],
        };
    }

    /**
     * @param array<int,int> $artifactIds
     * @param array<string,mixed> $target
     * @param array<string,mixed> $snapshot
     */
    private function recordTargetEvidence(
        Task $task,
        array $artifactIds,
        array $target,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $kind = (string) ($target['kind'] ?? '');

        return match ($kind) {
            'issue' => $this->recordIssue(
                $task,
                $artifactIds,
                $snapshot,
            ),
            'branch' => $this->recordBranch(
                $task,
                $artifactIds,
                $snapshot,
            ),
            'push' => $this->recordPush(
                $task,
                $artifactIds,
                $target,
                $snapshot,
            ),
            'commit' => $this->recordCommit(
                $task,
                $artifactIds,
                $target,
                $snapshot,
            ),
            'deployment' => $this->recordDeployment(
                $task,
                $artifactIds,
                $snapshot,
            ),
            default => null,
        };
    }

    private function recordIssue(
        Task $task,
        array $artifactIds,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $repo = (string) ($snapshot['repo_full_name'] ?? '');
        $issue = (array) ($snapshot['issue'] ?? []);
        $number = (int) ($issue['number'] ?? 0);
        $state = trim((string) ($issue['state'] ?? ''));

        if ($repo === '' || $number <= 0 || $state === '') {
            return null;
        }

        $fingerprint = hash(
            'sha256',
            (string) json_encode([
                'state' => $state,
                'state_reason' => $issue['state_reason'] ?? null,
                'locked' => (bool) ($issue['locked'] ?? false),
                'assignee_count' => (int) ($issue['assignee_count'] ?? 0),
                'updated_at' => $issue['updated_at'] ?? null,
                'closed_at' => $issue['closed_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $this->evidence->record(
            $task,
            EvidenceSource::GitHub,
            'github_issue_observed',
            [
                'plan_artifact_id' => $artifactIds[0] ?? null,
                'plan_artifact_ids' => $artifactIds,
                'repo_full_name' => $repo,
                'issue_number' => $number,
                'issue_state' => $state,
                'state_reason' => $issue['state_reason'] ?? null,
                'locked' => (bool) ($issue['locked'] ?? false),
                'assignee_count' => (int) ($issue['assignee_count'] ?? 0),
            ],
            confidence: 1.0,
            externalKey: 'github:'.$repo.':issue:'.$number.':state:'.$fingerprint,
            occurredAt: $this->date(
                $issue['closed_at'] ?? $issue['updated_at'] ?? null,
            ) ?? now(),
        );
    }

    private function recordBranch(
        Task $task,
        array $artifactIds,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $repo = (string) ($snapshot['repo_full_name'] ?? '');
        $branch = (array) ($snapshot['branch'] ?? []);
        $name = trim((string) ($branch['name'] ?? ''));
        $headSha = mb_strtolower(trim((string) ($branch['head_sha'] ?? '')));

        if ($repo === '' || $name === '' || $headSha === '') {
            return null;
        }

        return $this->evidence->record(
            $task,
            EvidenceSource::GitHub,
            'github_branch_observed',
            [
                'plan_artifact_id' => $artifactIds[0] ?? null,
                'plan_artifact_ids' => $artifactIds,
                'repo_full_name' => $repo,
                'branch' => $name,
                'head_sha' => $headSha,
                'protected' => (bool) ($branch['protected'] ?? false),
            ],
            confidence: 1.0,
            externalKey: 'github:'.$repo.':branch:'.hash('sha256', $name).':head:'.$headSha,
            occurredAt: $this->date($snapshot['fetched_at'] ?? null) ?? now(),
        );
    }

    private function recordPush(
        Task $task,
        array $artifactIds,
        array $target,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $branchSnapshot = (array) ($snapshot['branch_snapshot'] ?? []);
        if (is_array($branchSnapshot['branch'] ?? null)) {
            $this->recordBranch(
                $task,
                $artifactIds,
                $branchSnapshot,
            );
        }

        $commitSnapshot = (array) ($snapshot['commit_snapshot'] ?? []);
        $commit = (array) ($commitSnapshot['commit'] ?? []);
        $repo = (string) (
            $commitSnapshot['repo_full_name']
            ?? $snapshot['repo_full_name']
            ?? ''
        );
        $sha = mb_strtolower(trim((string) ($commit['sha'] ?? '')));

        if ($repo === '' || $sha === '') {
            return null;
        }

        // The branch observation is also useful but Commit is the canonical
        // push Evidence. The branch head remains available from a separate
        // branch Artifact / create event.
        return $this->evidence->record(
            $task,
            EvidenceSource::GitHub,
            'github_commit_observed',
            [
                'plan_artifact_id' => $artifactIds[0] ?? null,
                'plan_artifact_ids' => $artifactIds,
                'repo_full_name' => $repo,
                'commit_sha' => $sha,
                'branch' => trim((string) ($target['branch'] ?? '')) ?: null,
                'parent_count' => (int) ($commit['parent_count'] ?? 0),
                'verified' => (bool) ($commit['verified'] ?? false),
            ],
            confidence: 1.0,
            externalKey: 'github:'.$repo.':commit:'.$sha,
            occurredAt: $this->date(
                $commit['committed_at'] ?? $commit['authored_at'] ?? null,
            ) ?? now(),
        );
    }

    private function recordCommit(
        Task $task,
        array $artifactIds,
        array $target,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $commit = (array) ($snapshot['commit'] ?? []);
        $repo = (string) ($snapshot['repo_full_name'] ?? '');
        $sha = mb_strtolower(trim((string) ($commit['sha'] ?? '')));

        if ($repo === '' || $sha === '') {
            return null;
        }

        return $this->evidence->record(
            $task,
            EvidenceSource::GitHub,
            'github_commit_observed',
            [
                'plan_artifact_id' => $artifactIds[0] ?? null,
                'plan_artifact_ids' => $artifactIds,
                'repo_full_name' => $repo,
                'commit_sha' => $sha,
                'branch' => trim((string) ($target['branch'] ?? '')) ?: null,
                'parent_count' => (int) ($commit['parent_count'] ?? 0),
                'verified' => (bool) ($commit['verified'] ?? false),
            ],
            confidence: 1.0,
            externalKey: 'github:'.$repo.':commit:'.$sha,
            occurredAt: $this->date(
                $commit['committed_at'] ?? $commit['authored_at'] ?? null,
            ) ?? now(),
        );
    }

    private function recordDeployment(
        Task $task,
        array $artifactIds,
        array $snapshot,
    ): ?\App\Models\TaskEvidence {
        $repo = (string) ($snapshot['repo_full_name'] ?? '');
        $deployment = (array) ($snapshot['deployment'] ?? []);
        $id = (int) ($deployment['id'] ?? 0);
        $status = trim((string) ($deployment['latest_status'] ?? ''))
            ?: 'created';

        if ($repo === '' || $id <= 0) {
            return null;
        }

        $statusFingerprint = hash(
            'sha256',
            (string) json_encode([
                'status' => $status,
                'status_at' => $deployment['latest_status_at'] ?? null,
                'sha' => $deployment['sha'] ?? null,
                'ref' => $deployment['ref'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $this->evidence->record(
            $task,
            EvidenceSource::GitHub,
            'github_deployment_observed',
            [
                'plan_artifact_id' => $artifactIds[0] ?? null,
                'plan_artifact_ids' => $artifactIds,
                'repo_full_name' => $repo,
                'deployment_id' => $id,
                'deployment_sha' => (string) ($deployment['sha'] ?? ''),
                'ref' => (string) ($deployment['ref'] ?? ''),
                'environment' => (string) ($deployment['environment'] ?? ''),
                'deployment_status' => $status,
                'production_environment' => (bool) (
                    $deployment['production_environment'] ?? false
                ),
                'transient_environment' => (bool) (
                    $deployment['transient_environment'] ?? false
                ),
            ],
            confidence: 1.0,
            externalKey: 'github:'.$repo.':deployment:'.$id.':state:'.$statusFingerprint,
            occurredAt: $this->date(
                $deployment['latest_status_at']
                ?? $deployment['updated_at']
                ?? $deployment['created_at']
                ?? null,
            ) ?? now(),
        );
    }

    private function commitArtifactMatches(
        PlanArtifact $artifact,
        string $expectedSha,
    ): bool {
        $expectedSha = mb_strtolower(trim($expectedSha));
        if ($expectedSha === '') {
            return false;
        }

        $path = trim((string) parse_url((string) $artifact->url, PHP_URL_PATH), '/');
        $segments = array_values(array_filter(explode('/', $path)));
        $commitIndex = array_search('commit', $segments, true);

        if ($commitIndex === false || ! isset($segments[$commitIndex + 1])) {
            return false;
        }

        return $this->sameSha(
            rawurldecode((string) $segments[$commitIndex + 1]),
            $expectedSha,
        );
    }

    private function sameSha(string $left, string $right): bool
    {
        $left = mb_strtolower(trim($left));
        $right = mb_strtolower(trim($right));

        return $left !== ''
            && $right !== ''
            && (
                hash_equals($left, $right)
                || str_starts_with($left, $right)
                || str_starts_with($right, $left)
            );
    }

    private function sameRef(string $left, string $right): bool
    {
        return $left !== ''
            && $right !== ''
            && hash_equals(
                mb_strtolower(trim($left)),
                mb_strtolower(trim($right)),
            );
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
     * @return array{matched_artifacts:int,synced_tasks:int,skipped_entitlement:int,plan_ids:array<int,int>}
     */
    private function emptyCounts(): array
    {
        return [
            'matched_artifacts' => 0,
            'synced_tasks' => 0,
            'skipped_entitlement' => 0,
            'plan_ids' => [],
        ];
    }
}
