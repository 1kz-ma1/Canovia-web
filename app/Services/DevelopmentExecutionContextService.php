<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Intelligence\Data\ActionProposal;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Support\Collection;

final class DevelopmentExecutionContextService
{
    private const GITHUB_TYPES = [
        'pull_request_observed',
        'pull_request_review_submitted',
        'pull_request_ci_observed',
        'pull_request_merged',
        'github_issue_observed',
        'github_branch_observed',
        'github_commit_observed',
        'github_deployment_observed',
    ];

    /**
     * Build bounded, task-scoped execution context from already persisted
     * GitHub Evidence. This method never calls GitHub or AI.
     *
     * @return array<string,mixed>|null
     */
    public function build(
        Plan $plan,
        ?int $preferredTaskId,
        ?ActionProposal $action,
    ): ?array {
        $task = $this->targetTask($plan, $preferredTaskId);

        if (! $task instanceof Task) {
            return null;
        }

        $evidence = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('source', EvidenceSource::GitHub->value)
            ->whereIn('type', self::GITHUB_TYPES)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(40)
            ->get();

        $latest = $evidence
            ->unique('type')
            ->mapWithKeys(fn (TaskEvidence $item) => [$item->type => $item]);

        $task->loadMissing([
            'artifacts' => fn ($query) =>
                $query->where('provider', 'github')
                    ->latest('updated_at')
                    ->latest('id'),
        ]);

        $repository = $this->repository($evidence, $task);
        $pull = $this->pullRequest($latest);
        $ci = $this->ci($latest);
        $review = $this->review($latest);
        $issue = $this->issue($latest);
        $branch = $this->branch($latest);
        $commit = $this->commit($latest);
        $deployment = $this->deployment($latest);

        return [
            'task' => $task,
            'repository' => $repository,
            'branch' => $branch,
            'commit' => $commit,
            'pull_request' => $pull,
            'ci' => $ci,
            'review' => $review,
            'issue' => $issue,
            'deployment' => $deployment,
            'linked_artifacts' => $task->artifacts
                ->take(6)
                ->map(fn ($artifact) => [
                    'id' => (int) $artifact->id,
                    'title' => (string) $artifact->title,
                    'url' => (string) $artifact->url,
                    'workflow_state' => $artifact->githubWorkflowState(),
                    'workflow_label' => $artifact->githubWorkflowStateLabel(),
                ])
                ->values()
                ->all(),
            'recent_evidence' => $evidence
                ->take(5)
                ->map(fn (TaskEvidence $item) => [
                    'id' => (int) $item->id,
                    'type' => (string) $item->type,
                    'label' => $item->typeLabel(),
                    'summary' => $item->summary(),
                    'occurred_at' => $item->occurred_at,
                ])
                ->values()
                ->all(),
            'handoff' => $this->handoff($action, $evidence->isEmpty()),
            'has_github_evidence' => $evidence->isNotEmpty(),
            'latest_evidence_at' => $evidence->first()?->occurred_at,
        ];
    }

    private function targetTask(
        Plan $plan,
        ?int $preferredTaskId,
    ): ?Task {
        $plan->loadMissing('tasks');

        if ($preferredTaskId !== null && $preferredTaskId > 0) {
            $preferred = $plan->tasks->firstWhere('id', $preferredTaskId);
            if (
                $preferred instanceof Task
                && ! in_array($preferred->status, ['done', 'cancelled'], true)
            ) {
                return $preferred;
            }
        }

        return $plan->tasks
            ->filter(fn (Task $task) =>
                ! in_array($task->status, ['done', 'cancelled'], true)
            )
            ->sort(function (Task $left, Task $right) {
                $statusRank = [
                    'doing' => 0,
                    'todo' => 1,
                    'paused' => 2,
                ];

                $status = ($statusRank[$left->status] ?? 3)
                    <=> ($statusRank[$right->status] ?? 3);

                if ($status !== 0) {
                    return $status;
                }

                $priority = (int) $left->priority
                    <=> (int) $right->priority;

                if ($priority !== 0) {
                    return $priority;
                }

                $sort = (int) $left->sort_order
                    <=> (int) $right->sort_order;

                return $sort !== 0
                    ? $sort
                    : ((int) $left->id <=> (int) $right->id);
            })
            ->first();
    }

