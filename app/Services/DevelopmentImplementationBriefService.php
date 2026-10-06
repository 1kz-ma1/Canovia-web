<?php

namespace App\Services;

use App\Intelligence\Data\ActionProposal;
use App\Models\Task;

final class DevelopmentImplementationBriefService
{
    /**
     * Convert the bounded V57.4 execution context into a deterministic,
     * copy-ready implementation brief. No GitHub or AI call is performed.
     *
     * @param array<string,mixed>|null $context
     * @return array<string,mixed>|null
     */
    public function build(
        ?array $context,
        ?ActionProposal $action,
    ): ?array {
        if (! is_array($context)) {
            return null;
        }

        $task = data_get($context, 'task');

        if (! $task instanceof Task) {
            return null;
        }

        $kind = $action?->kind ?: 'development_continue';
        $mode = $this->mode($kind);
        $target = $this->target($context, $task);
        $knownFacts = $this->knownFacts($context);
        $steps = $this->steps($kind, $context, $task);
        $validation = $this->validation($context, $action);
        $guardrails = $this->guardrails($kind);

        $brief = [
            'version' => 1,
            'kind' => $kind,
            'mode' => $mode,
            'eyebrow' => $this->eyebrow($mode),
            'title' => $action?->title
                ?: (string) data_get(
                    $context,
                    'handoff.title',
                    '次の開発Actionを進める',
                ),
            'objective' => $action?->intent
                ?: (string) data_get(
                    $context,
                    'handoff.intent',
                    '現在のTaskとGitHub Contextから次の作業を進めます。',
                ),
            'target' => $target,
            'known_facts' => $knownFacts,
            'steps' => $steps,
            'validation' => $validation,
            'guardrails' => $guardrails,
        ];

        $brief['copy_text'] = $this->copyText($brief);

        return $brief;
    }

    private function mode(string $kind): string
    {
        return match ($kind) {
            'development_implement',
            'development_fix_verification' => 'implementation',
            'development_fix_ci',
            'development_run_ci' => 'ci_triage',
            'development_review_fix',
            'development_obtain_review' => 'review',
            'development_merge',
            'development_restore_merge_path' => 'merge',
            'development_deploy',
            'development_fix_deploy' => 'deploy',
            'development_verify' => 'verification',
            'development_sync_spec' => 'spec_sync',
            'development_connect_evidence' => 'evidence',
            'development_release_ready' => 'release',
            default => 'continuation',
        };
    }

