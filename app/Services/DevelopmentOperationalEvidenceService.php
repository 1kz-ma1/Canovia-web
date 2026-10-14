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
        ?array $selfReportedProfile = null,
        ?array $operatorAttestations = null,
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

        $checks = $this->withSelfReportedContext($checks, $selfReportedProfile);
        $checks = $this->withOperatorAttestations($checks, $operatorAttestations);

        usort($checks, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [
            'primary' => $checks[0],
            'checks' => array_map(static function (array $item): array {
                unset($item['priority']);
                return $item;
            }, $checks),
        ];
    }

    /**
     * Self-report can change relevance, NEVER prove a backup or live deploy.
     * Even "not applicable" is explicitly conditional on owner's declaration.
     *
     * @param array<int,array<string,mixed>> $checks
     * @param array<string,mixed>|null $profile
     * @return array<int,array<string,mixed>>
     */
    private function withSelfReportedContext(array $checks, ?array $profile): array
    {
        if (! is_array($profile)) {
            return $checks;
        }

        $allowedStage = ['unknown', 'prototype', 'preview', 'public_launch', 'live'];
        $allowedRuntime = ['unknown', 'web', 'ios', 'android', 'hybrid', 'backend', 'other'];
        $allowedStore = ['unknown', 'none', 'local_device', 'server_db', 'cloud_files', 'mixed'];
        $stage = in_array($profile['stage'] ?? null, $allowedStage, true)
            ? $profile['stage']
            : 'unknown';
        $runtime = in_array($profile['runtime'] ?? null, $allowedRuntime, true)
            ? $profile['runtime']
            : 'unknown';
        $store = in_array($profile['data_store'] ?? null, $allowedStore, true)
            ? $profile['data_store']
            : 'unknown';

        foreach ($checks as &$check) {
            if ($check['key'] === 'environment' && ($stage !== 'unknown' || $runtime !== 'unknown')) {
                $check['detail'] = '公開段階や実行環境はPlan所有者から申告されていますが、ホスト・配信経路・稼働SHAは未照合です。';
                $check['source'] = 'Plan所有者の入力（本番環境の実測なし）';
            }
            if ($check['key'] === 'data' && $store !== 'unknown') {
                $check['status'] = 'declared';
                $check['priority'] = 75;
                $check['source'] = 'Plan所有者の自己申告（外部検証なし）';
                $check['detail'] = match ($store) {
                    'none' => '現在の設計では永続データを保存しないと申告されています。実際の保存処理やログは未検証です。',
                    'local_device' => '端末内にデータを保存すると申告されています。端末交換・故障時の退避と復元を検討してください。',
                    'server_db' => 'サーバーDBに保存すると申告されています。対象DBと復元可能なバックアップを別途確認してください。',
                    'cloud_files' => 'クラウド上のファイル保存を申告されています。削除・上書き時の世代管理と復旧を確認してください。',
                    default => '複数またはその他の保存先を申告されています。各保存先の保全と復旧手順を分けて確認してください。',
                };
            } elseif ($check['key'] === 'data' && $store === 'unknown' && in_array($stage, ['public_launch', 'live'], true)) {
                $check['priority'] = 8;
            }

            if (in_array($check['key'], ['backup', 'restore'], true)) {
                if ($store === 'none') {
                    $check['status'] = 'not_applicable';
                    $check['priority'] = $check['key'] === 'backup' ? 90 : 91;
                    $check['detail'] = '永続データなしという自己申告に基づき、データ復旧の確認を一旦対象外にしています。保存処理が追加されたら再確認してください。';
                    $check['source'] = 'Plan所有者の自己申告に基づく暫定判断';
                } elseif ($store === 'local_device') {
                    $check['detail'] = $check['key'] === 'backup'
                        ? '端末内データの退避・同期・機種変更時の保護方法を確認してください。MySQLバックアップは前提にしません。'
                        : '端末内データが失われた場合の復元・再取得方法を確認してください。サーバーDB復元は前提にしません。';
                } elseif ($store === 'cloud_files') {
                    $check['detail'] = $check['key'] === 'backup'
                        ? 'クラウドファイルのバージョニング・削除保護・独立コピーの有無を確認してください。'
                        : '誤削除・上書きから実際にファイルを復元できるか、保護された試験先で確認してください。';
                }
            }

            if ($check['key'] === 'deploy' && in_array($runtime, ['ios', 'android', 'hybrid'], true)) {
                $check['detail'] .= ' モバイル向けには配信審査・署名・実機での確認も別途必要です。';
            }
            if ($check['key'] === 'deploy' && $stage === 'prototype') {
                $check['detail'] .= ' 現在は試作段階との申告なので、公開デプロイを今すぐ行う必要はありません。';
            }
        }
        unset($check);

        return $checks;
    }

    /**
     * Accept only statuses generated by the scoped server-side attestation
     * projector. This is NOT provider verification or V53.8 gate completion.
     */
    private function withOperatorAttestations(array $checks, ?array $attestations): array
    {
        if (! is_array($attestations)) {
            return $checks;
        }

        foreach ($checks as &$check) {
            $key = $check['key'];
            $proof = $attestations[$key] ?? null;
            if (! is_array($proof)) {
                continue;
            }

            $allowed = $key === 'environment'
                ? ['declared']
                : (in_array($key, ['backup', 'restore'], true)
                    ? ['operator_confirmed', 'stale']
                    : []);
            $status = $proof['status'] ?? null;
            if (! in_array($status, $allowed, true)
                || ($check['status'] ?? null) === 'not_applicable') {
                continue;
            }

            $check['status'] = $status;
            $check['detail'] = (string) ($proof['detail'] ?? $check['detail']);
            $check['source'] = (string) ($proof['source'] ?? $check['source']);
            $check['observedAt'] = $proof['observedAt'] ?? null;
            $check['priority'] = match ($status) {
                'stale' => $key === 'backup' ? 12 : 13,
                'operator_confirmed' => $key === 'backup' ? 85 : 86,
                default => 75,
            };
        }
        unset($check);

        return $checks;
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
