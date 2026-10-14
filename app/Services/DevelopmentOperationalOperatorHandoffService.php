<?php

namespace App\Services;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;

/**
 * A scoped, entirely local Render dashboard handoff.
 *
 * Instructions are not an observation, service proof, or external identity.
 * Never interpret operator navigation as provider authorization.
 */
final class DevelopmentOperationalOperatorHandoffService
{
    /**
     * @param array<string,mixed>|null $profile A V3 owner-supplied summary.
     * @return array{provider:string,resource_ref:string,environment:string,steps:list<array{key:string,title:string,detail:string}>}|null
     */
    public function build(
        Plan $plan,
        ?DevelopmentOperationalTarget $target,
        ?array $profile,
    ): ?array {
        if (! $target
            || (int) $plan->getKey() <= 0
            || (int) $plan->user_id <= 0
            || (int) $target->plan_id !== (int) $plan->getKey()
            || $target->provider !== 'render'
            || ! in_array($target->environment, DevelopmentOperationalTarget::ENVIRONMENTS, true)
            || (int) $target->revision < 1
            || ! is_string($target->resource_ref)
            || ! preg_match('/\Asrv-[a-zA-Z0-9_-]{4,76}\z/', $target->resource_ref)
        ) {
            return null;
        }

        $steps = [
            [
                'key' => 'service_identity',
                'title' => 'Workspaceとサービスを照合',
                'detail' => 'Renderに自分でログインし、選択中のWorkspaceとサービスIDが登録対象に一致するか確認してください。名前だけでは同一サービスと判断しません。',
            ],
            [
                'key' => 'environment',
                'title' => 'Staging / Productionの実体を確認',
                'detail' => '表示中の環境区分は自己申告です。Renderのプロジェクト環境、連携ブランチ、接続先を確認し、検証環境と本番が混在していないか判断してください。設定変更は不要です。',
            ],
            [
                'key' => 'deployment',
                'title' => 'デプロイ結果と実際の動作を分けて確認',
                'detail' => '対象サービスの直近のデプロイとソースコミットを確認してください。デプロイ成功と実稼働SHA・ユーザー画面の動作は別なので、必要なWeb・実機テストも別途確認してください。',
            ],
        ];

        $store = is_array($profile) ? ($profile['data_store'] ?? 'unknown') : 'unknown';

        if (in_array($store, ['server_db', 'mixed'], true)) {
            $steps[] = [
                'key' => 'backup',
                'title' => '永続データのバックアップを確認',
                'detail' => '実際のデータ保存先ごとにバックアップ対象・取得時点・保管先を確認してください。Renderのサービスが存在するだけでは、外部DBのバックアップを証明できません。',
            ];
            $steps[] = [
                'key' => 'restore',
                'title' => '隔離環境の復元結果を別に確認',
                'detail' => 'バックアップ取得と復元成功は別です。復元テストは本番と隔離された環境で、対象データを読み取れたことを個別に確認してください。本番DBへ変更を加えないでください。',
            ];
        } elseif ($store === 'local_device') {
            $steps[] = [
                'key' => 'device_recovery',
                'title' => '端末データの移行・復元を確認',
                'detail' => '端末交換・再インストール・故障時の復旧方法を確認してください。サーバーDBの復元作業は前提にしません。',
            ];
        } elseif ($store === 'cloud_files') {
            $steps[] = [
                'key' => 'file_recovery',
                'title' => 'クラウドファイルの世代と復元を確認',
                'detail' => 'ファイルの削除・上書きに備えたバージョン管理と隔離復元の可否を確認してください。Renderサービスの状態だけではファイル復元を保証しません。',
            ];
        } elseif ($store !== 'none') {
            $steps[] = [
                'key' => 'classify_store',
                'title' => 'データ保存方式を先に分類',
                'detail' => '保存先がまだ不明です。端末・DB・クラウドファイルの利用を整理してから、適切な復旧確認を選んでください。',
            ];
        }

        return [
            'provider' => 'render',
            'resource_ref' => $target->resource_ref,
            'environment' => $target->environment,
            'steps' => $steps,
        ];
    }
}
