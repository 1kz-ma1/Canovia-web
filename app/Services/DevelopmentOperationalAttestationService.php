<?php

namespace App\Services;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use Illuminate\Support\Carbon;

final class DevelopmentOperationalAttestationService
{
    /**
     * A bound, owner-reported confirmation, NOT an independent verification.
     * Every read is a pure projection; provider connectors are never queried.
     *
     * @return array<string,array<string,mixed>>
     */
    public function project(
        Plan $plan,
        ?array $profile,
        ?DevelopmentOperationalTarget $target,
    ): array {
        if (! $target || (int) $target->plan_id !== (int) $plan->getKey() || (int) $plan->getKey() <= 0) {
            return [];
        }

        $environment = $target->environment;
        $provider = $target->provider;

        // Both values originate from the Plan owner's explicit selection.
        // A provider name is never evidence of a verified connection.
        if (! in_array($environment, DevelopmentOperationalTarget::ENVIRONMENTS, true)
            || ! in_array($provider, DevelopmentOperationalTarget::PROVIDERS, true)) {
            return [];
        }

        $projected = [
            'environment' => [
                'status' => 'declared',
                'source' => 'Plan所有者の環境指定（外部サービス未照合）',
                'detail' => '対象環境は登録されていますが、接続先・権限・実際の稼働SHAはまだ自動検証していません。',
            ],
        ];

        $dataStore = is_array($profile) ? ($profile['data_store'] ?? 'unknown') : 'unknown';
        if (! in_array($dataStore, ['server_db', 'local_device', 'cloud_files', 'mixed'], true)) {
            return $projected;
        }

        foreach (['backup', 'restore'] as $kind) {
            $at = $target->{$kind.'_confirmed_at'};
            if (! $at) {
                continue;
            }

            $sameRevision = (int) $target->{$kind.'_revision'} === (int) $target->revision;
            $sameStore = (string) $target->{$kind.'_store'} === $dataStore;
            $ageDays = $kind === 'backup' ? 7 : 90;
            $tooOld = $at->lt(Carbon::now()->subDays($ageDays));
            $stale = ! $sameRevision || ! $sameStore || $tooOld;

            $projected[$kind] = [
                'status' => $stale ? 'stale' : 'operator_confirmed',
                'source' => 'Plan所有者の実施申告（独立検証なし）',
                'detail' => $stale
                    ? '以前の実施申告がありますが、対象環境・保存方式の変更または実施日からの経過により再確認が必要です。'
                    : ($kind === 'backup'
                        ? 'この環境に対するバックアップ取得を所有者が申告しました。ファイルの完全性・外部保管・復元可能性は検証していません。'
                        : 'この環境に対する隔離復元テストを所有者が申告しました。Canoviaが外部環境や復元結果を検証したものではありません。'),
                'observedAt' => $at->format('Y-m-d H:i'),
            ];
        }

        return $projected;
    }
}
