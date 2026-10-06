<?php

namespace App\Services;

use App\Intelligence\Data\ActionProposal;
use App\Models\Task;

final class DevelopmentProviderTriageService
{
    /**
     * Project one explicit provider fetch into a bounded transient triage view.
     *
     * @param array<string,mixed> $executionContext
     * @param array<string,mixed> $providerSnapshot
     * @return array<string,mixed>
     */
    public function build(
        Task $task,
        array $executionContext,
        array $providerSnapshot,
        ?ActionProposal $action,
    ): array {
        $mode = (string) ($providerSnapshot['mode'] ?? 'auto');

        $ci = $this->ci((array) ($providerSnapshot['ci'] ?? []));
        $review = $this->review((array) ($providerSnapshot['review'] ?? []));

        $result = [
            'version' => 1,
            'transient' => true,
            'mode' => $mode,
            'fetched_at' => (string) ($providerSnapshot['fetched_at'] ?? ''),
            'target' => [
                'task_id' => (int) $task->id,
                'task_title' => mb_substr((string) $task->title, 0, 500),
                'repository' => mb_substr(
                    (string) ($providerSnapshot['repo_full_name']
                        ?? data_get($executionContext, 'repository')
                        ?? ''),
                    0,
                    255,
                ),
                'pull_request_number' => max(
                    0,
                    (int) data_get(
                        $providerSnapshot,
                        'pull_request.number',
                        data_get($executionContext, 'pull_request.number', 0),
                    ),
                ),
                'pull_request_title' => mb_substr(
                    (string) data_get(
                        $providerSnapshot,
                        'pull_request.title',
                        '',
                    ),
                    0,
                    500,
                ),
                'pull_request_url' => $this->githubUrl(data_get(
                    $providerSnapshot,
                    'pull_request.url',
                )),
                'head_ref' => mb_substr(
                    (string) data_get(
                        $providerSnapshot,
                        'pull_request.head_ref',
                        data_get($executionContext, 'branch.name', ''),
                    ),
                    0,
                    255,
                ),
                'head_sha' => mb_substr(
                    (string) data_get(
                        $providerSnapshot,
                        'pull_request.head_sha',
                        data_get($executionContext, 'commit.sha', ''),
                    ),
                    0,
                    64,
                ),
                'base_ref' => mb_substr(
                    (string) data_get(
                        $providerSnapshot,
                        'pull_request.base_ref',
                        '',
                    ),
                    0,
                    255,
                ),
            ],
            'action' => [
                'kind' => $action?->kind,
                'title' => $action?->title
                    ?: (string) data_get(
                        $executionContext,
                        'handoff.title',
                        'Provider detailを確認する',
                    ),
                'intent' => $action?->intent
                    ?: (string) data_get(
                        $executionContext,
                        'handoff.intent',
                        '',
                    ),
            ],
            'ci' => $ci,
            'review' => $review,
            'next_steps' => $this->nextSteps(
                $mode,
                $ci,
                $review,
                $task,
            ),
            'warnings' => array_values(array_unique(array_filter(array_map(
                fn ($value) => $this->text($value, 500),
                (array) ($providerSnapshot['warnings'] ?? []),
            )))),
            'guardrails' => [
                'この画面のReview本文・CI annotationは明示操作でGitHubから取得した一時表示です。',
                'Provider本文はTaskEvidence / PlanArtifact / Development Intelligenceへ保存しません。',
                '修正対象は現在Taskの範囲に限定し、別Taskの変更を混ぜません。',
                'このTriageだけでTask progress / status / remaining timeを変更しません。',
                'GitHubへのwriteは既存の権限境界とユーザー確認を通します。',
            ],
        ];

        $result['copy_text'] = $this->copyText($result);

        return $result;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function ci(array $raw): array
    {
        $runs = collect((array) ($raw['workflow_runs'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->filter(fn (array $item) => in_array(
                $item['conclusion'] ?? null,
                [
                    'failure',
                    'cancelled',
                    'timed_out',
                    'startup_failure',
                    'action_required',
                ],
                true,
            ))
            ->take(10)
            ->values()
            ->all();

        $jobs = collect((array) ($raw['jobs'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take(20)
            ->values()
            ->all();

        $checks = collect((array) ($raw['check_runs'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->filter(fn (array $item) => in_array(
                $item['conclusion'] ?? null,
                [
                    'failure',
                    'cancelled',
                    'timed_out',
                    'startup_failure',
                    'action_required',
                    'stale',
                ],
                true,
            ))
            ->take(20)
            ->values()
            ->all();

        $annotations = collect((array) ($raw['annotations'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take(60)
            ->values()
            ->all();

        $statuses = collect((array) ($raw['statuses'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take(30)
            ->values()
            ->all();

        return [
            'workflow_runs' => $runs,
            'jobs' => $jobs,
            'check_runs' => $checks,
            'annotations' => $annotations,
            'statuses' => $statuses,
            'failure_count' => count($jobs)
                + count($checks)
                + count($statuses),
            'annotation_count' => count($annotations),
            'has_detail' => $jobs !== []
                || $checks !== []
                || $annotations !== []
                || $statuses !== [],
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function review(array $raw): array
    {
        $reviews = collect((array) ($raw['reviews'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->filter(fn (array $item) =>
                in_array(
                    $item['state'] ?? null,
                    ['CHANGES_REQUESTED', 'COMMENTED'],
                    true,
                )
                || filled($item['body'] ?? null)
            )
            ->take(20)
            ->values()
            ->all();

        $comments = collect((array) ($raw['inline_comments'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->take(30)
            ->values()
            ->all();

        return [
            'reviews' => $reviews,
            'inline_comments' => $comments,
            'change_request_count' => collect($reviews)
                ->where('state', 'CHANGES_REQUESTED')
                ->count(),
            'comment_count' => count($comments),
            'has_detail' => $reviews !== [] || $comments !== [],
        ];
    }

    /**
     * @param array<string,mixed> $ci
     * @param array<string,mixed> $review
     * @return array<int,string>
     */
    private function nextSteps(
        string $mode,
        array $ci,
        array $review,
        Task $task,
    ): array {
        $steps = [];

        if (
            in_array($mode, ['auto', 'ci'], true)
            && (bool) ($ci['has_detail'] ?? false)
        ) {
            $annotation = collect((array) ($ci['annotations'] ?? []))
                ->first(fn ($item) => is_array($item));

            if (is_array($annotation)) {
                $location = trim(
                    (string) ($annotation['path'] ?? '')
                    .(
                        ! empty($annotation['start_line'])
                            ? ':'.(int) $annotation['start_line']
                            : ''
                    ),
                );

                $steps[] = $location !== ''
                    ? 'まず '.$location.' のCI annotationを確認し、現在Taskの範囲で原因を切り分ける。'
                    : 'まず最初のCI annotationを確認し、現在Taskの範囲で原因を切り分ける。';
            } else {
                $job = collect((array) ($ci['jobs'] ?? []))
                    ->first(fn ($item) => is_array($item));

                if (is_array($job)) {
                    $failedStep = collect((array) ($job['steps'] ?? []))
                        ->first(fn ($item) => is_array($item));
                    $label = trim((string) ($job['name'] ?? ''));

                    if (is_array($failedStep) && filled($failedStep['name'] ?? null)) {
                        $label .= ' / '.trim((string) $failedStep['name']);
                    }

                    $steps[] = '最初に失敗しているCI「'.($label !== '' ? $label : 'job').'」から原因を確認する。';
                } else {
                    $steps[] = '失敗しているCheck / Commit StatusをGitHub上で確認する。';
                }
            }

            $steps[] = '修正はTask「'.mb_substr((string) $task->title, 0, 180).'」に必要な範囲だけに限定する。';
            $steps[] = '修正後の同じPR / head SHAでCIを再実行し、success Evidenceを再観測する。';
        }

        if (
            in_array($mode, ['auto', 'review'], true)
            && (bool) ($review['has_detail'] ?? false)
        ) {
            $steps[] = 'Review本文・inline commentをGitHub上の原文と照合し、対応が必要な指摘だけを整理する。';
            $steps[] = '各指摘を現在Taskへ対応付け、無関係な改善を同じ変更へ混ぜない。';
            $steps[] = '修正を現在PRへ反映し、Review stateを再観測する。';
        }

        if ($steps === []) {
            $steps[] = 'GitHubから取得できた詳細だけでは原因を一意に絞れません。対象PRを開き、現在Actionに必要なProvider情報を確認します。';
            $steps[] = '確認できない事実は推測せず、次のEvidence更新後にCanoviaへ戻します。';
        }

        return array_slice(array_values(array_unique($steps)), 0, 6);
    }

    /**
     * @param array<string,mixed> $triage
     */
    private function copyText(array $triage): string
    {
        $target = (array) ($triage['target'] ?? []);
        $action = (array) ($triage['action'] ?? []);
        $ci = (array) ($triage['ci'] ?? []);
        $review = (array) ($triage['review'] ?? []);

        $lines = [
            '# Canovia PROVIDER-LINKED TRIAGE',
            '',
            'Action: '.($action['title'] ?? ''),
            'Intent: '.($action['intent'] ?? ''),
            '',
            '## Target',
            '- Task: '.($target['task_title'] ?? 'unknown'),
            '- Repository: '.($target['repository'] ?? 'unknown'),
            '- Pull Request: '.(
                ! empty($target['pull_request_number'])
                    ? '#'.$target['pull_request_number']
                    : 'unknown'
            ),
            '- Branch: '.($target['head_ref'] ?? 'unknown'),
            '- Head SHA: '.(
                filled($target['head_sha'] ?? null)
                    ? mb_substr((string) $target['head_sha'], 0, 12)
                    : 'unknown'
            ),
        ];

        if (! empty($ci['has_detail'])) {
            $lines[] = '';
            $lines[] = '## CI detail';

            foreach ((array) ($ci['jobs'] ?? []) as $job) {
                if (! is_array($job)) {
                    continue;
                }

                $lines[] = '- Job: '.($job['name'] ?? 'unknown')
                    .' / '.($job['conclusion'] ?? 'unknown');

                foreach ((array) ($job['steps'] ?? []) as $step) {
                    if (! is_array($step)) {
                        continue;
                    }

                    $lines[] = '  - Step: '.($step['name'] ?? 'unknown')
                        .' / '.($step['conclusion'] ?? 'unknown');
                }
            }

            foreach ((array) ($ci['annotations'] ?? []) as $annotation) {
                if (! is_array($annotation)) {
                    continue;
                }

                $location = trim(
                    (string) ($annotation['path'] ?? '')
                    .(
                        ! empty($annotation['start_line'])
                            ? ':'.(int) $annotation['start_line']
                            : ''
                    ),
                );

                $lines[] = '- Annotation'.($location !== '' ? ' '.$location : '')
                    .': '.trim((string) ($annotation['message'] ?? ''));
            }

            foreach ((array) ($ci['statuses'] ?? []) as $status) {
                if (! is_array($status)) {
                    continue;
                }

                $lines[] = '- Status: '.($status['context'] ?? 'unknown')
                    .' / '.($status['state'] ?? 'unknown')
                    .(
                        filled($status['description'] ?? null)
                            ? ' / '.$status['description']
                            : ''
                    );
            }
        }

        if (! empty($review['has_detail'])) {
            $lines[] = '';
            $lines[] = '## Review detail';

            foreach ((array) ($review['reviews'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $lines[] = '- Review: '.($item['state'] ?? 'unknown')
                    .' by '.($item['reviewer'] ?? 'unknown');

                if (filled($item['body'] ?? null)) {
                    $lines[] = '  '.trim((string) $item['body']);
                }
            }

            foreach ((array) ($review['inline_comments'] ?? []) as $comment) {
                if (! is_array($comment)) {
                    continue;
                }

                $location = trim(
                    (string) ($comment['path'] ?? '')
                    .(
                        ! empty($comment['line'])
                            ? ':'.(int) $comment['line']
                            : ''
                    ),
                );

                $lines[] = '- Inline comment'
                    .($location !== '' ? ' '.$location : '')
                    .' by '.($comment['reviewer'] ?? 'unknown')
                    .': '.trim((string) ($comment['body'] ?? ''));
            }
        }

        $lines[] = '';
        $lines[] = '## Next steps';
        foreach ((array) ($triage['next_steps'] ?? []) as $index => $step) {
            $lines[] = ($index + 1).'. '.$step;
        }

        $lines[] = '';
        $lines[] = '## Guardrails';
        foreach ((array) ($triage['guardrails'] ?? []) as $guardrail) {
            $lines[] = '- '.$guardrail;
        }

        return mb_substr(implode("\n", $lines), 0, 14_000);
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? mb_substr($text, 0, $limit) : null;
    }

    private function githubUrl(mixed $value): ?string
    {
        $url = $this->text($value, 2048);

        if ($url === null) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['github.com', 'www.github.com'], true)
            ? $url
            : null;
    }
}
