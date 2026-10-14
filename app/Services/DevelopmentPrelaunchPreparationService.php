<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

final class DevelopmentPrelaunchPreparationService
{
    /**
     * A single, optional preparation suggestion. This is NOT a release gate,
     * proof of missing infrastructure, or a second Current Action engine.
     *
     * @return array{key:string,title:string,detail:string,basis:string,status:string}|null
     */
    public function suggest(Plan $plan, bool $explicitlyRequested = false, ?array $profile = null): ?array
    {
        // Only the authorized, selected Plan's already loaded Task titles are
        // considered. A page view must not fetch a provider or write state.
        if (! $plan->relationLoaded('tasks')) {
            return null;
        }

        $launchTask = $plan->tasks->first(
            fn (Task $task) =>
                ! in_array($task->status, ['done', 'cancelled'], true)
                && $this->mentionsLaunch((string) $task->title)
        );

        $stage = is_array($profile) ? ($profile['stage'] ?? 'unknown') : 'unknown';
        $launchContext = $launchTask instanceof Task
            || $this->mentionsLaunch((string) $plan->title);
        $publicStage = in_array($stage, ['public_launch', 'live'], true);

        // The owner explicitly said this is a prototype. Do not automatically
        // nag about production even when a prospective launch Task exists.
        if (! $explicitlyRequested && ($stage === 'prototype' || (! $launchContext && ! $publicStage))) {
            return null;
        }

        // This first slice has no authenticated environment/backup attestation.
        // Never treat the absence of that evidence as a verified failed gate.
        return [
            'key' => 'identify_production_and_data',
            'title' => '公開先とデータの保存・復旧方法を確認する',
            'detail' => '公開先、永続データの有無、バックアップと復元テストの状況を整理しましょう。DBを使わない場合は復元テストを必須にしません。',
            'basis' => $explicitlyRequested
                ? '公開準備の確認を選択'
                : ($publicStage
                    ? 'Plan所有者が登録した公開段階（自己申告）'
                    : 'Planまたは未完了Taskに公開・リリースに関する記述'),
            'status' => 'unknown',
        ];
    }

    private function mentionsLaunch(string $title): bool
    {
        // Narrow lifecycle cue, not an automatic classification of an app as
        // public. Do not equate Canovia Plan::is_public with product launch.
        return preg_match(
            '/(?:初回公開|一般公開|本番公開|アプリ公開|サービス公開|公開(?:準備|予定|する|前|まで|へ)|'
            .'リリース(?:準備|予定)|本番(?:デプロイ|リリース|運用)|'
            .'ローンチ|go[\s-]?live|production[\s-]?(?:deploy|release)|launch)/iu',
            $title,
        ) === 1;
    }
}