    private function eyebrow(string $mode): string
    {
        return match ($mode) {
            'implementation' => 'IMPLEMENTATION BRIEF',
            'ci_triage' => 'CI TRIAGE BRIEF',
            'review' => 'REVIEW BRIEF',
            'merge' => 'MERGE BRIEF',
            'deploy' => 'DEPLOY BRIEF',
            'verification' => 'VERIFICATION BRIEF',
            'spec_sync' => 'SPEC SYNC BRIEF',
            'evidence' => 'EVIDENCE BRIEF',
            'release' => 'RELEASE BRIEF',
            default => 'DEVELOPMENT BRIEF',
        };
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function target(array $context, Task $task): array
    {
        return [
            'task_id' => (int) $task->id,
            'task_title' => mb_substr((string) $task->title, 0, 500),
            'repository' => $this->text(
                data_get($context, 'repository'),
                255,
            ),
            'branch' => $this->text(
                data_get($context, 'branch.name'),
                255,
            ),
            'commit_sha' => $this->text(
                data_get($context, 'commit.sha'),
                64,
            ),
            'pull_request_number' => $this->positiveInt(
                data_get($context, 'pull_request.number'),
            ),
            'pull_request_state' => $this->text(
                data_get($context, 'pull_request.state'),
                50,
            ),
            'pull_request_url' => $this->githubUrl(
                data_get($context, 'pull_request.url'),
            ),
            'base_branch' => $this->text(
                data_get($context, 'pull_request.base_ref'),
                255,
            ),
            'issue_number' => $this->positiveInt(
                data_get($context, 'issue.number'),
            ),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    private function knownFacts(array $context): array
    {
        $facts = [];

        if ($repository = $this->text(data_get($context, 'repository'), 255)) {
            $facts[] = 'Repository: '.$repository;
        }

        if ($branch = $this->text(data_get($context, 'branch.name'), 255)) {
            $facts[] = 'Branch: '.$branch;
        }

        if ($sha = $this->text(data_get($context, 'commit.sha'), 64)) {
            $facts[] = 'Commit: '.mb_substr($sha, 0, 12)
                .((bool) data_get($context, 'commit.verified')
                    ? ' (verified)'
                    : '');
        }

        $prNumber = $this->positiveInt(
            data_get($context, 'pull_request.number'),
        );
        if ($prNumber !== null) {
            $state = $this->text(
                data_get($context, 'pull_request.state'),
                50,
            ) ?: 'unknown';
            $facts[] = 'Pull Request: #'.$prNumber.' / '.$state
                .((bool) data_get($context, 'pull_request.draft')
                    ? ' / draft'
                    : '');
        }

        $ci = $this->text(data_get($context, 'ci.state'), 50);
        if ($ci !== null) {
            $facts[] = 'CI: '.$ci;
        }

        $review = $this->text(data_get($context, 'review.state'), 80);
        if ($review !== null) {
            $reviewer = $this->text(
                data_get($context, 'review.reviewer'),
                255,
            );
            $facts[] = 'Review: '.$review
                .($reviewer ? ' / '.$reviewer : '');
        }

        $issue = $this->positiveInt(data_get($context, 'issue.number'));
        if ($issue !== null) {
            $state = $this->text(
                data_get($context, 'issue.state'),
                50,
            ) ?: 'unknown';
            $facts[] = 'Issue: #'.$issue.' / '.$state;
        }

        $deploy = $this->text(
            data_get($context, 'deployment.status'),
            50,
        );
        if ($deploy !== null) {
            $environment = $this->text(
                data_get($context, 'deployment.environment'),
                120,
            ) ?: 'environment';
            $facts[] = 'Deploy: '.$environment.' / '.$deploy
                .((bool) data_get($context, 'deployment.production')
                    ? ' / production'
                    : '');
        }

        if ($facts === []) {
            $facts[] = 'GitHubの実装Stateはまだ十分に観測できていません。';
        }

        return array_slice(array_values(array_unique($facts)), 0, 8);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    private function steps(
        string $kind,
        array $context,
        Task $task,
    ): array {
        $taskTitle = mb_substr((string) $task->title, 0, 180);
        $branch = $this->text(data_get($context, 'branch.name'), 255);
        $pr = $this->positiveInt(data_get($context, 'pull_request.number'));
        $ci = $this->text(data_get($context, 'ci.state'), 50);
        $review = $this->text(data_get($context, 'review.state'), 80);

        return match ($kind) {
            'development_implement',
            'development_fix_verification' => array_values(array_filter([
                'Task「'.$taskTitle.'」に必要な変更範囲を先に固定する。',
                $branch
                    ? '既存Branch「'.$branch.'」を作業対象として使い、無関係な変更を混ぜない。'
                    : 'Task専用Branchを用意し、mainへの直接変更を避ける。',
                '必要な実装と最小限の回帰確認を行う。',
                $pr
                    ? '既存PR #'.$pr.'へ変更を反映し、同じTaskの実装Evidenceを更新する。'
                    : 'CommitまたはPull Requestとして変更を観測できる状態にする。',
            ])),
            'development_fix_ci' => [
                '現在のPR / head SHAに対する失敗CIをGitHub側で確認し、失敗ジョブを特定する。',
                'Task「'.$taskTitle.'」の範囲に限定して原因を修正する。',
                '変更後のhead SHAでCIを再実行する。',
                '同じTaskにCI success Evidenceが戻ったことを確認する。',
            ],
            'development_run_ci' => [
                $pr
                    ? 'PR #'.$pr.'の現在head SHAを対象にCIを実行する。'
                    : '現在の実装Commit / Branchを対象にCIを実行する。',
                '失敗した場合は結果を実装Taskへ戻し、別Taskへ広げず原因を切り分ける。',
                'CI successを同じTaskのEvidenceとして再観測する。',
            ],
            'development_review_fix' => [
                $review === 'CHANGES_REQUESTED'
                    ? 'GitHub上の最新Review指摘を確認する。CanoviaはReview本文を保持しない。'
                    : 'GitHub上のReview状態と指摘内容を確認する。',
                '指摘をTask「'.$taskTitle.'」の変更範囲へ落とし込み、必要な修正だけ行う。',
                $pr
                    ? 'PR #'.$pr.'へ修正を反映して再Review可能な状態にする。'
                    : '修正をPull Requestへ反映して再Review可能な状態にする。',
                '更新後のReview stateを再観測する。',
            ],
            'development_obtain_review' => [
                $pr
                    ? 'PR #'.$pr.'がReview可能な状態か確認する。'
                    : '対象変更をReview可能なPull Requestにする。',
                $ci === 'success'
                    ? '既にCI成功済みなので、Review依頼へ進む。'
                    : 'CI状態が未確認ならReview前提としてテスト結果も確認する。',
                'Approvalまたは新しい修正依頼をEvidenceとして取り込む。',
            ],
            'development_merge',
            'development_restore_merge_path' => [
                $pr
                    ? 'PR #'.$pr.'のCI / Review / mergeabilityをGitHubで確認する。'
                    : '現在変更のmerge経路となるPull Requestを特定する。',
                '未解決のCI failure / changes requestedがある場合はmergeより先に解消する。',
                'mainへの直接pushではなくPull Request経由でmerge経路を維持する。',
                'merge後の状態を同じTaskのEvidenceとして再観測する。',
            ],
            'development_deploy',
            'development_fix_deploy' => [
                'merge済みRelease候補のSHAを確認する。',
                'Production Deploy対象がそのSHAと一致していることを確認する。',
                $kind === 'development_fix_deploy'
                    ? '失敗Deployの原因をTask範囲で修正し、Productionへ再Deployする。'
                    : '既存Release手順でProductionへDeployする。',
                'Production deployment successをEvidenceとして確認する。',
            ],
            'development_verify' => [
                'Productionへ反映されたRelease候補を実機・本番環境で確認する。',
                'Task「'.$taskTitle.'」の受け入れ条件に沿って主要動作を確認する。',
                '問題があればVerificationをpassedにせず、修正Taskへ戻す。',
                '問題がなければVerification Gateへ明示結果を残す。',
            ],
            'development_sync_spec' => [
                '現在の実装結果と既存仕様・引き継ぎ内容の差分を確認する。',
                'ユーザー挙動・権限境界・データ契約など、実装で変わった事実だけを同期する。',
                '不要な実装詳細や一時的なデバッグ情報は仕様へ固定しない。',
                '同期済み、または同期不要を明示してSpec Sync Gateを確定する。',
            ],
            'development_connect_evidence' => [
                'Task「'.$taskTitle.'」に対応するRepository / PR / Issue / Branchを特定する。',
                'GitHub Activityの候補を確認し、Taskとの関連付けを人間が確定する。',
                '関連付け後にauthoritative GitHub Evidenceを再取得する。',
                'Taskに紐づくDevelopment Evidenceが1件以上ある状態を確認する。',
            ],
            'development_release_ready' => [
                'Implementation / CI / Review / Merge / Deploy / Verification / Spec Syncの7 Gateを確認する。',
                '各Gateが同じRelease候補を指していることを確認する。',
                '新しいCommitやDeployが入っている場合は古いVerification / Spec Syncを再利用しない。',
                '全Gateが維持されていれば次の変更へ進む。',
            ],
            default => [
                'Task「'.$taskTitle.'」の現在Stateと不足Evidenceを確認する。',
                '現在Actionの完了条件に直接つながる最小の変更・確認を行う。',
                '完了後にGitHub / Gate Evidenceを更新し、次のActionを再判定する。',
            ],
        };
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    private function validation(
        array $context,
        ?ActionProposal $action,
    ): array {
        $signals = (array) data_get($context, 'handoff.done_when', []);

        if ($signals === [] && $action instanceof ActionProposal) {
            $signals = $action->successSignals;
        }

        $signals = array_values(array_filter(array_map(
            fn ($value) => $this->text($value, 500),
            $signals,
        )));

        if ($signals === []) {
            $signals[] = '対象TaskのEvidenceを更新し、次のDevelopment Actionが再判定される。';
        }

        return array_slice(array_values(array_unique($signals)), 0, 6);
    }

    /**
     * @return array<int,string>
     */
    private function guardrails(string $kind): array
    {
        $items = [
            '確認できないGitHub事実は推測せず、未知のまま扱う。',
            'このBriefだけでTaskのprogress / status / remaining timeを自動変更しない。',
        ];

        if (! in_array($kind, [
            'development_verify',
            'development_sync_spec',
            'development_connect_evidence',
        ], true)) {
            $items[] = 'GitHubへの変更は既存の権限境界とユーザー確認を通す。';
        }

        $items[] = 'source code / diff / PR本文 / Issue本文をBriefへ保存しない。';

        return $items;
    }

    /**
     * @param array<string,mixed> $brief
     */
    private function copyText(array $brief): string
    {
        $target = (array) ($brief['target'] ?? []);
        $lines = [
            '# Canovia '.($brief['eyebrow'] ?? 'DEVELOPMENT BRIEF'),
            '',
            'Action: '.($brief['title'] ?? ''),
            'Objective: '.($brief['objective'] ?? ''),
            '',
            '## Target',
            '- Task: '.($target['task_title'] ?? 'unknown'),
            '- Repository: '.($target['repository'] ?? 'unknown'),
            '- Branch: '.($target['branch'] ?? 'unknown'),
            '- Pull Request: '.(
                $target['pull_request_number']
                    ? '#'.$target['pull_request_number']
                    : 'unknown'
            ),
        ];

        if (! empty($target['base_branch'])) {
            $lines[] = '- Base: '.$target['base_branch'];
        }

        $lines[] = '';
        $lines[] = '## Known state';
        foreach ((array) ($brief['known_facts'] ?? []) as $fact) {
            $lines[] = '- '.$fact;
        }

        $lines[] = '';
        $lines[] = '## Steps';
        foreach ((array) ($brief['steps'] ?? []) as $index => $step) {
            $lines[] = ($index + 1).'. '.$step;
        }

        $lines[] = '';
        $lines[] = '## Done when';
        foreach ((array) ($brief['validation'] ?? []) as $signal) {
            $lines[] = '- '.$signal;
        }

        $lines[] = '';
        $lines[] = '## Guardrails';
        foreach ((array) ($brief['guardrails'] ?? []) as $guardrail) {
            $lines[] = '- '.$guardrail;
        }

        return implode("\n", $lines);
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $limit) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $number = is_numeric($value) ? (int) $value : 0;

        return $number > 0 ? $number : null;
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
