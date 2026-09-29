<?php

namespace App\Services;

use App\Models\GitHubWebhookDelivery;
use App\Models\PlanArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GitHubIntegrationDiagnosticsService
{
    public function __construct(
        private readonly GitHubRepositoryWriter $writer,
        private readonly GitHubWorkflowService $workflow,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $appConfigured = $this->writer->configured();
        $webhookConfigured = trim((string) config('services.github.app_webhook_secret', '')) !== '';
        $queueDriver = trim((string) config('queue.default', 'sync')) ?: 'sync';
        $asyncQueueConfigured = ! in_array($queueDriver, ['sync', 'null'], true);

        $pendingGithubJobs = 0;
        $oldestGithubJobAt = null;
        $failedGithubJobs = 0;

        if (Schema::hasTable('jobs')) {
            $jobs = DB::table('jobs')
                ->where('payload', 'like', '%ProcessGitHubWebhookDelivery%');

            $pendingGithubJobs = (clone $jobs)->count();
            $oldestDueAt = (clone $jobs)
                ->where('available_at', '<=', now()->timestamp)
                ->min('available_at');

            if (is_numeric($oldestDueAt)) {
                $oldestGithubJobAt = CarbonImmutable::createFromTimestamp((int) $oldestDueAt);
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedGithubJobs = DB::table('failed_jobs')
                ->where('payload', 'like', '%ProcessGitHubWebhookDelivery%')
                ->count();
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

        if (Schema::hasTable('github_webhook_deliveries')) {
            $deliveries = GitHubWebhookDelivery::query();

            $deliveryStats['total'] = (clone $deliveries)->count();

            foreach (['accepted', 'processing', 'processed', 'ignored', 'failed'] as $status) {
                $deliveryStats[$status] = (clone $deliveries)
                    ->where('status', $status)
                    ->count();
            }

            $deliveryStats['last_received_at'] = (clone $deliveries)->max('received_at');
            $deliveryStats['last_processed_at'] = (clone $deliveries)
                ->whereNotNull('processed_at')
                ->max('processed_at');
            $deliveryStats['recent_processed_at'] = (clone $deliveries)
                ->where('status', 'processed')
                ->max('processed_at');
            $deliveryStats['recent_failed_at'] = (clone $deliveries)
                ->where('status', 'failed')
                ->max('processed_at');

            $stuckBefore = now()->subMinutes(5);
            $deliveryStats['stuck'] = (clone $deliveries)
                ->whereIn('status', ['accepted', 'processing'])
                ->where('received_at', '<=', $stuckBefore)
                ->count();

            $recentDeliveries = GitHubWebhookDelivery::query()
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get()
                ->map(fn (GitHubWebhookDelivery $delivery) => [
                    'delivery_id' => (string) $delivery->delivery_id,
                    'event_name' => (string) $delivery->event_name,
                    'action' => $delivery->action,
                    'repo_full_name' => (string) $delivery->repo_full_name,
                    'pull_request_numbers' => array_values((array) $delivery->pull_request_numbers),
                    'status' => (string) $delivery->status,
                    'matched_artifacts' => (int) $delivery->matched_artifacts,
                    'synced_tasks' => (int) $delivery->synced_tasks,
                    'skipped_entitlement' => (int) $delivery->skipped_entitlement,
                    'attempts' => (int) $delivery->attempts,
                    'has_error' => filled($delivery->last_error),
                    'received_at' => $delivery->received_at,
                    'processed_at' => $delivery->processed_at,
                ]);
        }

        $connectedRepositories = PlanArtifact::query()
            ->with('plan:id,title')
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (PlanArtifact $artifact) =>
                data_get($artifact->metadata, 'github_app_connection.status') === 'connected'
            )
            ->map(function (PlanArtifact $artifact) {
                $parsed = $this->workflow->parseUrl((string) $artifact->url);

                return [
                    'artifact_id' => (int) $artifact->id,
                    'plan_id' => (int) $artifact->plan_id,
                    'plan_title' => (string) ($artifact->plan?->title ?? 'Plan'),
                    'repo_full_name' => (string) ($parsed['repo_full_name'] ?? $artifact->title),
                    'updated_at' => $artifact->updated_at,
                ];
            })
            ->values();

        $oldestPendingSeconds = $oldestGithubJobAt
            ? max(0, now()->diffInSeconds($oldestGithubJobAt, false) * -1)
            : null;

        $workerObservation = $this->workerObservation(
            $webhookConfigured,
            $asyncQueueConfigured,
            $pendingGithubJobs,
            $oldestPendingSeconds,
            (int) $deliveryStats['stuck'],
            $deliveryStats['recent_processed_at'],
        );

        $overallState = $this->overallState(
            $appConfigured,
            $webhookConfigured,
            $asyncQueueConfigured,
            $connectedRepositories->count(),
            $failedGithubJobs,
            (int) $deliveryStats['failed'],
            (int) $deliveryStats['stuck'],
            $workerObservation['state'],
        );

        return [
            'overall_state' => $overallState,
            'configuration' => [
                'app_configured' => $appConfigured,
                'webhook_configured' => $webhookConfigured,
                'queue_driver' => $queueDriver,
                'async_queue_configured' => $asyncQueueConfigured,
            ],
            'queue' => [
                'pending_github_jobs' => $pendingGithubJobs,
                'oldest_pending_at' => $oldestGithubJobAt,
                'oldest_pending_seconds' => $oldestPendingSeconds,
                'failed_github_jobs' => $failedGithubJobs,
                'worker_observation' => $workerObservation,
            ],
            'deliveries' => $deliveryStats,
            'recent_deliveries' => $recentDeliveries,
            'repositories' => [
                'connected_count' => $connectedRepositories->count(),
                'items' => $connectedRepositories->take(30)->values(),
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * @return array{state:string,label:string,note:string}
     */
    private function workerObservation(
        bool $webhookConfigured,
        bool $asyncQueueConfigured,
        int $pendingJobs,
        ?int $oldestPendingSeconds,
        int $stuckDeliveries,
        mixed $recentProcessedAt,
    ): array {
        if (! $webhookConfigured) {
            return [
                'state' => 'not_configured',
                'label' => '未設定',
                'note' => 'Webhook secretが未設定なので、自動同期はまだ有効化されていません。',
            ];
        }

        if (! $asyncQueueConfigured) {
            return [
                'state' => 'attention',
                'label' => '要設定',
                'note' => 'Queue driverが同期実行です。本番ではdatabase / redis等の非同期Queueを利用してください。',
            ];
        }

        if ($stuckDeliveries > 0 || ($pendingJobs > 0 && ($oldestPendingSeconds ?? 0) >= 300)) {
            return [
                'state' => 'attention',
                'label' => '滞留あり',
                'note' => '5分以上処理されていないWebhook deliveryまたはGitHub Queue Jobがあります。Worker状態を確認してください。',
            ];
        }

        if ($recentProcessedAt) {
            $processedAt = CarbonImmutable::parse((string) $recentProcessedAt);
            if ($processedAt->gte(now()->subMinutes(30))) {
                return [
                    'state' => 'observed',
                    'label' => '最近処理を確認',
                    'note' => '直近30分以内にWebhook deliveryのbackground処理が完了しています。',
                ];
            }
        }

        return [
            'state' => 'unknown',
            'label' => '稼働未確認',
            'note' => '設定は確認できますが、Worker processの生存自体はこの画面だけでは断定しません。実deliveryが処理されると観測できます。',
        ];
    }

    private function overallState(
        bool $appConfigured,
        bool $webhookConfigured,
        bool $asyncQueueConfigured,
        int $connectedRepositoryCount,
        int $failedGithubJobs,
        int $failedDeliveries,
        int $stuckDeliveries,
        string $workerState,
    ): string {
        if (! $appConfigured || ! $webhookConfigured || ! $asyncQueueConfigured) {
            return 'setup_required';
        }

        if (
            $failedGithubJobs > 0
            || $failedDeliveries > 0
            || $stuckDeliveries > 0
            || $workerState === 'attention'
        ) {
            return 'attention';
        }

        if ($connectedRepositoryCount === 0 || $workerState === 'unknown') {
            return 'unverified';
        }

        return 'healthy';
    }
}
