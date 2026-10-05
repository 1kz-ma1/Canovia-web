<?php

namespace App\Services;

use App\Models\GitHubWebhookDelivery;
use App\Models\PlanArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class GitHubIntegrationDiagnosticsService
{
    public function __construct(
        private readonly GitHubRepositoryWriter $writer,
        private readonly GitHubWorkflowService $workflow,
    ) {}

    /**
     * Diagnostics must remain available even when one integration table is
     * missing or stale. This method therefore treats schema/query failures as
     * diagnostic facts instead of allowing the diagnostics page itself to 500.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $errors = [];
        $deliveryTable = (new GitHubWebhookDelivery())->getTable();
        $artifactTable = (new PlanArtifact())->getTable();

        $appIdConfigured = trim(
            (string) config('services.github.app_id', ''),
        ) !== '';
        $privateKeyConfigured = $this->privateKeyConfigured();
        $appConfigured = $appIdConfigured && $privateKeyConfigured;
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

        $schema = [
            'webhook_deliveries_table' => $this->tableExists(
                $deliveryTable,
                $errors,
            ),
            'plan_artifacts_table' => $this->tableExists(
                $artifactTable,
                $errors,
            ),
            'jobs_table' => $this->tableExists(
                'jobs',
                $errors,
            ),
            'failed_jobs_table' => $this->tableExists(
                'failed_jobs',
                $errors,
            ),
            'webhook_deliveries_table_name' => $deliveryTable,
        ];

        $queueStorageReady = $queueDriver !== 'database'
            || (bool) $schema['jobs_table'];

        $pendingGithubJobs = 0;
        $oldestGithubJobAt = null;
        $failedGithubJobs = 0;

        if ($queueDriver === 'database' && $schema['jobs_table']) {
            try {
                $jobs = DB::table('jobs')
                    ->where(
                        'payload',
                        'like',
                        '%ProcessGitHubWebhookDelivery%',
                    );

                $pendingGithubJobs = (clone $jobs)->count();
                $oldestDueAt = (clone $jobs)
                    ->where(
                        'available_at',
                        '<=',
                        now()->timestamp,
                    )
                    ->min('available_at');

                if (is_numeric($oldestDueAt)) {
                    $oldestGithubJobAt =
                        CarbonImmutable::createFromTimestamp(
                            (int) $oldestDueAt,
                        );
                }
            } catch (Throwable $exception) {
                $errors[] = [
                    'area' => 'queue',
                    'message' => 'GitHub Queue状態を読み取れませんでした。',
                    'exception' => $exception::class,
                ];
            }
        }

        if ($schema['failed_jobs_table']) {
            try {
                $failedGithubJobs = DB::table('failed_jobs')
                    ->where(
                        'payload',
                        'like',
                        '%ProcessGitHubWebhookDelivery%',
                    )
                    ->count();
            } catch (Throwable $exception) {
                $errors[] = [
                    'area' => 'failed_jobs',
                    'message' => 'Failed GitHub Jobを読み取れませんでした。',
                    'exception' => $exception::class,
                ];
            }
        }

        $deliveryStats = [
            'total' => 0,
            'accepted' => 0,
            'processing' => 0,
            'processed' => 0,
            'ignored' => 0,
            'failed' => 0,
            'last_received_at' => null,
            'last_processed_at' => null,
            'recent_processed_at' => null,
            'recent_failed_at' => null,
            'stuck' => 0,
        ];
        $recentDeliveries = collect();

        if ($schema['webhook_deliveries_table']) {
            try {
                $deliveries = GitHubWebhookDelivery::query();

                $deliveryStats['total'] =
                    (clone $deliveries)->count();

                foreach (
                    [
                        'accepted',
                        'processing',
                        'processed',
                        'ignored',
                        'failed',
                    ] as $status
                ) {
                    $deliveryStats[$status] =
                        (clone $deliveries)
                            ->where('status', $status)
                            ->count();
                }

                $deliveryStats['last_received_at'] =
                    (clone $deliveries)->max('received_at');
                $deliveryStats['last_processed_at'] =
                    (clone $deliveries)
                        ->whereNotNull('processed_at')
                        ->max('processed_at');
                $deliveryStats['recent_processed_at'] =
                    (clone $deliveries)
                        ->where('status', 'processed')
                        ->max('processed_at');
                $deliveryStats['recent_failed_at'] =
                    (clone $deliveries)
                        ->where('status', 'failed')
                        ->max('processed_at');

                $stuckBefore = now()->subMinutes(5);
                $deliveryStats['stuck'] =
                    (clone $deliveries)
                        ->whereIn(
                            'status',
                            ['accepted', 'processing'],
                        )
                        ->where(
                            'received_at',
                            '<=',
                            $stuckBefore,
                        )
                        ->count();

                $recentDeliveries =
                    GitHubWebhookDelivery::query()
                        ->orderByDesc('received_at')
                        ->orderByDesc('id')
                        ->limit(30)
                        ->get()
                        ->map(
                            fn (
                                GitHubWebhookDelivery $delivery,
                            ) => [
                                'delivery_id' =>
                                    (string) $delivery->delivery_id,
                                'event_name' =>
                                    (string) $delivery->event_name,
                                'action' => $delivery->action,
                                'repo_full_name' =>
                                    (string) $delivery->repo_full_name,
                                'pull_request_numbers' =>
                                    array_values(
                                        (array) $delivery
                                            ->pull_request_numbers,
                                    ),
                                'status' =>
                                    (string) $delivery->status,
                                'matched_artifacts' =>
                                    (int) $delivery
                                        ->matched_artifacts,
                                'synced_tasks' =>
                                    (int) $delivery->synced_tasks,
                                'skipped_entitlement' =>
                                    (int) $delivery
                                        ->skipped_entitlement,
                                'attempts' =>
                                    (int) $delivery->attempts,
                                'has_error' =>
                                    filled($delivery->last_error),
                                'received_at' =>
                                    $delivery->received_at,
                                'processed_at' =>
                                    $delivery->processed_at,
                            ],
                        );
            } catch (Throwable $exception) {
                $errors[] = [
                    'area' => 'webhook_deliveries',
                    'message' => 'GitHub Webhook delivery状態を読み取れませんでした。',
                    'exception' => $exception::class,
                ];
            }
        }

        $connectedRepositories = collect();

        if ($schema['plan_artifacts_table']) {
            try {
                $connectedRepositories =
                    PlanArtifact::query()
                        ->with('plan:id,title')
                        ->where('provider', 'github')
                        ->where(
                            'artifact_type',
                            'repository',
                        )
                        ->orderByDesc('updated_at')
                        ->get()
                        ->filter(
                            fn (PlanArtifact $artifact) =>
                                data_get(
                                    $artifact->metadata,
                                    'github_app_connection.status',
                                ) === 'connected',
                        )
                        ->map(function (
                            PlanArtifact $artifact,
                        ) {
                            $parsed = $this->workflow->parseUrl(
                                (string) $artifact->url,
                            );

                            return [
                                'artifact_id' =>
                                    (int) $artifact->id,
                                'plan_id' =>
                                    (int) $artifact->plan_id,
                                'plan_title' =>
                                    (string) (
                                        $artifact->plan?->title
                                        ?? 'Plan'
                                    ),
                                'repo_full_name' =>
                                    (string) (
                                        $parsed['repo_full_name']
                                        ?? $artifact->title
                                    ),
                                'updated_at' =>
                                    $artifact->updated_at,
                            ];
                        })
                        ->values();
            } catch (Throwable $exception) {
                $errors[] = [
                    'area' => 'repositories',
                    'message' => '接続済みRepositoryを読み取れませんでした。',
                    'exception' => $exception::class,
                ];
            }
        }

        $oldestPendingSeconds = $oldestGithubJobAt
            ? max(
                0,
                now()->diffInSeconds(
                    $oldestGithubJobAt,
                    false,
                ) * -1,
            )
            : null;

        $workerObservation = $this->workerObservation(
            $webhookConfigured,
            $asyncQueueConfigured,
            $queueStorageReady,
            $pendingGithubJobs,
            $oldestPendingSeconds,
            (int) $deliveryStats['stuck'],
            $deliveryStats['recent_processed_at'],
        );

        $schemaReady =
            (bool) $schema['webhook_deliveries_table']
            && (bool) $schema['plan_artifacts_table']
            && $queueStorageReady;

        $overallState = $this->overallState(
            $appConfigured,
            $installUrlConfigured,
            $webhookConfigured,
            $asyncQueueConfigured,
            $schemaReady,
            $connectedRepositories->count(),
            $failedGithubJobs,
            (int) $deliveryStats['failed'],
            (int) $deliveryStats['stuck'],
            $workerObservation['state'],
            $errors,
        );

        $configuration = [
            'app_id_configured' => $appIdConfigured,
            'private_key_configured' =>
                $privateKeyConfigured,
            'app_configured' => $appConfigured,
            'install_url_configured' =>
                $installUrlConfigured,
            'webhook_configured' => $webhookConfigured,
            'queue_driver' => $queueDriver,
            'async_queue_configured' =>
                $asyncQueueConfigured,
            'queue_storage_ready' =>
                $queueStorageReady,
        ];

        return [
            'overall_state' => $overallState,
            'configuration' => $configuration,
            'schema' => $schema,
            'queue' => [
                'pending_github_jobs' =>
                    $pendingGithubJobs,
                'oldest_pending_at' =>
                    $oldestGithubJobAt,
                'oldest_pending_seconds' =>
                    $oldestPendingSeconds,
                'failed_github_jobs' =>
                    $failedGithubJobs,
                'worker_observation' =>
                    $workerObservation,
            ],
            'deliveries' => $deliveryStats,
            'recent_deliveries' =>
                $recentDeliveries,
            'repositories' => [
                'connected_count' =>
                    $connectedRepositories->count(),
                'items' =>
                    $connectedRepositories
                        ->take(30)
                        ->values(),
            ],
            'operator_steps' => $this->operatorSteps(
                $configuration,
                $schema,
                $connectedRepositories->count(),
                $workerObservation,
            ),
            'diagnostic_errors' => $errors,
            'generated_at' => now(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $errors
     */
    private function tableExists(
        string $table,
        array &$errors,
    ): bool {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $exception) {
            $errors[] = [
                'area' => 'schema',
                'message' =>
                    "Table {$table} の存在確認に失敗しました。",
                'exception' => $exception::class,
            ];

            return false;
        }
    }

    /**
     * @return array{state:string,label:string,note:string}
     */
    private function workerObservation(
        bool $webhookConfigured,
        bool $asyncQueueConfigured,
        bool $queueStorageReady,
        int $pendingJobs,
        ?int $oldestPendingSeconds,
        int $stuckDeliveries,
        mixed $recentProcessedAt,
    ): array {
        if (! $webhookConfigured) {
            return [
                'state' => 'not_configured',
                'label' => '未設定',
                'note' => 'Webhook Secretが未設定なので、自動Return Syncはまだ有効化されていません。',
            ];
        }

        if (! $asyncQueueConfigured) {
            return [
                'state' => 'attention',
                'label' => 'Queue要設定',
                'note' => 'Queue driverが同期実行です。本番ではdatabase / redis等の非同期Queueを利用してください。',
            ];
        }

        if (! $queueStorageReady) {
            return [
                'state' => 'attention',
                'label' => 'Queue Schema未準備',
                'note' => '現在のQueue driverに必要なstorage tableを確認できません。',
            ];
        }

        if (
            $stuckDeliveries > 0
            || (
                $pendingJobs > 0
                && ($oldestPendingSeconds ?? 0) >= 300
            )
        ) {
            return [
                'state' => 'attention',
                'label' => '滞留あり',
                'note' => '5分以上処理されていないWebhook deliveryまたはGitHub Queue Jobがあります。Worker状態を確認してください。',
            ];
        }

        if ($recentProcessedAt) {
            $processedAt = CarbonImmutable::parse(
                (string) $recentProcessedAt,
            );

            if (
                $processedAt->gte(
                    now()->subMinutes(30),
                )
            ) {
                return [
                    'state' => 'observed',
                    'label' => '最近処理を確認',
                    'note' => '直近30分以内にWebhook deliveryのbackground処理が完了しています。',
                ];
            }
        }

        return [
            'state' => 'unknown',
            'label' => 'Worker未観測',
            'note' => 'Queue設定はありますが、Worker processの生存はまだ観測できていません。実Webhookがprocessedになるまで稼働済みとは判定しません。',
        ];
    }

    /**
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $worker
     * @return array<int,array<string,mixed>>
     */
    private function operatorSteps(
        array $configuration,
        array $schema,
        int $connectedRepositoryCount,
        array $worker,
    ): array {
        $schemaReady =
            (bool) ($schema['webhook_deliveries_table'] ?? false)
            && (bool) ($schema['plan_artifacts_table'] ?? false)
            && (
                (string) (
                    $configuration['queue_driver']
                    ?? 'sync'
                ) !== 'database'
                || (bool) ($schema['jobs_table'] ?? false)
            );

        return [
            [
                'key' => 'app_credentials',
                'owner' => 'CANOVIA',
                'done' => (bool) (
                    $configuration['app_configured']
                    ?? false
                ),
                'label' => 'GitHub App ID / Private Key',
                'detail' => 'Render等のserver-side環境へ設定します。秘密値はUIへ表示しません。',
            ],
            [
                'key' => 'install_url',
                'owner' => 'CANOVIA',
                'done' => (bool) (
                    $configuration[
                        'install_url_configured'
                    ] ?? false
                ),
                'label' => 'GitHub App Install URL',
                'detail' => 'ユーザーがRepository選択へ進むためのGitHub App install URLです。',
            ],
            [
                'key' => 'webhook_secret',
                'owner' => 'GITHUB APP / RENDER',
                'done' => (bool) (
                    $configuration[
                        'webhook_configured'
                    ] ?? false
                ),
                'label' => 'Webhook Secret',
                'detail' => 'GitHub AppとRenderへ同じsecretを設定します。',
            ],
            [
                'key' => 'async_queue',
                'owner' => 'RENDER',
                'done' => (bool) (
                    $configuration[
                        'async_queue_configured'
                    ] ?? false
                ),
                'label' => 'Async Queue driver',
                'detail' =>
                    'database / redis等の非同期Queueを利用します。',
            ],
            [
                'key' => 'schema',
                'owner' => 'CANOVIA / DB',
                'done' => $schemaReady,
                'label' => 'GitHub integration schema',
                'detail' => 'Webhook deliveryとQueue storageのmigrationが適用済みである必要があります。',
            ],
            [
                'key' => 'repository_installation',
                'owner' => 'GITHUB / REPOSITORY ADMIN',
                'done' => $connectedRepositoryCount > 0,
                'label' => 'Repository Installation',
                'detail' => '対象RepositoryへCanovia GitHub Appをinstallし、必要なpermissionを承認します。',
            ],
            [
                'key' => 'worker',
                'owner' => 'RENDER',
                'done' =>
                    ($worker['state'] ?? null)
                    === 'observed',
                'label' => 'Queue Worker',
                'detail' => 'php artisan queue:work --sleep=1 --tries=4 --timeout=90 を常駐させ、実Webhookがprocessedになることを確認します。',
                'state' =>
                    (string) (
                        $worker['state']
                        ?? 'unknown'
                    ),
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $errors
     */
    private function overallState(
        bool $appConfigured,
        bool $installUrlConfigured,
        bool $webhookConfigured,
        bool $asyncQueueConfigured,
        bool $schemaReady,
        int $connectedRepositoryCount,
        int $failedGithubJobs,
        int $failedDeliveries,
        int $stuckDeliveries,
        string $workerState,
        array $errors,
    ): string {
        if (
            ! $appConfigured
            || ! $installUrlConfigured
            || ! $webhookConfigured
            || ! $asyncQueueConfigured
            || ! $schemaReady
        ) {
            return 'setup_required';
        }

        if (
            $errors !== []
            || $failedGithubJobs > 0
            || $failedDeliveries > 0
            || $stuckDeliveries > 0
            || $workerState === 'attention'
        ) {
            return 'attention';
        }

        if (
            $connectedRepositoryCount === 0
            || $workerState === 'unknown'
        ) {
            return 'unverified';
        }

        return 'healthy';
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