    /**
     * @param Collection<int,TaskEvidence> $evidence
     */
    private function repository(
        Collection $evidence,
        Task $task,
    ): ?string {
        $fromEvidence = $evidence
            ->map(fn (TaskEvidence $item) =>
                trim((string) data_get($item->metadata, 'repo_full_name'))
            )
            ->first(fn (string $value) => $value !== '');

        if (is_string($fromEvidence) && $fromEvidence !== '') {
            return mb_substr($fromEvidence, 0, 255);
        }

        foreach ($task->artifacts as $artifact) {
            $repo = $this->repoFromUrl((string) $artifact->url);
            if ($repo !== null) {
                return $repo;
            }
        }

        return null;
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function pullRequest(Collection $latest): ?array
    {
        $observed = $latest->get('pull_request_observed');
        $merged = $latest->get('pull_request_merged');
        $source = $observed ?? $merged;

        if (! $source instanceof TaskEvidence) {
            return null;
        }

        $number = (int) data_get(
            $source->metadata,
            'pull_request_number',
            0,
        );

        if ($number <= 0) {
            return null;
        }

        $mergedIsLatest = $merged instanceof TaskEvidence
            && (
                ! $observed instanceof TaskEvidence
                || $merged->occurred_at?->gte($observed->occurred_at)
            );

        return [
            'number' => $number,
            'state' => $mergedIsLatest
                ? 'merged'
                : trim((string) data_get(
                    $observed?->metadata,
                    'state',
                    'unknown',
                )),
            'draft' => (bool) data_get(
                $observed?->metadata,
                'draft',
                false,
            ),
            'url' => trim((string) data_get(
                $source->metadata,
                'pull_request_url',
                '',
            )) ?: null,
            'head_ref' => trim((string) data_get(
                $observed?->metadata,
                'head_ref',
                '',
            )) ?: null,
            'base_ref' => trim((string) data_get(
                $observed?->metadata,
                'base_ref',
                '',
            )) ?: null,
            'head_sha' => trim((string) data_get(
                $observed?->metadata,
                'head_sha',
                '',
            )) ?: null,
            'occurred_at' => $source->occurred_at,
        ];
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function ci(Collection $latest): ?array
    {
        $item = $latest->get('pull_request_ci_observed');

        if (! $item instanceof TaskEvidence) {
            return null;
        }

        return [
            'state' => trim((string) data_get(
                $item->metadata,
                'ci_state',
                'unknown',
            )),
            'head_sha' => trim((string) data_get(
                $item->metadata,
                'head_sha',
                '',
            )) ?: null,
            'occurred_at' => $item->occurred_at,
        ];
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function review(Collection $latest): ?array
    {
        $item = $latest->get('pull_request_review_submitted');

        if (! $item instanceof TaskEvidence) {
            return null;
        }

        return [
            'state' => strtoupper(trim((string) data_get(
                $item->metadata,
                'review_state',
                'UNKNOWN',
            ))),
            'reviewer' => trim((string) data_get(
                $item->metadata,
                'reviewer',
                '',
            )) ?: null,
            'url' => trim((string) data_get(
                $item->metadata,
                'review_url',
                '',
            )) ?: null,
            'occurred_at' => $item->occurred_at,
        ];
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function issue(Collection $latest): ?array
    {
        $item = $latest->get('github_issue_observed');

        if (! $item instanceof TaskEvidence) {
            return null;
        }

        $number = (int) data_get($item->metadata, 'issue_number', 0);

        return $number > 0
            ? [
                'number' => $number,
                'state' => trim((string) data_get(
                    $item->metadata,
                    'issue_state',
                    'unknown',
                )),
                'occurred_at' => $item->occurred_at,
            ]
            : null;
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function branch(Collection $latest): ?array
    {
        $branch = $latest->get('github_branch_observed');
        $commit = $latest->get('github_commit_observed');
        $pull = $latest->get('pull_request_observed');

        $name = trim((string) data_get(
            $branch?->metadata,
            'branch',
            data_get(
                $commit?->metadata,
                'branch',
                data_get($pull?->metadata, 'head_ref', ''),
            ),
        ));

        if ($name === '') {
            return null;
        }

        return [
            'name' => mb_substr($name, 0, 255),
            'head_sha' => trim((string) data_get(
                $branch?->metadata,
                'head_sha',
                '',
            )) ?: null,
            'protected' => $branch instanceof TaskEvidence
                ? (bool) data_get($branch->metadata, 'protected', false)
                : null,
        ];
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function commit(Collection $latest): ?array
    {
        $item = $latest->get('github_commit_observed');

        if (! $item instanceof TaskEvidence) {
            return null;
        }

        $sha = trim((string) data_get($item->metadata, 'commit_sha', ''));

        return $sha !== ''
            ? [
                'sha' => $sha,
                'verified' => (bool) data_get(
                    $item->metadata,
                    'verified',
                    false,
                ),
                'occurred_at' => $item->occurred_at,
            ]
            : null;
    }

    /**
     * @param Collection<string,TaskEvidence> $latest
     * @return array<string,mixed>|null
     */
    private function deployment(Collection $latest): ?array
    {
        $item = $latest->get('github_deployment_observed');

        if (! $item instanceof TaskEvidence) {
            return null;
        }

        return [
            'environment' => trim((string) data_get(
                $item->metadata,
                'environment',
                '',
            )) ?: null,
            'status' => trim((string) data_get(
                $item->metadata,
                'deployment_status',
                'unknown',
            )),
            'production' => (bool) data_get(
                $item->metadata,
                'production_environment',
                false,
            ),
            'sha' => trim((string) data_get(
                $item->metadata,
                'deployment_sha',
                '',
            )) ?: null,
            'occurred_at' => $item->occurred_at,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function handoff(
        ?ActionProposal $action,
        bool $evidenceMissing,
    ): array {
        if (! $action instanceof ActionProposal) {
            return [
                'title' => '次の開発Actionを確認する',
                'intent' => '現在のTaskとEvidenceから、次に必要な作業を確認します。',
                'route_kind' => null,
                'done_when' => [],
                'evidence_hint' => $evidenceMissing
                    ? 'GitHub EvidenceをTaskへ関連付けると、Repository / PR / CIのContextがここに集約されます。'
                    : '現在のEvidenceを更新すると次のActionが再計算されます。',
            ];
        }

        return [
            'title' => $action->title,
            'intent' => $action->intent,
            'route_kind' => data_get($action->metadata, 'route_kind'),
            'done_when' => array_values(array_filter(array_map(
                fn ($value) => trim((string) $value),
                $action->successSignals,
            ))),
            'evidence_hint' => $this->evidenceHint(
                $action->kind,
                $evidenceMissing,
            ),
        ];
    }

    private function evidenceHint(
        string $kind,
        bool $evidenceMissing,
    ): string {
        if ($evidenceMissing) {
            return 'まず対象TaskとGitHubのRepository / PR / Issue / Branchを結び、現実の開発Stateを観測できるようにします。';
        }

        return match ($kind) {
            'development_fix_ci',
            'development_run_ci' =>
                '次に必要なのは、このTaskに紐づく同じPR / head SHAのCI success Evidenceです。',
            'development_review_fix',
            'development_obtain_review' =>
                '修正後のReview stateを再観測し、Approvalまたは新しい修正依頼を確認します。',
            'development_merge',
            'development_restore_merge_path' =>
                '現在PRのmerge状態を更新し、Release経路が成立したことをEvidenceで確認します。',
            'development_deploy',
            'development_fix_deploy' =>
                'merge後のSHAに対するProduction deployment successを確認します。',
            'development_verify',
            'development_fix_verification' =>
                '現在Releaseを実機・本番で確認し、Verification Gateへ明示結果を残します。',
            'development_sync_spec' =>
                '現在実装と仕様・引き継ぎの同期を確認し、Spec Sync Gateへ明示結果を残します。',
            'development_implement' =>
                '変更をCommitまたはPull Requestとして観測できる状態まで進めます。',
            'development_release_ready' =>
                '現在の7 Gateが同じRelease候補に対して成立したままか最終確認します。',
            default =>
                'このActionの完了後にGitHub / Gate Evidenceを更新すると、Canoviaが次のActionを再判定します。',
        };
    }

    private function repoFromUrl(string $url): ?string
    {
        $host = mb_strtolower(trim((string) parse_url($url, PHP_URL_HOST)));

        if (! in_array($host, ['github.com', 'www.github.com'], true)) {
            return null;
        }

        $segments = array_values(array_filter(explode(
            '/',
            trim((string) parse_url($url, PHP_URL_PATH), '/'),
        )));

        if (count($segments) < 2) {
            return null;
        }

        return mb_substr(
            rawurldecode($segments[0]).'/'.rawurldecode($segments[1]),
            0,
            255,
        );
    }
}
