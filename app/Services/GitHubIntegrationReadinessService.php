<?php

namespace App\Services;

use App\Data\FeatureAccessDecision;
use App\Enums\FeatureKey;
use App\Models\User;

final class GitHubIntegrationReadinessService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
        private readonly GitHubRepositoryWriter $writer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forActor(?User $user): array
    {
        $evidence = $this->featureAccess->resolveAccess(
            $user,
            FeatureKey::DeveloperGithubEvidence,
        );
        $write = $this->featureAccess->resolveAccess(
            $user,
            FeatureKey::DeveloperGithubWrite,
        );

        $appIdConfigured = trim(
            (string) config('services.github.app_id', ''),
        ) !== '';
        $privateKeyConfigured = $this->privateKeyConfigured();
        $installUrlConfigured = $this->writer->installUrl() !== null;
        $webhookConfigured = trim(
            (string) config(
                'services.github.app_webhook_secret',
                '',
            ),
        ) !== '';
        $queueDriver = trim(
            (string) config('queue.default', 'sync'),
        ) ?: 'sync';
        $asyncQueueConfigured = ! in_array(
            $queueDriver,
            ['sync', 'null'],
            true,
        );

        return [
            'evidence' => $this->decision(
                $evidence,
                'Repository read / Return Evidence',
            ),
            'write' => $this->decision(
                $write,
                'GitHub review write',
            ),
            'runtime' => [
                'app_id_configured' => $appIdConfigured,
                'private_key_configured' => $privateKeyConfigured,
                'app_configured' =>
                    $appIdConfigured && $privateKeyConfigured,
                'install_url_configured' => $installUrlConfigured,
                'webhook_configured' => $webhookConfigured,
                'queue_driver' => $queueDriver,
                'async_queue_configured' =>
                    $asyncQueueConfigured,
                'interactive_write_configured' =>
                    $appIdConfigured
                    && $privateKeyConfigured
                    && $installUrlConfigured,
                'automatic_return_sync_configured' =>
                    $appIdConfigured
                    && $privateKeyConfigured
                    && $webhookConfigured
                    && $asyncQueueConfigured,
            ],
        ];
    }

    public function deniedMessage(
        FeatureAccessDecision $decision,
        string $capabilityLabel,
    ): string {
        if ($decision->allowed) {
            return '';
        }

        return match ($decision->reason) {
            'admin_preview_free' =>
                "現在はFreeプレビュー中のため、{$capabilityLabel}は無効です。実接続を確認するときは管理者メニューでSuper Admin表示へ戻してください。",
            'admin_preview_premium' =>
                "現在はPremiumプレビュー中のため、{$capabilityLabel}は無効です。実接続を確認するときは管理者メニューでSuper Admin表示へ戻してください。",
            default =>
                "現在の利用権では{$capabilityLabel}を利用できません。閲覧できるGitHub情報はそのまま利用できます。",
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function connectionStatus(
        array $readiness,
        array $appConnection,
    ): array {
        $writeAllowed = (bool) data_get(
            $readiness,
            'write.allowed',
            false,
        );
        $runtime = (array) data_get(
            $readiness,
            'runtime',
            [],
        );
        $connection = (string) (
            $appConnection['status']
            ?? 'not_connected'
        );

        if (! $writeAllowed) {
            return [
                'state' => 'capability_blocked',
                'owner' => 'PLAN / ENTITLEMENT',
                'label' => 'Write利用不可',
                'detail' => (string) data_get(
                    $readiness,
                    'write.message',
                    'GitHub Writeを利用できません。',
                ),
            ];
        }

        if (! (bool) ($runtime['app_configured'] ?? false)) {
            return [
                'state' => 'operator_setup',
                'owner' => 'CANOVIA OPERATOR',
                'label' => 'GitHub App credential未設定',
                'detail' => 'App ID / Private KeyをCanoviaのserver-side環境へ設定する必要があります。',
            ];
        }

        if (! (bool) (
            $runtime['install_url_configured']
            ?? false
        )) {
            return [
                'state' => 'operator_setup',
                'owner' => 'CANOVIA OPERATOR',
                'label' => 'Install URL未設定',
                'detail' => 'GitHub AppのInstall URLをCanoviaへ設定する必要があります。',
            ];
        }

        return match ($connection) {
            'connected' => [
                'state' => 'ready',
                'owner' => 'READY',
                'label' => 'Review write準備完了',
                'detail' => '対象RepositoryへのGitHub App接続とwrite権限が確認されています。',
            ],
            'connecting', 'pending' => [
                'state' => 'github_pending',
                'owner' => 'GITHUB / REPOSITORY ADMIN',
                'label' => 'GitHub承認待ち',
                'detail' => 'Organization Owner承認またはGitHub側のInstallation完了を待っています。',
            ],
            'permission_update_required' => [
                'state' => 'github_permission',
                'owner' => 'GITHUB APP',
                'label' => 'write権限承認待ち',
                'detail' => 'Contents / Pull Requestsのwrite権限をGitHub側で承認する必要があります。',
            ],
            'revoked', 'verification_failed' => [
                'state' => 'github_reconnect',
                'owner' => 'GITHUB / REPOSITORY ADMIN',
                'label' => '接続再確認が必要',
                'detail' => '対象RepositoryのInstallationを現在確認できません。GitHub App接続をやり直してください。',
            ],
            default => [
                'state' => 'repository_install',
                'owner' => 'GITHUB / REPOSITORY ADMIN',
                'label' => 'Repository未接続',
                'detail' => '対象RepositoryへCanovia GitHub Appをinstallしてください。',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function decision(
        FeatureAccessDecision $decision,
        string $label,
    ): array {
        return [
            'allowed' => $decision->allowed,
            'reason' => $decision->reason,
            'source' => $decision->source?->value,
            'message' => $decision->allowed
                ? "{$label}を利用できます。"
                : $this->deniedMessage(
                    $decision,
                    $label,
                ),
        ];
    }

    private function privateKeyConfigured(): bool
    {
        return trim(
            (string) config(
                'services.github.app_private_key',
                '',
            ),
        ) !== ''
            || trim(
                (string) config(
                    'services.github.app_private_key_base64',
                    '',
                ),
            ) !== '';
    }
}
