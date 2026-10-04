<?php

namespace App\Services;

final class GitHubWebhookService
{
    public const MAX_PAYLOAD_BYTES = 1_000_000;

    private const SUPPORTED_EVENTS = [
        'pull_request',
        'pull_request_review',
        'workflow_run',
        'check_run',
        'check_suite',
        'issues',
        'push',
        'create',
        'deployment',
        'deployment_status',
    ];

    public function configured(): bool
    {
        return trim((string) config('services.github.app_webhook_secret', '')) !== '';
    }

    public function verifySignature(string $payload, ?string $signature): bool
    {
        $secret = trim((string) config('services.github.app_webhook_secret', ''));
        $signature = trim((string) $signature);

        if ($secret === '' || ! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals(strtolower($expected), strtolower($signature));
    }

    public function supported(string $eventName): bool
    {
        return in_array($eventName, self::SUPPORTED_EVENTS, true);
    }

    private function branchFromRef(string $ref): ?string
    {
        $ref = trim($ref);

        if (! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return $this->boundedRef(substr($ref, strlen('refs/heads/')));
    }

    private function boundedRef(string $value): ?string
    {
        $value = trim($value);

        if (
            $value === ''
            || mb_strlen($value) > 255
            || str_contains($value, "\0")
            || str_contains($value, '..')
            || preg_match('/[\x00-\x1F\x7F]/u', $value)
        ) {
            return null;
        }

        return $value;
    }

    /**
     * Reduce an authenticated GitHub webhook payload to the minimum routing
     * facts Canovia needs. Raw webhook content is intentionally not persisted.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public function route(string $eventName, array $payload): ?array
    {
        if (! $this->supported($eventName)) {
            return null;
        }

        $repoFullName = trim((string) data_get($payload, 'repository.full_name', ''));
        if (
            $repoFullName === ''
            || ! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repoFullName)
        ) {
            return null;
        }

        $installationId = (int) data_get($payload, 'installation.id', 0);
        if ($installationId <= 0) {
            return null;
        }

        $numbers = match ($eventName) {
            'pull_request', 'pull_request_review' => collect([
                (int) (
                    data_get($payload, 'pull_request.number')
                    ?: data_get($payload, 'number', 0)
                ),
            ]),
            'workflow_run' => collect((array) data_get($payload, 'workflow_run.pull_requests', []))
                ->map(fn ($pull) => (int) data_get($pull, 'number', 0)),
            'check_run' => collect((array) data_get($payload, 'check_run.pull_requests', []))
                ->map(fn ($pull) => (int) data_get($pull, 'number', 0)),
            'check_suite' => collect((array) data_get($payload, 'check_suite.pull_requests', []))
                ->map(fn ($pull) => (int) data_get($pull, 'number', 0)),
            default => collect(),
        };

        $numbers = $numbers
            ->filter(fn (int $number) => $number > 0)
            ->unique()
            ->take(20)
            ->values();

        $targets = $numbers
            ->map(fn (int $number) => [
                'kind' => 'pull_request',
                'number' => $number,
            ]);

        if ($eventName === 'issues') {
            $issueNumber = (int) (
                data_get($payload, 'issue.number')
                ?: data_get($payload, 'number', 0)
            );

            if ($issueNumber > 0) {
                $targets->push([
                    'kind' => 'issue',
                    'number' => $issueNumber,
                ]);
            }
        }

        if ($eventName === 'push') {
            $branch = $this->branchFromRef(
                (string) data_get($payload, 'ref', ''),
            );
            $after = mb_strtolower(mb_substr(
                trim((string) data_get($payload, 'after', '')),
                0,
                64,
            ));

            if (
                $branch !== null
                && preg_match('/^[a-f0-9]{7,64}$/', $after)
                && ! preg_match('/^0+$/', $after)
            ) {
                $targets->push([
                    'kind' => 'push',
                    'branch' => $branch,
                    'commit_sha' => $after,
                ]);
            }
        }

        if (
            $eventName === 'create'
            && (string) data_get($payload, 'ref_type', '') === 'branch'
        ) {
            $branch = $this->boundedRef(
                (string) data_get($payload, 'ref', ''),
            );

            if ($branch !== null) {
                $targets->push([
                    'kind' => 'branch',
                    'branch' => $branch,
                ]);
            }
        }

        if (in_array($eventName, ['deployment', 'deployment_status'], true)) {
            $deploymentId = (int) data_get($payload, 'deployment.id', 0);

            if ($deploymentId > 0) {
                $targets->push([
                    'kind' => 'deployment',
                    'deployment_id' => $deploymentId,
                ]);
            }
        }

        $targets = $targets
            ->filter(fn ($target) => is_array($target))
            ->unique(fn (array $target) => json_encode(
                $target,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ))
            ->take(30)
            ->values();

        if ($targets->isEmpty()) {
            return null;
        }

        return [
            'event_name' => $eventName,
            'action' => mb_substr(trim((string) data_get($payload, 'action', '')), 0, 80) ?: null,
            'repo_full_name' => $repoFullName,
            'installation_id' => $installationId,
            'pull_request_numbers' => $numbers->all(),
            'routing_targets' => $targets->all(),
        ];
    }
}
