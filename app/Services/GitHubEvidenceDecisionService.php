<?php

namespace App\Services;

use App\Models\PlanArtifact;
use App\Models\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GitHubEvidenceDecisionService
{
    /**
     * Build a deterministic, human-reviewable Task state candidate from the
     * latest authoritative GitHub return snapshot.
     *
     * This method is pure: it never mutates Task / Evidence / Artifact state.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function candidate(
        Task $task,
        PlanArtifact $pullRequestArtifact,
        array $snapshot,
    ): array {
        $pull = (array) ($snapshot['pull_request'] ?? []);
        $review = (array) ($snapshot['review_summary'] ?? []);
        $ci = (array) ($snapshot['ci'] ?? []);

        $number = (int) ($pull['number'] ?? 0);
        $merged = (bool) ($pull['merged'] ?? false);
        $approvedReviewers = max(0, (int) ($review['approved_reviewers'] ?? 0));
        $changesRequestedReviewers = max(0, (int) ($review['changes_requested_reviewers'] ?? 0));
        $ciState = in_array(($ci['state'] ?? null), ['success', 'failure', 'pending', 'unknown'], true)
            ? (string) $ci['state']
            : 'unknown';

        $completed = $task->status === 'done' || (int) $task->progress_percent >= 100;
        $cancelled = $task->status === 'cancelled';
        $negativeSignal = $changesRequestedReviewers > 0 || $ciState === 'failure';

        $recommendation = 'wait';
        $headline = 'GitHubの結果を待つ';
        $reason = 'まだTask完了を判断できるremote factが揃っていません。';

        if ($cancelled) {
            $recommendation = 'no_change';
            $headline = 'このTaskは中止済みです';
            $reason = 'GitHub Evidenceは保持しますが、中止済みTaskをReturn Layerから再開しません。';
        } elseif ($completed) {
            $recommendation = 'no_change';
            $headline = 'このTaskはすでに完了扱いです';
            $reason = 'GitHub Evidenceは追加できますが、現在の完了状態をReturn Layerから上書きしません。';
        } elseif ($merged && $negativeSignal) {
            $recommendation = 'manual_review';
            $headline = 'Merge済みですが、追加確認が必要です';
            $reason = 'GitHubではmergeを確認しましたが、修正依頼またはCI失敗のsignalも残っています。完了か追加対応かを人が判断してください。';
        } elseif ($merged) {
            $recommendation = 'complete';
            $headline = 'Task完了の候補があります';
            $reason = 'GitHubでPull Requestのmergeを確認しました。Taskの成功条件を満たしたと判断できる場合だけ完了として反映できます。';
        } elseif ($negativeSignal) {
            $recommendation = 'continue';
            $headline = '修正対応を続ける候補があります';
            $reason = $changesRequestedReviewers > 0
                ? 'GitHubで修正依頼を確認しました。現在のTaskを維持したまま次の修正Actionへ進めます。'
                : 'GitHubでCI失敗を確認しました。現在のTaskを維持したまま原因確認・修正へ進めます。';
        } elseif ($ciState === 'pending') {
            $recommendation = 'wait';
            $headline = 'CI結果を待っています';
            $reason = 'GitHubでCI実行中を確認しました。Taskの現在地は変えず、結果確認を次Actionにできます。';
        } elseif ($approvedReviewers > 0 || $ciState === 'success') {
            $recommendation = 'wait';
            $headline = 'Merge待ちの候補です';
            $reason = '承認またはCI成功を確認しましたが、まだmergeを確認していません。Task完了には進めません。';
        }

        $allowedActions = [];
        if (! $cancelled && ! $completed) {
            if ($merged) {
                $allowedActions[] = 'complete';
            }
            if ($negativeSignal) {
                $allowedActions[] = 'continue';
            }
            $allowedActions[] = 'wait';
        }

        return [
            'schema_version' => '1.0',
            'flow' => 'github_evidence_decision',
            'task_id' => (int) $task->id,
            'task_title' => (string) $task->title,
            'pull_request_artifact_id' => (int) $pullRequestArtifact->id,
            'pull_request_number' => $number,
            'pull_request_url' => (string) ($pull['url'] ?? $pullRequestArtifact->url),
            'recommendation' => $recommendation,
            'headline' => $headline,
            'reason' => $reason,
            'signals' => [
                'merged' => $merged,
                'approved_reviewers' => $approvedReviewers,
                'changes_requested_reviewers' => $changesRequestedReviewers,
                'ci_state' => $ciState,
                'head_sha' => (string) ($pull['head_sha'] ?? ''),
                'merge_commit_sha' => (string) ($pull['merge_commit_sha'] ?? ''),
            ],
            'allowed_actions' => array_values(array_unique($allowedActions)),
            'proposed' => [
                'complete' => $merged && ! $completed && ! $cancelled
                    ? [
                        'status' => 'done',
                        'progress_percent' => 100,
                        'remaining_minutes' => 0,
                        'progress_reason' => $this->completionReason($number),
                    ]
                    : null,
                'continue' => $negativeSignal && ! $completed && ! $cancelled
                    ? [
                        'status' => 'doing',
                        'next_action_note' => $this->continueNote(
                            $number,
                            $changesRequestedReviewers,
                            $ciState,
                            $merged,
                        ),
                    ]
                    : null,
                'wait' => ! $completed && ! $cancelled
                    ? [
                        'next_action_note' => $this->waitNote(
                            $number,
                            $approvedReviewers,
                            $ciState,
                            $merged,
                        ),
                    ]
                    : null,
            ],
            'snapshot_fingerprint' => $this->snapshotFingerprint($snapshot),
            'task_fingerprint' => $this->taskFingerprint($task),
        ];
    }

    /**
     * Apply only one previously displayed deterministic action.
     *
     * Caller must re-fetch GitHub return state and rebuild the candidate before
     * calling this method.
     *
     * @param array<string,mixed> $candidate
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function apply(
        Task $task,
        array $candidate,
        string $action,
    ): array {
        if ((int) ($candidate['task_id'] ?? 0) !== (int) $task->id) {
            throw ValidationException::withMessages([
                'github_decision' => 'TaskとGitHub判断候補が一致しません。',
            ]);
        }

        if (! in_array($action, (array) ($candidate['allowed_actions'] ?? []), true)) {
            throw ValidationException::withMessages([
                'github_decision' => '現在のGitHub Evidenceではその操作を反映できません。',
            ]);
        }

        if (! hash_equals(
            (string) ($candidate['task_fingerprint'] ?? ''),
            $this->taskFingerprint($task),
        )) {
            throw ValidationException::withMessages([
                'github_decision' => 'Task状態が確認後に変わっています。現在状態から判断し直してください。',
            ]);
        }

        $changes = (array) data_get($candidate, 'proposed.'.$action, []);
        if ($changes === []) {
            throw ValidationException::withMessages([
                'github_decision' => '反映できるTask変更がありません。',
            ]);
        }

        $changes = Arr::only($changes, [
            'status',
            'progress_percent',
            'remaining_minutes',
            'progress_reason',
            'next_action_note',
        ]);

        $before = $task->only(array_keys($changes));
        $task->update($changes);
        $task->refresh();

        return [
            'before' => $before,
            'after' => $task->only(array_keys($changes)),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function snapshotFingerprint(array $snapshot): string
    {
        $payload = [
            'repo_full_name' => (string) ($snapshot['repo_full_name'] ?? ''),
            'pull_request' => Arr::only((array) ($snapshot['pull_request'] ?? []), [
                'number',
                'state',
                'draft',
                'merged',
                'merged_at',
                'merge_commit_sha',
                'head_sha',
                'head_ref',
                'base_ref',
                'updated_at',
                'closed_at',
            ]),
            'review_summary' => [
                'approved_reviewers' => (int) data_get($snapshot, 'review_summary.approved_reviewers', 0),
                'changes_requested_reviewers' => (int) data_get($snapshot, 'review_summary.changes_requested_reviewers', 0),
                'latest_decisions' => collect((array) data_get($snapshot, 'review_summary.latest_decisions', []))
                    ->filter(fn ($item) => is_array($item))
                    ->map(fn (array $item) => Arr::only($item, [
                        'id',
                        'state',
                        'reviewer',
                        'submitted_at',
                        'commit_id',
                    ]))
                    ->sortBy(fn (array $item) => ((string) ($item['reviewer'] ?? '')).':'.((string) ($item['id'] ?? '')))
                    ->values()
                    ->all(),
            ],
            'ci' => [
                'state' => (string) data_get($snapshot, 'ci.state', 'unknown'),
                'actions_runs' => collect((array) data_get($snapshot, 'ci.actions_runs', []))
                    ->filter(fn ($item) => is_array($item))
                    ->map(fn (array $item) => Arr::only($item, [
                        'id',
                        'run_attempt',
                        'status',
                        'conclusion',
                        'head_sha',
                        'updated_at',
                    ]))
                    ->sortBy(fn (array $item) => (int) ($item['id'] ?? 0))
                    ->values()
                    ->all(),
                'check_runs' => collect((array) data_get($snapshot, 'ci.check_runs', []))
                    ->filter(fn ($item) => is_array($item))
                    ->map(fn (array $item) => Arr::only($item, [
                        'id',
                        'status',
                        'conclusion',
                        'completed_at',
                    ]))
                    ->sortBy(fn (array $item) => (int) ($item['id'] ?? 0))
                    ->values()
                    ->all(),
                'combined_status' => is_array(data_get($snapshot, 'ci.combined_status'))
                    ? [
                        'state' => (string) data_get($snapshot, 'ci.combined_status.state', ''),
                        'total_count' => (int) data_get($snapshot, 'ci.combined_status.total_count', 0),
                    ]
                    : null,
            ],
        ];

        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function taskFingerprint(Task $task): string
    {
        $payload = [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
            'description' => $task->description,
            'status' => (string) $task->status,
            'progress_percent' => (int) $task->progress_percent,
            'remaining_minutes' => $task->remaining_minutes !== null
                ? (int) $task->remaining_minutes
                : null,
            'progress_reason' => $task->progress_reason,
            'next_action_note' => $task->next_action_note,
            'estimated_minutes' => (int) $task->estimated_minutes,
            'priority' => (int) $task->priority,
            'activation_cost' => (int) $task->activation_cost,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];

        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function completionReason(int $number): string
    {
        return $number > 0
            ? 'GitHub PR #'.$number.' のmergeを確認し、ユーザー確認によりTask完了として反映。'
            : 'GitHub上のmergeを確認し、ユーザー確認によりTask完了として反映。';
    }

    private function continueNote(
        int $number,
        int $changesRequestedReviewers,
        string $ciState,
        bool $merged,
    ): string {
        $prefix = $number > 0 ? 'PR #'.$number : '対象PR';

        if ($merged) {
            return $prefix.'はmerge済みですが、修正依頼またはCI失敗のsignalが残っています。追加対応が必要か確認する。';
        }

        if ($changesRequestedReviewers > 0 && $ciState === 'failure') {
            return $prefix.'の修正依頼とCI失敗を確認し、必要な修正を反映して再確認する。';
        }

        if ($changesRequestedReviewers > 0) {
            return $prefix.'の修正依頼を確認し、必要な修正を反映して再レビューへ出す。';
        }

        return $prefix.'のCI失敗を確認し、原因を修正して再実行する。';
    }

    private function waitNote(
        int $number,
        int $approvedReviewers,
        string $ciState,
        bool $merged,
    ): string {
        $prefix = $number > 0 ? 'PR #'.$number : '対象PR';

        if ($merged) {
            return $prefix.'のmerge後状態を確認し、Task完了条件を満たすか判断する。';
        }

        if ($ciState === 'pending') {
            return $prefix.'のCI結果を確認し、結果に応じて次の対応を判断する。';
        }

        if ($approvedReviewers > 0 || $ciState === 'success') {
            return $prefix.'のmerge結果を確認し、Task完了条件を満たすか判断する。';
        }

        return $prefix.'のReview / CI / Merge結果を確認する。';
    }
}
