<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use DateTimeInterface;

final class DevelopmentOperationalEvidenceService
{
    /**
     * Project bounded operational questions without provider calls or writes.
     * GitHub observations are NOT independent live Render/DB attestations.
     *
     * @return array{primary:array<string,mixed>,checks:array<int,array<string,mixed>>}
     */
    public function assess(
        Plan $plan,
        ?array $executionContext,
        bool $currentGithubEvidenceAllowed,
    ): array {
        $scope = $currentGithubEvidenceAllowed
            && ($executionContext['task'] ?? null) instanceof Task
            && (int) $executionContext['task']->plan_id === (int) $plan->getKey()
            && (int) $plan->getKey() > 0
            && ($executionContext['has_github_evidence'] ?? false) === true;

        $pr = $scope && is_array($executionContext['pull_request'] ?? null)
            ? $executionContext['pull_request']
            : [];
        $ci = $scope && is_array($executionContext['ci'] ?? null)
            ? $executionContext['ci']
            : [];
        $deployment = $scope && is_array($executionContext['deployment'] ?? null)
            ? $executionContext['deployment']
            : [];

        // CI and deployments must not inherit an old/unrelated release's SHA.
        $candidate = $this->sha($pr['head_sha'] ?? null);
        $ciSha = $this->sha($ci['head_sha'] ?? null);
        $deploySha = $this->sha($deployment['sha'] ?? null);

        $checks = [
            $this->check(
                'environment', 10, '公開先とデータの保存・復旧方法を確認する', 'unknown',
                '公開対象の環境・サービスと実際のデプロイブランチは、Canovia上ではまだ照合していません。',
                '独立した本番環境の確認が必要'
            ),
            $this->check(
                'data', 15, '保存データの有無を整理する', 'unknown',
                'DBやユーザーデータがあるか未確認です。データがない場合、DB復元テストは不要です。',
                '利用するデータストアは未確認'
            ),
            $this->check(
                'backup', 20, 'バックアップの取得状況を確認する', 'unknown',
                '永続データがある場合に、取得対象・時点・保管先を確認します。ファイルの存在だけでは復元可能とは判定しません。',
                'バックアップの直接証拠なし'
            ),
            $this->check(
                'restore', 25, '隔離環境で復元できるか確認する', 'unknown',
                '永続データがある場合に、独立環境への復元と読み取りの証拠を確認します。バックアップ作成と復元成功は別です。',
                '復元実行の直接証拠なし'
            ),
        ];

        $ciState = strtolower(trim((string) ($ci['state'] ?? 'unknown')));
        if ($candidate !== null && $ciSha !== null && $candidate !== $ciSha) {
            $checks[] = $this->check('ci', 5, '現在のPRに対するCIを再確認する', 'stale',
                'PRのhead SHAとCI観測SHAが異なります。以前の成功結果を現在の変更の証拠に使わないでください。',
                'Taskに関連するGitHub観測', $candidate, $this->time($ci['occurred_at'] ?? null));
        } elseif ($candidate !== null && $ciSha === $candidate && $ciState === 'failure') {
            $checks[] = $this->check('ci', 3, '失敗したCIを修正する', 'attention',
                '現在のPR head SHAに関連したCIで失敗が観測されています。リリース準備の前に原因を確認してください。',
                'Taskに関連するGitHub観測', $candidate, $this->time($ci['occurred_at'] ?? null));
        } elseif ($candidate !== null && $ciSha === $candidate && $ciState === 'success') {
            $checks[] = $this->check('ci', 60, 'CI観測を確認する', 'observed',
                '現在のPR head SHAに対するCI successを観測しました。ただし必須チェック全体や本番動作は別途確認が必要です。',
                'Taskに関連するGitHub観測', $candidate, $this->time($ci['occurred_at'] ?? null));
        } else {
            $checks[] = $this->check('ci', 40, '対象PRのCI証拠を確認する', 'unknown',
                '現在のPR head SHAと一致するCIの結果を、この画面では確認できません。',
                $scope ? 'Taskに関連するGitHub観測（照合未完了）' : 'GitHub証拠を使用できない');
        }

        $deploymentStatus = strtolower(trim((string) ($deployment['status'] ?? 'unknown')));
        $prod = ($deployment['production'] ?? false) === true;
        if ($candidate !== null && $deploySha !== null && $candidate !== $deploySha && $prod) {
            $checks[] = $this->check('deploy', 35, '本番デプロイの対象SHAを照合する', 'review',
                '観測されたProductionデプロイと現在のPR head SHAが異なります。マージコミットとの差か、旧リリースかを確認してください。',
                'Taskに関連するGitHub観測', $deploySha, $this->time($deployment['occurred_at'] ?? null));
        } elseif ($candidate !== null && $deploySha === $candidate && $prod && in_array($deploymentStatus, ['success', 'successful'], true)) {
            $checks[] = $this->check('deploy', 70, '本番デプロイの観測を確認する', 'observed',
                'GitHub上でProduction成功が観測されています。実際のホスティング環境・稼働SHA・動作確認は独立して検証してください。',
                'Taskに関連するGitHub観測', $deploySha, $this->time($deployment['occurred_at'] ?? null));
        } else {
            $checks[] = $this->check('deploy', 45, '本番デプロイと稼働SHAを確認する', 'unknown',
                '現在のPRに対応するProduction成功と実サービスの稼働SHAは確認できていません。Staging成功は本番証拠になりません。',
                $scope ? 'Taskに関連するGitHub観測（照合未完了）' : 'GitHub証拠を使用できない');
        }

        usort($checks, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [
            'primary' => $checks[0],
            'checks' => array_map(static function (array $item): array {
                unset($item['priority']);
                return $item;
            }, $checks),
        ];
    }

    private function check(
        string $key, int $priority, string $title, string $status,
        string $detail, string $source, ?string $sha = null,
        ?string $observedAt = null,
    ): array {
        return compact('key', 'priority', 'title', 'status', 'detail', 'source', 'sha', 'observedAt');
    }

    private function sha(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}\z/i', $value)
            ? strtolower($value)
            : null;
    }

    private function time(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d H:i')
            : null;
    }
}
