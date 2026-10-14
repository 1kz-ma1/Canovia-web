<?php

namespace App\Services;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;

/**
 * Owner-only, read-only summary of human statements.
 *
 * No status in this projection may satisfy release gates, authorize a
 * deployment, or be transformed into provider-authenticated evidence.
 */
final class DevelopmentOperationalOperatorReadinessService
{
    public function __construct(
        private readonly DevelopmentOperationalAttestationService $attestations,
    ) {}

    /**
     * @param array<string,mixed>|null $profile V3 owner's storage selection.
     * @return array{checks:list<array{key:string,label:string,status:string,source:string,detail:string,recorded_at:?string}>,next:array{key:string,title:string,detail:string},basis:string}|null
     */
    public function summarize(
        Plan $plan,
        ?array $profile,
        ?DevelopmentOperationalTarget $target,
    ): ?array {
        // The handoff validator and V5.3 projector must agree on the exact
        // Plan/owner/target binding. Do not summarize invalid identifiers.
        $identity = $this->attestations->projectServiceIdentity($plan, $target);
        if ($identity === null || $target === null) {
            return null;
        }

        $store = is_array($profile) ? ($profile['data_store'] ?? 'unknown') : 'unknown';
        $persistentStores = ['server_db', 'local_device', 'cloud_files', 'mixed'];
        $isPersistent = in_array($store, $persistentStores, true);
        $isNone = $store === 'none';

        $v4 = $this->attestations->project($plan, $profile, $target);
        $checks = [
            $this->row(
                'service',
                'サービス・環境の手動照合',
                $identity,
                'Render画面で対象のサービスIDと環境を照合し、手動確認を記録してください。',
            ),
        ];

        foreach (['backup' => 'バックアップ取得', 'restore' => '隔離復元テスト'] as $key => $label) {
            if ($isNone) {
                $checks[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => 'not_applicable',
                    'source' => 'Plan所有者の保存方式の申告',
                    'detail' => '永続データなしという申告に基づき、復旧記録は要求していません。外部検証ではありません。',
                    'recorded_at' => null,
                ];
            } elseif (! $isPersistent) {
                $checks[] = [
                    'key' => $key,
                    'label' => $label,
                    'status' => 'unknown',
                    'source' => '保存方式未分類',
                    'detail' => '先にデータ保存方式を登録すると、必要な復旧確認を判断できます。',
                    'recorded_at' => null,
                ];
            } else {
                $checks[] = $this->row(
                    $key,
                    $label,
                    $v4[$key] ?? null,
                    $key === 'backup'
                        ? '実際の保存先でバックアップ取得を確認し、所有者の実施申告を記録してください。'
                        : '本番から隔離された環境で復元と読み取りを確認し、所有者の実施申告を記録してください。',
                );
            }
        }

        if (! $isNone && ! $isPersistent) {
            $next = [
                'key' => 'storage',
                'title' => 'まず保存方式を登録',
                'detail' => '「開発環境と保存方式」で永続データの有無と保存先を選択してください。',
            ];
        } else {
            $next = null;
            foreach ($checks as $row) {
                if (! in_array($row['status'], ['unknown', 'stale'], true)) {
                    continue;
                }

                $next = match ($row['key']) {
                    'service' => [
                        'key' => 'service',
                        'title' => '次にサービス・環境を再照合',
                        'detail' => '下のRender手動確認ガイドで登録サービスIDと環境を照合し、明示的に記録してください。',
                    ],
                    'backup' => [
                        'key' => 'backup',
                        'title' => '次にバックアップを確認',
                        'detail' => 'データの保存先で実際のバックアップ取得を確認してから、下の実施記録を更新してください。',
                    ],
                    default => [
                        'key' => 'restore',
                        'title' => '次に隔離復元テストを確認',
                        'detail' => '本番データを変更せず、隔離環境で復元・読み取りを確認してから下の実施記録を更新してください。',
                    ],
                };

                break;
            }

            $next ??= [
                'key' => 'release_review',
                'title' => '次に独立したリリース検証へ',
                'detail' => '所有者の申告上は再確認項目がありません。ただし実稼働SHA・CI・デプロイ・復元の独立検証と既存の公開品質ゲートは別途必要です。',
            ];
        }

        return [
            'checks' => $checks,
            'next' => $next,
            'basis' => '所有者の自己申告のみ。外部APIによる照合なし・公開可否の判定なし。',
        ];
    }

    /**
     * @param array<string,mixed>|null $claim
     * @return array{key:string,label:string,status:string,source:string,detail:string,recorded_at:?string}
     */
    private function row(string $key, string $label, ?array $claim, string $fallback): array
    {
        $status = $claim['status'] ?? 'unknown';
        if (! in_array($status, ['unknown', 'stale', 'operator_confirmed'], true)) {
            $status = 'unknown';
        }

        $timestamp = $claim['checkedAt'] ?? $claim['observedAt'] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'source' => is_string($claim['source'] ?? null) ? $claim['source'] : '所有者の実施申告（独立検証なし）',
            'detail' => $status === 'unknown'
                ? $fallback
                : (is_string($claim['detail'] ?? null) ? $claim['detail'] : $fallback),
            'recorded_at' => is_string($timestamp) && $status !== 'unknown' ? $timestamp : null,
        ];
    }
}
