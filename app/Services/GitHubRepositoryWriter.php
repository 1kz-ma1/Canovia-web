<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class GitHubRepositoryWriter
{
    public const MAX_CONTENT_BYTES = 200_000;

    public function configured(): bool
    {
        return trim((string) config('services.github.app_id', '')) !== ''
            && $this->configuredPrivateKey() !== null;
    }

    public function installUrl(): ?string
    {
        $url = trim((string) config('services.github.app_install_url', ''));
        if ($url === '') {
            return null;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' && in_array($host, ['github.com', 'www.github.com'], true)
            ? $url
            : null;
    }

    public function installUrlForState(string $state): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{32,128}$/', $state)) {
            return null;
        }

        $url = $this->installUrl();
        if ($url === null) {
            return null;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'state='.rawurlencode($state);
    }

    /**
     * Return the current GitHub App installation for one Repository.
     *
     * The installation id is not trusted from browser input. Canovia always
     * asks GitHub for the installation bound to the target owner/repository.
     *
     * @return array<string,mixed>|null
     */
    public function repositoryInstallation(string $repoFullName): ?array
    {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);

        $response = $this->appClient($this->appJwt())
            ->get('/repos/'.$repoPath.'/installation');

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException('GitHub AppのRepository接続状態を確認できませんでした。');
        }

        $installation = $response->json();
        if (! is_array($installation)) {
            throw new RuntimeException('GitHub App installation情報を読み取れませんでした。');
        }

        $installationId = (int) ($installation['id'] ?? 0);
        if ($installationId <= 0) {
            throw new RuntimeException('GitHub App installation IDを確認できませんでした。');
        }

        $permissions = collect((array) ($installation['permissions'] ?? []))
            ->filter(fn ($value, $key) =>
                is_string($key)
                && preg_match('/^[a-z_]{1,80}$/', $key)
                && is_string($value)
                && in_array($value, ['read', 'write'], true)
            )
            ->map(fn (string $value) => $value)
            ->all();

        return [
            'installation_id' => $installationId,
            'account_login' => mb_substr((string) data_get($installation, 'account.login', ''), 0, 255),
            'account_type' => mb_substr((string) data_get($installation, 'account.type', ''), 0, 80),
            'target_type' => mb_substr((string) ($installation['target_type'] ?? ''), 0, 80),
            'repository_selection' => mb_substr((string) ($installation['repository_selection'] ?? ''), 0, 80),
            'permissions' => $permissions,
            'management_url' => $this->githubUrl($installation['html_url'] ?? null),
        ];
    }

    /**
     * Verify that a setup redirect refers to the installation GitHub currently
     * exposes for the exact Repository selected in Canovia.
     *
     * @return array<string,mixed>
     */
    public function verifyRepositoryInstallation(
        string $repoFullName,
        int $expectedInstallationId,
    ): array {
        if ($expectedInstallationId <= 0) {
            throw new RuntimeException('GitHub App installation IDを確認できませんでした。');
        }

        $installation = $this->repositoryInstallation($repoFullName);
        if ($installation === null) {
            throw new RuntimeException('対象RepositoryへのCanovia GitHub App接続をまだ確認できません。');
        }

        if ((int) ($installation['installation_id'] ?? 0) !== $expectedInstallationId) {
            throw new RuntimeException('GitHubから返された接続情報と対象Repositoryが一致しません。');
        }

        return $installation;
    }

    /**
     * Read the authoritative return state for one Pull Request through the
     * installed GitHub App. This method is read-only.
     *
     * Pull Requests permission is required. Actions / Checks / Commit statuses
     * are optional signals and are queried only when the installation grants
     * the corresponding read permission.
     *
     * @return array<string,mixed>
     */
    public function inspectPullRequestReturn(
        string $repoFullName,
        int $pullRequestNumber,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        if ($pullRequestNumber <= 0) {
            throw new RuntimeException('Pull Request番号を確認できませんでした。');
        }

        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);

        $appJwt = $this->appJwt();
        $installationResponse = $this->appClient($appJwt)
            ->get('/repos/'.$repoPath.'/installation');

        if ($installationResponse->status() === 404) {
            throw new RuntimeException('Canovia GitHub AppがこのRepositoryに接続されていません。Repository管理者に接続してもらってください。');
        }

        if (! $installationResponse->successful()) {
            throw new RuntimeException('GitHub AppのRepository接続を確認できませんでした。');
        }

        $installationId = (int) data_get($installationResponse->json(), 'id', 0);
        if ($installationId <= 0) {
            throw new RuntimeException('GitHub App installationを確認できませんでした。');
        }

        $tokenResponse = $this->appClient($appJwt)
            ->post('/app/installations/'.$installationId.'/access_tokens');

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('GitHub Appの一時Access Tokenを発行できませんでした。');
        }

        $token = trim((string) data_get($tokenResponse->json(), 'token', ''));
        $permissions = (array) data_get($tokenResponse->json(), 'permissions', []);

        if ($token === '') {
            throw new RuntimeException('GitHub Appの一時Access Tokenを取得できませんでした。');
        }

        if (! in_array(($permissions['pull_requests'] ?? null), ['read', 'write'], true)) {
            throw new RuntimeException('GitHub AppにPull Requestsのread権限がありません。');
        }

        $client = $this->installationClient($token);
        $pullResponse = $client->get('/repos/'.$repoPath.'/pulls/'.$pullRequestNumber);

        if ($pullResponse->status() === 404) {
            throw new RuntimeException('対象Pull RequestをGitHubから確認できませんでした。');
        }

        if (! $pullResponse->successful()) {
            throw new RuntimeException('Pull Requestの現在状態をGitHubから取得できませんでした。');
        }

        $pull = $pullResponse->json();
        if (! is_array($pull)) {
            throw new RuntimeException('Pull Request情報を読み取れませんでした。');
        }

        $actualNumber = (int) ($pull['number'] ?? 0);
        if ($actualNumber !== $pullRequestNumber) {
            throw new RuntimeException('GitHubから返されたPull Request番号が一致しません。');
        }

        $headSha = mb_substr(trim((string) data_get($pull, 'head.sha', '')), 0, 64);
        $warnings = [];

        $reviewsResponse = $client->get('/repos/'.$repoPath.'/pulls/'.$pullRequestNumber.'/reviews', [
            'per_page' => 50,
        ]);
        if (! $reviewsResponse->successful()) {
            throw new RuntimeException('Pull Request reviewをGitHubから取得できませんでした。');
        }

        $reviews = collect($reviewsResponse->json())
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => [
                'id' => (int) ($item['id'] ?? 0),
                'state' => mb_strtoupper(mb_substr((string) ($item['state'] ?? ''), 0, 50)),
                'reviewer' => mb_substr((string) data_get($item, 'user.login', ''), 0, 255),
                'submitted_at' => $this->dateValue($item['submitted_at'] ?? null),
                'commit_id' => mb_substr((string) ($item['commit_id'] ?? ''), 0, 64),
                'url' => $this->githubUrl($item['html_url'] ?? null),
            ])
            ->filter(fn (array $item) => $item['id'] > 0 && $item['state'] !== '')
            ->sortBy(fn (array $item) => ($item['submitted_at'] ?? '').':'.str_pad((string) $item['id'], 20, '0', STR_PAD_LEFT))
            ->values();

        $latestDecisions = [];
        foreach ($reviews as $review) {
            $reviewer = trim((string) ($review['reviewer'] ?? ''));
            $state = (string) ($review['state'] ?? '');

            if ($reviewer === '' || ! in_array($state, ['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'], true)) {
                continue;
            }

            $latestDecisions[$reviewer] = $review;
        }

        $latestDecisionValues = collect(array_values($latestDecisions));
        $approvedCount = $latestDecisionValues->where('state', 'APPROVED')->count();
        $changesRequestedCount = $latestDecisionValues->where('state', 'CHANGES_REQUESTED')->count();

        $actionsRuns = [];
        $checks = [];
        $combinedStatus = null;

        if ($headSha !== '' && in_array(($permissions['actions'] ?? null), ['read', 'write'], true)) {
            $actionsResponse = $client->get('/repos/'.$repoPath.'/actions/runs', [
                'head_sha' => $headSha,
                'per_page' => 20,
            ]);

            if ($actionsResponse->successful()) {
                $runData = $actionsResponse->json();
                $actionsRuns = is_array($runData)
                    ? collect($runData['workflow_runs'] ?? [])
                        ->filter(fn ($item) => is_array($item))
                        ->take(20)
                        ->map(fn (array $item) => [
                            'id' => (int) ($item['id'] ?? 0),
                            'run_attempt' => max(1, (int) ($item['run_attempt'] ?? 1)),
                            'name' => mb_substr((string) ($item['name'] ?? ''), 0, 255),
                            'event' => mb_substr((string) ($item['event'] ?? ''), 0, 100),
                            'status' => mb_substr((string) ($item['status'] ?? ''), 0, 100),
                            'conclusion' => filled($item['conclusion'] ?? null)
                                ? mb_substr((string) $item['conclusion'], 0, 100)
                                : null,
                            'head_sha' => mb_substr((string) ($item['head_sha'] ?? ''), 0, 64),
                            'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                            'url' => $this->githubUrl($item['html_url'] ?? null),
                        ])
                        ->filter(fn (array $item) => $item['id'] > 0)
                        ->values()
                        ->all()
                    : [];
            } else {
                $warnings[] = 'GitHub Actionsの結果を取得できませんでした。';
            }
        } else {
            $warnings[] = 'GitHub Actions permission未設定のためCI workflow結果は取得していません。';
        }

        if ($headSha !== '' && in_array(($permissions['checks'] ?? null), ['read', 'write'], true)) {
            $checksResponse = $client->get('/repos/'.$repoPath.'/commits/'.$headSha.'/check-runs', [
                'per_page' => 30,
            ]);

            if ($checksResponse->successful()) {
                $checkData = $checksResponse->json();
                $checks = is_array($checkData)
                    ? collect($checkData['check_runs'] ?? [])
                        ->filter(fn ($item) => is_array($item))
                        ->take(30)
                        ->map(fn (array $item) => [
                            'id' => (int) ($item['id'] ?? 0),
                            'name' => mb_substr((string) ($item['name'] ?? ''), 0, 255),
                            'status' => mb_substr((string) ($item['status'] ?? ''), 0, 100),
                            'conclusion' => filled($item['conclusion'] ?? null)
                                ? mb_substr((string) $item['conclusion'], 0, 100)
                                : null,
                            'completed_at' => $this->dateValue($item['completed_at'] ?? null),
                            'url' => $this->githubUrl($item['html_url'] ?? null),
                        ])
                        ->filter(fn (array $item) => $item['id'] > 0)
                        ->values()
                        ->all()
                    : [];
            } else {
                $warnings[] = 'GitHub Checksの結果を取得できませんでした。';
            }
        }

        if ($headSha !== '' && in_array(($permissions['statuses'] ?? null), ['read', 'write'], true)) {
            $statusResponse = $client->get('/repos/'.$repoPath.'/commits/'.$headSha.'/status');

            if ($statusResponse->successful()) {
                $statusData = $statusResponse->json();
                if (is_array($statusData)) {
                    $combinedStatus = [
                        'state' => mb_substr((string) ($statusData['state'] ?? ''), 0, 50),
                        'total_count' => max(0, (int) ($statusData['total_count'] ?? 0)),
                        'statuses' => collect($statusData['statuses'] ?? [])
                            ->filter(fn ($item) => is_array($item))
                            ->take(30)
                            ->map(fn (array $item) => [
                                'id' => (int) ($item['id'] ?? 0),
                                'state' => mb_substr((string) ($item['state'] ?? ''), 0, 50),
                                'context' => mb_substr((string) ($item['context'] ?? ''), 0, 255),
                                'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                                'url' => $this->githubUrl($item['target_url'] ?? null),
                            ])
                            ->values()
                            ->all(),
                    ];
                }
            } else {
                $warnings[] = 'GitHub Commit Statusの結果を取得できませんでした。';
            }
        }

        $ciState = $this->ciState($actionsRuns, $checks, $combinedStatus);

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => $repoFullName,
            'installation_id' => $installationId,
            'fetched_at' => now()->toIso8601String(),
            'permissions' => collect($permissions)
                ->filter(fn ($value, $key) => is_string($key) && is_string($value))
                ->map(fn (string $value) => $value)
                ->all(),
            'pull_request' => [
                'number' => $actualNumber,
                'title' => mb_substr((string) ($pull['title'] ?? ''), 0, 500),
                'state' => mb_substr((string) ($pull['state'] ?? ''), 0, 50),
                'draft' => (bool) ($pull['draft'] ?? false),
                'merged' => (bool) ($pull['merged'] ?? false),
                'merged_at' => $this->dateValue($pull['merged_at'] ?? null),
                'merged_by' => mb_substr((string) data_get($pull, 'merged_by.login', ''), 0, 255),
                'merge_commit_sha' => mb_substr((string) ($pull['merge_commit_sha'] ?? ''), 0, 64),
                'head_sha' => $headSha,
                'head_ref' => mb_substr((string) data_get($pull, 'head.ref', ''), 0, 255),
                'base_ref' => mb_substr((string) data_get($pull, 'base.ref', ''), 0, 255),
                'updated_at' => $this->dateValue($pull['updated_at'] ?? null),
                'closed_at' => $this->dateValue($pull['closed_at'] ?? null),
                'url' => $this->githubUrl($pull['html_url'] ?? null),
            ],
            'reviews' => $reviews->take(50)->values()->all(),
            'review_summary' => [
                'approved_reviewers' => $approvedCount,
                'changes_requested_reviewers' => $changesRequestedCount,
                'latest_decisions' => $latestDecisionValues->values()->all(),
            ],
            'ci' => [
                'state' => $ciState,
                'actions_runs' => $actionsRuns,
                'check_runs' => $checks,
                'combined_status' => $combinedStatus,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Explicitly fetch bounded provider detail for one Pull Request triage.
     *
     * Unlike normal Developer Home GET, this method may fetch review text and
     * CI annotations because the user explicitly requested provider-linked
     * triage. Returned provider text is transient and must not be persisted by
     * callers.
     *
     * @return array<string,mixed>
     */
    public function inspectPullRequestTriage(
        string $repoFullName,
        int $pullRequestNumber,
        string $mode = 'auto',
    ): array {
        if ($pullRequestNumber <= 0) {
            throw new RuntimeException('Pull Request番号を確認できませんでした。');
        }

        if (! in_array($mode, ['auto', 'ci', 'review'], true)) {
            throw new RuntimeException('Provider triage modeを確認できませんでした。');
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'pull_requests',
            'Pull Requests',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $permissions = (array) $context['permissions'];
        $repoPath = (string) $context['repo_path'];

        $pullResponse = $client->get(
            '/repos/'.$repoPath.'/pulls/'.$pullRequestNumber,
        );

        if ($pullResponse->status() === 404) {
            throw new RuntimeException('対象Pull RequestをGitHubから確認できませんでした。');
        }

        if (! $pullResponse->successful()) {
            throw new RuntimeException('Pull Requestの現在状態をGitHubから取得できませんでした。');
        }

        $pull = $pullResponse->json();
        if (
            ! is_array($pull)
            || (int) ($pull['number'] ?? 0) !== $pullRequestNumber
        ) {
            throw new RuntimeException('GitHubから返されたPull Request情報を確認できませんでした。');
        }

        $headSha = mb_strtolower(mb_substr(
            trim((string) data_get($pull, 'head.sha', '')),
            0,
            64,
        ));
        $warnings = [];
        $reviewDetails = [
            'reviews' => [],
            'inline_comments' => [],
        ];
        $ciDetails = [
            'workflow_runs' => [],
            'jobs' => [],
            'check_runs' => [],
            'annotations' => [],
            'statuses' => [],
        ];

        if (in_array($mode, ['auto', 'review'], true)) {
            $reviewsResponse = $client->get(
                '/repos/'.$repoPath.'/pulls/'.$pullRequestNumber.'/reviews',
                ['per_page' => 50],
            );

            if ($reviewsResponse->successful()) {
                $reviewDetails['reviews'] = collect(
                    (array) $reviewsResponse->json(),
                )
                    ->filter(fn ($item) => is_array($item))
                    ->sortByDesc(fn (array $item) =>
                        (string) ($item['submitted_at'] ?? '')
                        .':'.str_pad(
                            (string) ((int) ($item['id'] ?? 0)),
                            20,
                            '0',
                            STR_PAD_LEFT,
                        )
                    )
                    ->take(20)
                    ->map(fn (array $item) => [
                        'id' => (int) ($item['id'] ?? 0),
                        'state' => mb_strtoupper(mb_substr(
                            (string) ($item['state'] ?? ''),
                            0,
                            50,
                        )),
                        'reviewer' => mb_substr(
                            (string) data_get($item, 'user.login', ''),
                            0,
                            255,
                        ),
                        'body' => $this->boundedProviderText(
                            $item['body'] ?? null,
                            1800,
                        ),
                        'submitted_at' => $this->dateValue(
                            $item['submitted_at'] ?? null,
                        ),
                        'commit_id' => mb_substr(
                            (string) ($item['commit_id'] ?? ''),
                            0,
                            64,
                        ),
                        'url' => $this->githubUrl(
                            $item['html_url'] ?? null,
                        ),
                    ])
                    ->filter(fn (array $item) =>
                        $item['id'] > 0 && $item['state'] !== ''
                    )
                    ->values()
                    ->all();
            } else {
                $warnings[] = 'Pull Request review本文を取得できませんでした。';
            }

            $commentsResponse = $client->get(
                '/repos/'.$repoPath.'/pulls/'.$pullRequestNumber.'/comments',
                ['per_page' => 50],
            );

            if ($commentsResponse->successful()) {
                $reviewDetails['inline_comments'] = collect(
                    (array) $commentsResponse->json(),
                )
                    ->filter(fn ($item) => is_array($item))
                    ->sortByDesc(fn (array $item) =>
                        (string) ($item['updated_at'] ?? $item['created_at'] ?? '')
                        .':'.str_pad(
                            (string) ((int) ($item['id'] ?? 0)),
                            20,
                            '0',
                            STR_PAD_LEFT,
                        )
                    )
                    ->take(30)
                    ->map(fn (array $item) => [
                        'id' => (int) ($item['id'] ?? 0),
                        'reviewer' => mb_substr(
                            (string) data_get($item, 'user.login', ''),
                            0,
                            255,
                        ),
                        'body' => $this->boundedProviderText(
                            $item['body'] ?? null,
                            1800,
                        ),
                        'path' => mb_substr(
                            (string) ($item['path'] ?? ''),
                            0,
                            500,
                        ),
                        'line' => $this->positiveInteger(
                            $item['line']
                            ?? $item['original_line']
                            ?? null,
                        ),
                        'side' => mb_substr(
                            (string) ($item['side'] ?? ''),
                            0,
                            20,
                        ),
                        'created_at' => $this->dateValue(
                            $item['created_at'] ?? null,
                        ),
                        'updated_at' => $this->dateValue(
                            $item['updated_at'] ?? null,
                        ),
                        'url' => $this->githubUrl(
                            $item['html_url'] ?? null,
                        ),
                    ])
                    ->filter(fn (array $item) =>
                        $item['id'] > 0 && $item['body'] !== null
                    )
                    ->values()
                    ->all();
            } else {
                $warnings[] = 'Pull Request inline commentを取得できませんでした。';
            }
        }

        if (in_array($mode, ['auto', 'ci'], true)) {
            if (
                $headSha !== ''
                && in_array(
                    $permissions['actions'] ?? null,
                    ['read', 'write'],
                    true,
                )
            ) {
                $actionsResponse = $client->get(
                    '/repos/'.$repoPath.'/actions/runs',
                    [
                        'head_sha' => $headSha,
                        'per_page' => 12,
                    ],
                );

                if ($actionsResponse->successful()) {
                    $actionsData = $actionsResponse->json();
                    $runs = is_array($actionsData)
                        ? collect($actionsData['workflow_runs'] ?? [])
                            ->filter(fn ($item) => is_array($item))
                            ->take(12)
                            ->map(fn (array $item) => [
                                'id' => (int) ($item['id'] ?? 0),
                                'run_attempt' => max(
                                    1,
                                    (int) ($item['run_attempt'] ?? 1),
                                ),
                                'name' => mb_substr(
                                    (string) ($item['name'] ?? ''),
                                    0,
                                    255,
                                ),
                                'status' => mb_substr(
                                    (string) ($item['status'] ?? ''),
                                    0,
                                    100,
                                ),
                                'conclusion' => filled(
                                    $item['conclusion'] ?? null,
                                )
                                    ? mb_substr(
                                        (string) $item['conclusion'],
                                        0,
                                        100,
                                    )
                                    : null,
                                'head_sha' => mb_substr(
                                    (string) ($item['head_sha'] ?? ''),
                                    0,
                                    64,
                                ),
                                'updated_at' => $this->dateValue(
                                    $item['updated_at'] ?? null,
                                ),
                                'url' => $this->githubUrl(
                                    $item['html_url'] ?? null,
                                ),
                            ])
                            ->filter(fn (array $item) => $item['id'] > 0)
                            ->values()
                        : collect();

                    $ciDetails['workflow_runs'] = $runs->all();

                    $failedRuns = $runs
                        ->filter(fn (array $run) => in_array(
                            $run['conclusion'] ?? null,
                            [
                                'failure',
                                'cancelled',
                                'timed_out',
                                'startup_failure',
                                'action_required',
                            ],
                            true,
                        ))
                        ->take(5);

                    foreach ($failedRuns as $run) {
                        $jobsResponse = $client->get(
                            '/repos/'.$repoPath
                            .'/actions/runs/'.(int) $run['id'].'/jobs',
                            [
                                'filter' => 'latest',
                                'per_page' => 30,
                            ],
                        );

                        if (! $jobsResponse->successful()) {
                            $warnings[] = 'GitHub Actions job詳細を取得できませんでした。';
                            continue;
                        }

                        $jobsData = $jobsResponse->json();
                        if (! is_array($jobsData)) {
                            continue;
                        }

                        collect($jobsData['jobs'] ?? [])
                            ->filter(fn ($item) => is_array($item))
                            ->filter(fn (array $job) => in_array(
                                $job['conclusion'] ?? null,
                                [
                                    'failure',
                                    'cancelled',
                                    'timed_out',
                                    'startup_failure',
                                    'action_required',
                                ],
                                true,
                            ))
                            ->take(20)
                            ->each(function (array $job) use (
                                &$ciDetails,
                                $run,
                            ) {
                                $ciDetails['jobs'][] = [
                                    'id' => (int) ($job['id'] ?? 0),
                                    'run_id' => (int) $run['id'],
                                    'run_name' => (string) $run['name'],
                                    'name' => mb_substr(
                                        (string) ($job['name'] ?? ''),
                                        0,
                                        255,
                                    ),
                                    'status' => mb_substr(
                                        (string) ($job['status'] ?? ''),
                                        0,
                                        100,
                                    ),
                                    'conclusion' => filled(
                                        $job['conclusion'] ?? null,
                                    )
                                        ? mb_substr(
                                            (string) $job['conclusion'],
                                            0,
                                            100,
                                        )
                                        : null,
                                    'url' => $this->githubUrl(
                                        $job['html_url'] ?? null,
                                    ),
                                    'steps' => collect(
                                        $job['steps'] ?? [],
                                    )
                                        ->filter(fn ($step) =>
                                            is_array($step)
                                        )
                                        ->filter(fn (array $step) =>
                                            in_array(
                                                $step['conclusion'] ?? null,
                                                [
                                                    'failure',
                                                    'cancelled',
                                                    'timed_out',
                                                    'action_required',
                                                ],
                                                true,
                                            )
                                        )
                                        ->take(10)
                                        ->map(fn (array $step) => [
                                            'number' => max(
                                                0,
                                                (int) (
                                                    $step['number']
                                                    ?? 0
                                                ),
                                            ),
                                            'name' => mb_substr(
                                                (string) (
                                                    $step['name']
                                                    ?? ''
                                                ),
                                                0,
                                                255,
                                            ),
                                            'conclusion' => mb_substr(
                                                (string) (
                                                    $step['conclusion']
                                                    ?? ''
                                                ),
                                                0,
                                                100,
                                            ),
                                        ])
                                        ->values()
                                        ->all(),
                                ];
                            });
                    }
                } else {
                    $warnings[] = 'GitHub Actions runを取得できませんでした。';
                }
            } else {
                $warnings[] = 'GitHub Actions read権限がないためworkflow job詳細は取得していません。';
            }

            if (
                $headSha !== ''
                && in_array(
                    $permissions['checks'] ?? null,
                    ['read', 'write'],
                    true,
                )
            ) {
                $checksResponse = $client->get(
                    '/repos/'.$repoPath.'/commits/'.$headSha.'/check-runs',
                    ['per_page' => 30],
                );

                if ($checksResponse->successful()) {
                    $checksData = $checksResponse->json();
                    $checks = is_array($checksData)
                        ? collect($checksData['check_runs'] ?? [])
                            ->filter(fn ($item) => is_array($item))
                            ->take(30)
                            ->map(fn (array $item) => [
                                'id' => (int) ($item['id'] ?? 0),
                                'name' => mb_substr(
                                    (string) ($item['name'] ?? ''),
                                    0,
                                    255,
                                ),
                                'status' => mb_substr(
                                    (string) ($item['status'] ?? ''),
                                    0,
                                    100,
                                ),
                                'conclusion' => filled(
                                    $item['conclusion'] ?? null,
                                )
                                    ? mb_substr(
                                        (string) $item['conclusion'],
                                        0,
                                        100,
                                    )
                                    : null,
                                'completed_at' => $this->dateValue(
                                    $item['completed_at'] ?? null,
                                ),
                                'url' => $this->githubUrl(
                                    $item['html_url'] ?? null,
                                ),
                            ])
                            ->filter(fn (array $item) => $item['id'] > 0)
                            ->values()
                        : collect();

                    $ciDetails['check_runs'] = $checks->all();

                    $failedChecks = $checks
                        ->filter(fn (array $check) => in_array(
                            $check['conclusion'] ?? null,
                            [
                                'failure',
                                'cancelled',
                                'timed_out',
                                'startup_failure',
                                'action_required',
                                'stale',
                            ],
                            true,
                        ))
                        ->take(8);

                    foreach ($failedChecks as $check) {
                        $annotationsResponse = $client->get(
                            '/repos/'.$repoPath
                            .'/check-runs/'.(int) $check['id']
                            .'/annotations',
                            ['per_page' => 30],
                        );

                        if (! $annotationsResponse->successful()) {
                            $warnings[] = 'GitHub Check annotationを取得できませんでした。';
                            continue;
                        }

                        collect((array) $annotationsResponse->json())
                            ->filter(fn ($item) => is_array($item))
                            ->take(30)
                            ->each(function (array $annotation) use (
                                &$ciDetails,
                                $check,
                            ) {
                                $message = $this->boundedProviderText(
                                    $annotation['message'] ?? null,
                                    1800,
                                );

                                if ($message === null) {
                                    return;
                                }

                                $ciDetails['annotations'][] = [
                                    'check_run_id' => (int) $check['id'],
                                    'check_name' => (string) $check['name'],
                                    'path' => mb_substr(
                                        (string) (
                                            $annotation['path']
                                            ?? ''
                                        ),
                                        0,
                                        500,
                                    ),
                                    'start_line' => $this->positiveInteger(
                                        $annotation['start_line']
                                        ?? null,
                                    ),
                                    'end_line' => $this->positiveInteger(
                                        $annotation['end_line']
                                        ?? null,
                                    ),
                                    'level' => mb_substr(
                                        (string) (
                                            $annotation['annotation_level']
                                            ?? ''
                                        ),
                                        0,
                                        50,
                                    ),
                                    'title' => $this->boundedProviderText(
                                        $annotation['title'] ?? null,
                                        500,
                                    ),
                                    'message' => $message,
                                ];
                            });
                    }
                } else {
                    $warnings[] = 'GitHub Check Runsを取得できませんでした。';
                }
            } else {
                $warnings[] = 'GitHub Checks read権限がないためannotation詳細は取得していません。';
            }

            if (
                $headSha !== ''
                && in_array(
                    $permissions['statuses'] ?? null,
                    ['read', 'write'],
                    true,
                )
            ) {
                $statusResponse = $client->get(
                    '/repos/'.$repoPath.'/commits/'.$headSha.'/status',
                );

                if ($statusResponse->successful()) {
                    $statusData = $statusResponse->json();

                    if (is_array($statusData)) {
                        $ciDetails['statuses'] = collect(
                            $statusData['statuses'] ?? [],
                        )
                            ->filter(fn ($item) => is_array($item))
                            ->filter(fn (array $item) => in_array(
                                $item['state'] ?? null,
                                ['error', 'failure', 'pending'],
                                true,
                            ))
                            ->take(30)
                            ->map(fn (array $item) => [
                                'id' => (int) ($item['id'] ?? 0),
                                'state' => mb_substr(
                                    (string) ($item['state'] ?? ''),
                                    0,
                                    50,
                                ),
                                'context' => mb_substr(
                                    (string) ($item['context'] ?? ''),
                                    0,
                                    255,
                                ),
                                'description' =>
                                    $this->boundedProviderText(
                                        $item['description'] ?? null,
                                        800,
                                    ),
                                'updated_at' => $this->dateValue(
                                    $item['updated_at'] ?? null,
                                ),
                                'url' => $this->githubUrl(
                                    $item['target_url'] ?? null,
                                ),
                            ])
                            ->values()
                            ->all();
                    }
                } else {
                    $warnings[] = 'GitHub Commit Status詳細を取得できませんでした。';
                }
            }
        }

        return [
            'version' => 1,
            'source' => 'github_app_rest_explicit_triage',
            'transient' => true,
            'mode' => $mode,
            'repo_full_name' => $repoFullName,
            'fetched_at' => now()->toIso8601String(),
            'pull_request' => [
                'number' => $pullRequestNumber,
                'title' => mb_substr(
                    (string) ($pull['title'] ?? ''),
                    0,
                    500,
                ),
                'state' => mb_substr(
                    (string) ($pull['state'] ?? ''),
                    0,
                    50,
                ),
                'draft' => (bool) ($pull['draft'] ?? false),
                'head_sha' => $headSha,
                'head_ref' => mb_substr(
                    (string) data_get($pull, 'head.ref', ''),
                    0,
                    255,
                ),
                'base_ref' => mb_substr(
                    (string) data_get($pull, 'base.ref', ''),
                    0,
                    255,
                ),
                'url' => $this->githubUrl(
                    $pull['html_url'] ?? null,
                ),
            ],
            'review' => $reviewDetails,
            'ci' => $ciDetails,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Read one Issue through the installed GitHub App without importing
     * unbounded body/comment text into Canovia Intelligence.
     *
     * @return array<string,mixed>
     */
    public function inspectIssue(
        string $repoFullName,
        int $issueNumber,
    ): array {
        if ($issueNumber <= 0) {
            throw new RuntimeException('Issue番号を確認できませんでした。');
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'issues',
            'Issues',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $response = $client->get(
            '/repos/'.$context['repo_path'].'/issues/'.$issueNumber,
        );

        if ($response->status() === 404) {
            throw new RuntimeException('対象IssueをGitHubから確認できませんでした。');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Issueの現在状態をGitHubから取得できませんでした。');
        }

        $issue = $response->json();
        if (
            ! is_array($issue)
            || array_key_exists('pull_request', $issue)
            || (int) ($issue['number'] ?? 0) !== $issueNumber
        ) {
            throw new RuntimeException('GitHubから返されたIssue情報を確認できませんでした。');
        }

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => $repoFullName,
            'installation_id' => $context['installation_id'],
            'fetched_at' => now()->toIso8601String(),
            'issue' => [
                'number' => $issueNumber,
                'state' => mb_substr((string) ($issue['state'] ?? ''), 0, 50),
                'state_reason' => filled($issue['state_reason'] ?? null)
                    ? mb_substr((string) $issue['state_reason'], 0, 80)
                    : null,
                'locked' => (bool) ($issue['locked'] ?? false),
                'assignee_count' => collect((array) ($issue['assignees'] ?? []))
                    ->filter(fn ($item) => is_array($item))
                    ->count(),
                'updated_at' => $this->dateValue($issue['updated_at'] ?? null),
                'closed_at' => $this->dateValue($issue['closed_at'] ?? null),
                'url' => $this->githubUrl($issue['html_url'] ?? null),
            ],
        ];
    }

    /**
     * Read one Branch head through the installed GitHub App.
     *
     * @return array<string,mixed>
     */
    public function inspectBranch(
        string $repoFullName,
        string $branch,
    ): array {
        $branch = trim($branch);
        if (
            $branch === ''
            || mb_strlen($branch) > 255
            || str_contains($branch, "\0")
        ) {
            throw new RuntimeException('Branch名を確認できませんでした。');
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $response = $client->get(
            '/repos/'.$context['repo_path'].'/branches/'.rawurlencode($branch),
        );

        if ($response->status() === 404) {
            throw new RuntimeException('対象BranchをGitHubから確認できませんでした。');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Branchの現在状態をGitHubから取得できませんでした。');
        }

        $branchData = $response->json();
        if (! is_array($branchData)) {
            throw new RuntimeException('GitHub Branch情報を読み取れませんでした。');
        }

        $actualName = trim((string) ($branchData['name'] ?? ''));
        $headSha = mb_strtolower(mb_substr(
            trim((string) data_get($branchData, 'commit.sha', '')),
            0,
            64,
        ));

        if ($actualName === '' || $headSha === '') {
            throw new RuntimeException('GitHub Branchのheadを確認できませんでした。');
        }

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => $repoFullName,
            'installation_id' => $context['installation_id'],
            'fetched_at' => now()->toIso8601String(),
            'branch' => [
                'name' => mb_substr($actualName, 0, 255),
                'head_sha' => $headSha,
                'protected' => (bool) ($branchData['protected'] ?? false),
            ],
        ];
    }

    /**
     * Read one Commit by SHA through the installed GitHub App.
     *
     * Commit message / file diff are deliberately excluded from the returned
     * Intelligence-facing snapshot.
     *
     * @return array<string,mixed>
     */
    public function inspectCommit(
        string $repoFullName,
        string $commitSha,
    ): array {
        $commitSha = mb_strtolower(trim($commitSha));
        if (! preg_match('/^[a-f0-9]{7,64}$/', $commitSha)) {
            throw new RuntimeException('Commit SHAを確認できませんでした。');
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $response = $client->get(
            '/repos/'.$context['repo_path'].'/commits/'.$commitSha,
        );

        if ($response->status() === 404) {
            throw new RuntimeException('対象CommitをGitHubから確認できませんでした。');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Commitの現在状態をGitHubから取得できませんでした。');
        }

        $commit = $response->json();
        if (! is_array($commit)) {
            throw new RuntimeException('GitHub Commit情報を読み取れませんでした。');
        }

        $actualSha = mb_strtolower(mb_substr(
            trim((string) ($commit['sha'] ?? '')),
            0,
            64,
        ));

        if (
            $actualSha === ''
            || ! str_starts_with($actualSha, $commitSha)
        ) {
            throw new RuntimeException('GitHubから返されたCommit SHAが一致しません。');
        }

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => $repoFullName,
            'installation_id' => $context['installation_id'],
            'fetched_at' => now()->toIso8601String(),
            'commit' => [
                'sha' => $actualSha,
                'authored_at' => $this->dateValue(
                    data_get($commit, 'commit.author.date'),
                ),
                'committed_at' => $this->dateValue(
                    data_get($commit, 'commit.committer.date'),
                ),
                'parent_count' => collect((array) ($commit['parents'] ?? []))
                    ->filter(fn ($item) => is_array($item))
                    ->count(),
                'verified' => (bool) data_get(
                    $commit,
                    'commit.verification.verified',
                    false,
                ),
                'url' => $this->githubUrl($commit['html_url'] ?? null),
            ],
        ];
    }

    /**
     * Read one GitHub Deployment and its latest status.
     *
     * @return array<string,mixed>
     */
    public function inspectDeployment(
        string $repoFullName,
        int $deploymentId,
    ): array {
        if ($deploymentId <= 0) {
            throw new RuntimeException('Deployment IDを確認できませんでした。');
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'deployments',
            'Deployments',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $response = $client->get(
            '/repos/'.$context['repo_path'].'/deployments/'.$deploymentId,
        );

        if ($response->status() === 404) {
            throw new RuntimeException('対象DeploymentをGitHubから確認できませんでした。');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Deploymentの現在状態をGitHubから取得できませんでした。');
        }

        $deployment = $response->json();
        if (
            ! is_array($deployment)
            || (int) ($deployment['id'] ?? 0) !== $deploymentId
        ) {
            throw new RuntimeException('GitHub Deployment情報を読み取れませんでした。');
        }

        $statusesResponse = $client->get(
            '/repos/'.$context['repo_path'].'/deployments/'.$deploymentId.'/statuses',
            ['per_page' => 10],
        );

        $statuses = $statusesResponse->successful()
            ? collect($statusesResponse->json())
                ->filter(fn ($item) => is_array($item))
                ->take(10)
                ->map(fn (array $item) => [
                    'id' => (int) ($item['id'] ?? 0),
                    'state' => mb_substr((string) ($item['state'] ?? ''), 0, 80),
                    'created_at' => $this->dateValue($item['created_at'] ?? null),
                    'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                ])
                ->filter(fn (array $item) => $item['id'] > 0 && $item['state'] !== '')
                ->values()
            : collect();

        $latest = $statuses
            ->sortByDesc(fn (array $item) => (
                (string) ($item['updated_at'] ?? $item['created_at'] ?? '')
            ).':'.str_pad((string) $item['id'], 20, '0', STR_PAD_LEFT))
            ->first();

        return [
            'version' => 1,
            'source' => 'github_app_rest',
            'repo_full_name' => $repoFullName,
            'installation_id' => $context['installation_id'],
            'fetched_at' => now()->toIso8601String(),
            'deployment' => [
                'id' => $deploymentId,
                'sha' => mb_strtolower(mb_substr(
                    trim((string) ($deployment['sha'] ?? '')),
                    0,
                    64,
                )),
                'ref' => mb_substr(
                    trim((string) ($deployment['ref'] ?? '')),
                    0,
                    255,
                ),
                'environment' => mb_substr(
                    trim((string) ($deployment['environment'] ?? '')),
                    0,
                    255,
                ),
                'production_environment' => (bool) (
                    $deployment['production_environment'] ?? false
                ),
                'transient_environment' => (bool) (
                    $deployment['transient_environment'] ?? false
                ),
                'created_at' => $this->dateValue(
                    $deployment['created_at'] ?? null,
                ),
                'updated_at' => $this->dateValue(
                    $deployment['updated_at'] ?? null,
                ),
                'latest_status' => is_array($latest)
                    ? $latest['state']
                    : null,
                'latest_status_at' => is_array($latest)
                    ? ($latest['updated_at'] ?? $latest['created_at'] ?? null)
                    : null,
            ],
        ];
    }

    /**
     * Read the current target file state through the same GitHub App boundary
     * used for write, without creating a branch or commit.
     *
     * @return array<string,mixed>
     */
    public function previewFileChange(
        string $repoFullName,
        string $filePath,
        string $content,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);
        $filePath = $this->normalizeFilePath($filePath);

        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new RuntimeException('1回に反映できるファイルは200KBまでです。');
        }

        $appJwt = $this->appJwt();
        $installationResponse = $this->appClient($appJwt)
            ->get('/repos/'.$repoPath.'/installation');

        if ($installationResponse->status() === 404) {
            throw new RuntimeException('Canovia GitHub AppがこのRepositoryに接続されていません。Repository管理者に接続してもらってください。');
        }

        if (! $installationResponse->successful()) {
            throw new RuntimeException('GitHub AppのRepository接続を確認できませんでした。');
        }

        $installationId = (int) data_get($installationResponse->json(), 'id', 0);
        if ($installationId <= 0) {
            throw new RuntimeException('GitHub App installationを確認できませんでした。');
        }

        $tokenResponse = $this->appClient($appJwt)
            ->post('/app/installations/'.$installationId.'/access_tokens');

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('GitHub Appの一時Access Tokenを発行できませんでした。');
        }

        $token = trim((string) data_get($tokenResponse->json(), 'token', ''));
        $permissions = (array) data_get($tokenResponse->json(), 'permissions', []);

        if ($token === '') {
            throw new RuntimeException('GitHub Appの一時Access Tokenを取得できませんでした。');
        }

        if (($permissions['contents'] ?? null) !== 'write' || ($permissions['pull_requests'] ?? null) !== 'write') {
            throw new RuntimeException('GitHub AppにContents / Pull Requestsのwrite権限がありません。');
        }

        $client = $this->installationClient($token);
        $repositoryResponse = $client->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException('GitHub AppからRepository情報を取得できませんでした。');
        }

        $repository = $repositoryResponse->json();
        if (! is_array($repository)) {
            throw new RuntimeException('GitHub Repository情報を読み取れませんでした。');
        }

        if ((bool) ($repository['archived'] ?? false)) {
            throw new RuntimeException('Archived Repositoryには変更を作成できません。');
        }

        $baseBranch = trim((string) ($repository['default_branch'] ?? ''));
        if ($baseBranch === '') {
            throw new RuntimeException('Repositoryのdefault branchを確認できませんでした。');
        }

        $encodedFilePath = $this->encodePath($filePath);
        $existingFileResponse = $client->get('/repos/'.$repoPath.'/contents/'.$encodedFilePath, [
            'ref' => $baseBranch,
        ]);

        $existingFileSha = null;
        $currentBytes = 0;
        $currentContentAvailable = false;

        if ($existingFileResponse->successful()) {
            $existingFile = $existingFileResponse->json();
            if (! is_array($existingFile) || ($existingFile['type'] ?? null) !== 'file') {
                throw new RuntimeException('指定されたpathは編集可能なファイルではありません。');
            }

            $existingFileSha = trim((string) ($existingFile['sha'] ?? ''));
            if ($existingFileSha === '') {
                throw new RuntimeException('既存ファイルのSHAを確認できませんでした。');
            }

            $currentBytes = max(0, (int) ($existingFile['size'] ?? 0));

            if (($existingFile['encoding'] ?? null) === 'base64' && is_string($existingFile['content'] ?? null)) {
                $currentContent = base64_decode(
                    preg_replace('/\\s+/', '', (string) $existingFile['content']) ?: '',
                    true,
                );

                if (is_string($currentContent)) {
                    $currentContentAvailable = true;
                    $currentBytes = strlen($currentContent);

                    if (hash_equals(hash('sha256', $currentContent), hash('sha256', $content))) {
                        throw new RuntimeException('指定したファイル内容は現在のdefault branchと同じです。変更候補は作成していません。');
                    }
                }
            }
        } elseif ($existingFileResponse->status() !== 404) {
            throw new RuntimeException('変更対象ファイルの現在状態を確認できませんでした。');
        }

        return [
            'repo_full_name' => (string) ($repository['full_name'] ?? $repoFullName),
            'installation_id' => $installationId,
            'base_branch' => $baseBranch,
            'file_path' => $filePath,
            'file_action' => $existingFileSha === null ? 'created' : 'updated',
            'expected_file_sha' => $existingFileSha,
            'current_bytes' => $currentBytes,
            'proposed_bytes' => strlen($content),
            'current_content_available' => $currentContentAvailable,
            'previewed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Create a review-only GitHub change using an installed GitHub App.
     *
     * The adapter can create a branch, create/update one text file and open a
     * pull request. It intentionally cannot merge, force-push, delete branches
     * or write directly to the default branch.
     *
     * @return array<string,mixed>
     */
    public function proposeFileChange(
        string $repoFullName,
        string $filePath,
        string $content,
        string $commitMessage,
        string $pullRequestTitle,
        ?string $pullRequestBody = null,
        ?string $expectedFileSha = null,
        bool $enforceExpectedFileState = false,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);
        $filePath = $this->normalizeFilePath($filePath);

        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new RuntimeException('1回に反映できるファイルは200KBまでです。');
        }

        $commitMessage = trim($commitMessage);
        $pullRequestTitle = trim($pullRequestTitle);
        if ($commitMessage === '' || $pullRequestTitle === '') {
            throw new RuntimeException('Commit messageとレビュー用タイトルを入力してください。');
        }

        $appJwt = $this->appJwt();
        $installationResponse = $this->appClient($appJwt)
            ->get('/repos/'.$repoPath.'/installation');

        if ($installationResponse->status() === 404) {
            throw new RuntimeException('Canovia GitHub AppがこのRepositoryに接続されていません。Repository管理者に接続してもらってください。');
        }

        if (! $installationResponse->successful()) {
            throw new RuntimeException('GitHub AppのRepository接続を確認できませんでした。');
        }

        $installationId = (int) data_get($installationResponse->json(), 'id', 0);
        if ($installationId <= 0) {
            throw new RuntimeException('GitHub App installationを確認できませんでした。');
        }

        $tokenResponse = $this->appClient($appJwt)
            ->post('/app/installations/'.$installationId.'/access_tokens');

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('GitHub Appの一時Access Tokenを発行できませんでした。');
        }

        $token = trim((string) data_get($tokenResponse->json(), 'token', ''));
        $permissions = (array) data_get($tokenResponse->json(), 'permissions', []);

        if ($token === '') {
            throw new RuntimeException('GitHub Appの一時Access Tokenを取得できませんでした。');
        }

        if (($permissions['contents'] ?? null) !== 'write' || ($permissions['pull_requests'] ?? null) !== 'write') {
            throw new RuntimeException('GitHub AppにContents / Pull Requestsのwrite権限がありません。');
        }

        $client = $this->installationClient($token);
        $repositoryResponse = $client->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException('GitHub AppからRepository情報を取得できませんでした。');
        }

        $repository = $repositoryResponse->json();
        if (! is_array($repository)) {
            throw new RuntimeException('GitHub Repository情報を読み取れませんでした。');
        }

        if ((bool) ($repository['archived'] ?? false)) {
            throw new RuntimeException('Archived Repositoryには変更を作成できません。');
        }

        $baseBranch = trim((string) ($repository['default_branch'] ?? ''));
        if ($baseBranch === '') {
            throw new RuntimeException('Repositoryのdefault branchを確認できませんでした。');
        }

        $baseRefResponse = $client->get('/repos/'.$repoPath.'/git/ref/heads/'.rawurlencode($baseBranch));
        if (! $baseRefResponse->successful()) {
            throw new RuntimeException('Default branchの現在位置を取得できませんでした。');
        }

        $baseSha = trim((string) data_get($baseRefResponse->json(), 'object.sha', ''));
        if ($baseSha === '') {
            throw new RuntimeException('Default branchのcommit SHAを確認できませんでした。');
        }

        $encodedFilePath = $this->encodePath($filePath);
        $existingFileResponse = $client->get('/repos/'.$repoPath.'/contents/'.$encodedFilePath, [
            'ref' => $baseBranch,
        ]);

        $existingFileSha = null;
        if ($existingFileResponse->successful()) {
            $existingFile = $existingFileResponse->json();
            if (! is_array($existingFile) || ($existingFile['type'] ?? null) !== 'file') {
                throw new RuntimeException('指定されたpathは編集可能なファイルではありません。');
            }
            $existingFileSha = trim((string) ($existingFile['sha'] ?? ''));
            if ($existingFileSha === '') {
                throw new RuntimeException('既存ファイルのSHAを確認できませんでした。');
            }

            if (($existingFile['encoding'] ?? null) === 'base64' && is_string($existingFile['content'] ?? null)) {
                $currentContent = base64_decode(
                    preg_replace('/\\s+/', '', (string) $existingFile['content']) ?: '',
                    true,
                );

                if (is_string($currentContent) && hash_equals(hash('sha256', $currentContent), hash('sha256', $content))) {
                    throw new RuntimeException('指定したファイル内容は現在のdefault branchと同じです。変更は作成していません。');
                }
            }
        } elseif ($existingFileResponse->status() !== 404) {
            throw new RuntimeException('変更対象ファイルの現在状態を確認できませんでした。');
        }

        if ($enforceExpectedFileState) {
            if ($expectedFileSha === null && $existingFileSha !== null) {
                throw new RuntimeException('確認後に対象pathへファイルが作成されています。現在状態から変更候補を作り直してください。');
            }

            if ($expectedFileSha !== null && $existingFileSha === null) {
                throw new RuntimeException('確認後に対象ファイルが削除されています。現在状態から変更候補を作り直してください。');
            }

            if (
                $expectedFileSha !== null
                && $existingFileSha !== null
                && ! hash_equals($expectedFileSha, $existingFileSha)
            ) {
                throw new RuntimeException('確認後に対象ファイルが更新されています。上書きを避けるため変更候補を作り直してください。');
            }
        }

        $branchName = $this->branchName();
        $branchResponse = $client->post('/repos/'.$repoPath.'/git/refs', [
            'ref' => 'refs/heads/'.$branchName,
            'sha' => $baseSha,
        ]);

        if (! $branchResponse->successful()) {
            throw new RuntimeException('レビュー用Branchを作成できませんでした。時間を置いて再試行してください。');
        }

        $filePayload = [
            'message' => mb_substr($commitMessage, 0, 240),
            'content' => base64_encode($content),
            'branch' => $branchName,
        ];
        if ($existingFileSha !== null) {
            $filePayload['sha'] = $existingFileSha;
        }

        $fileResponse = $client->put('/repos/'.$repoPath.'/contents/'.$encodedFilePath, $filePayload);
        if (! $fileResponse->successful()) {
            throw new RuntimeException('ファイル反映に失敗しました。レビュー用Branch '.$branchName.' が残っている可能性があります。');
        }

        $commitSha = trim((string) data_get($fileResponse->json(), 'commit.sha', ''));
        if ($commitSha === '') {
            throw new RuntimeException('Commitは作成されましたが、Commit SHAを確認できませんでした。Branch '.$branchName.' をRepositoryで確認してください。');
        }

        $body = trim((string) $pullRequestBody);
        $body = $body === ''
            ? "Canoviaからレビュー用に作成された変更です。\n\nmainへ直接pushせず、確認後にGitHub上でmergeしてください。"
            : mb_substr($body, 0, 20_000);

        $pullRequestResponse = $client->post('/repos/'.$repoPath.'/pulls', [
            'title' => mb_substr($pullRequestTitle, 0, 240),
            'head' => $branchName,
            'base' => $baseBranch,
            'body' => $body,
        ]);

        if (! $pullRequestResponse->successful()) {
            throw new RuntimeException('変更はBranchへ反映されましたがPull Requestを作成できませんでした。Branch '.$branchName.' をRepositoryで確認してください。');
        }

        $pullRequest = $pullRequestResponse->json();
        $pullRequestNumber = (int) data_get($pullRequest, 'number', 0);
        $pullRequestUrl = $this->githubUrl(data_get($pullRequest, 'html_url'));

        if ($pullRequestNumber <= 0 || $pullRequestUrl === null) {
            throw new RuntimeException('Pull Requestは作成されましたが結果を確認できませんでした。Repositoryを確認してください。');
        }

        return [
            'repo_full_name' => (string) ($repository['full_name'] ?? $repoFullName),
            'installation_id' => $installationId,
            'base_branch' => $baseBranch,
            'branch' => $branchName,
            'file_path' => $filePath,
            'file_action' => $existingFileSha === null ? 'created' : 'updated',
            'commit_sha' => $commitSha,
            'pull_request' => [
                'number' => $pullRequestNumber,
                'title' => mb_substr((string) data_get($pullRequest, 'title', $pullRequestTitle), 0, 500),
                'url' => $pullRequestUrl,
                'state' => mb_substr((string) data_get($pullRequest, 'state', 'open'), 0, 50),
            ],
            'created_at' => now()->toIso8601String(),
            'executed_by' => 'github_app',
        ];
    }

    /**
     * Read a bounded Repository snapshot through the installed GitHub App.
     * This is the authoritative path for both public and private repositories.
     *
     * @return array<string,mixed>
     */
    /**
     * Read a bounded repository tree for the explicit Developer Repository
     * surface. Source contents are never fetched.
     *
     * @return array<string,mixed>
     */
    public function inspectRepositoryTree(
        string $repoFullName,
        int $limit = 320,
    ): array {
        $limit = max(50, min(500, $limit));

        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $repoPath = (string) $context['repo_path'];

        $repositoryResponse = $client->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException('GitHub App経由でRepository情報を取得できませんでした。');
        }

        $repository = $repositoryResponse->json();
        if (! is_array($repository)) {
            throw new RuntimeException('GitHub Repository情報を読み取れませんでした。');
        }

        $defaultBranch = trim((string) (
            $repository['default_branch']
            ?? ''
        ));
        if ($defaultBranch === '') {
            throw new RuntimeException('Repositoryのdefault branchを確認できませんでした。');
        }

        $commitResponse = $client->get(
            '/repos/'.$repoPath.'/commits/'.rawurlencode($defaultBranch),
        );

        if (! $commitResponse->successful()) {
            throw new RuntimeException('default branchのtreeを確認できませんでした。');
        }

        $commit = $commitResponse->json();
        $treeSha = trim((string) data_get(
            is_array($commit) ? $commit : [],
            'commit.tree.sha',
            '',
        ));
        $headSha = trim((string) data_get(
            is_array($commit) ? $commit : [],
            'sha',
            '',
        ));

        if ($treeSha === '') {
            throw new RuntimeException('Repository tree SHAを確認できませんでした。');
        }

        $treeResponse = $client->get(
            '/repos/'.$repoPath.'/git/trees/'.rawurlencode($treeSha),
            ['recursive' => 1],
        );

        if (! $treeResponse->successful()) {
            throw new RuntimeException('Repository directory構成を取得できませんでした。');
        }

        $treePayload = $treeResponse->json();
        if (! is_array($treePayload)) {
            throw new RuntimeException('Repository directory構成を読み取れませんでした。');
        }

        $rawTree = collect((array) ($treePayload['tree'] ?? []))
            ->filter(fn ($item) => is_array($item))
            ->filter(fn (array $item) => in_array(
                $item['type'] ?? null,
                ['tree', 'blob'],
                true,
            ))
            ->map(function (array $item) use (
                $repoFullName,
                $defaultBranch,
            ) {
                $path = trim((string) ($item['path'] ?? ''), '/');
                if ($path === '' || mb_strlen($path) > 1200) {
                    return null;
                }

                $type = (string) ($item['type'] ?? 'blob');
                $depth = min(12, substr_count($path, '/'));
                $url = 'https://github.com/'.$repoFullName
                    .'/'.($type === 'tree' ? 'tree' : 'blob')
                    .'/'.rawurlencode($defaultBranch)
                    .'/'.implode(
                        '/',
                        array_map(
                            'rawurlencode',
                            explode('/', $path),
                        ),
                    );

                return [
                    'path' => $path,
                    'name' => mb_substr(
                        (string) basename($path),
                        0,
                        255,
                    ),
                    'type' => $type === 'tree'
                        ? 'directory'
                        : 'file',
                    'depth' => $depth,
                    'size' => $type === 'blob' && is_numeric(
                        $item['size'] ?? null,
                    )
                        ? max(0, (int) $item['size'])
                        : null,
                    'url' => $url,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $leftDir = dirname($left['path']);
                $rightDir = dirname($right['path']);

                if ($leftDir !== $rightDir) {
                    return strcasecmp($leftDir, $rightDir);
                }

                $typeOrder = ($left['type'] === 'directory' ? 0 : 1)
                    <=> ($right['type'] === 'directory' ? 0 : 1);

                return $typeOrder !== 0
                    ? $typeOrder
                    : strcasecmp($left['name'], $right['name']);
            })
            ->values();

        $providerTruncated = (bool) ($treePayload['truncated'] ?? false);
        $visible = $rawTree->take($limit)->values();

        return [
            'version' => 1,
            'source' => 'github_app_git_tree',
            'repo_full_name' => $repoFullName,
            'repository_url' => $this->githubUrl(
                $repository['html_url'] ?? null,
            ),
            'visibility' => mb_substr(
                (string) (
                    $repository['visibility']
                    ?? ((bool) ($repository['private'] ?? false)
                        ? 'private'
                        : 'public')
                ),
                0,
                50,
            ),
            'default_branch' => mb_substr(
                $defaultBranch,
                0,
                255,
            ),
            'head_sha' => mb_substr($headSha, 0, 64),
            'fetched_at' => now()->toIso8601String(),
            'entries' => $visible->all(),
            'entry_count' => $visible->count(),
            'total_observed_count' => $rawTree->count(),
            'truncated' => $providerTruncated
                || $rawTree->count() > $limit,
            'limit' => $limit,
        ];
    }

    public function inspectRepositorySnapshot(string $repoFullName): array
    {
        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $permissions = (array) $context['permissions'];
        $repoPath = (string) $context['repo_path'];

        $repositoryResponse = $client->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException('GitHub App経由でRepository情報を取得できませんでした。');
        }

        $repository = $repositoryResponse->json();
        if (! is_array($repository)) {
            throw new RuntimeException('GitHub Repository情報を読み取れませんでした。');
        }

        $warnings = [];

        $branchesResponse = $client->get('/repos/'.$repoPath.'/branches', [
            'per_page' => 12,
        ]);

        $pullsResponse = null;
        if (in_array(($permissions['pull_requests'] ?? null), ['read', 'write'], true)) {
            $pullsResponse = $client->get('/repos/'.$repoPath.'/pulls', [
                'state' => 'open',
                'sort' => 'updated',
                'direction' => 'desc',
                'per_page' => 12,
            ]);
        } else {
            $warnings[] = 'Pull RequestsはGitHub Appのread権限がないため取得していません。';
        }

        $issuesResponse = null;
        if (in_array(($permissions['issues'] ?? null), ['read', 'write'], true)) {
            $issuesResponse = $client->get('/repos/'.$repoPath.'/issues', [
                'state' => 'open',
                'sort' => 'updated',
                'direction' => 'desc',
                'per_page' => 20,
            ]);
        } else {
            $warnings[] = 'IssuesはGitHub Appのread権限がないため取得していません。';
        }

        $actionsResponse = null;
        if (in_array(($permissions['actions'] ?? null), ['read', 'write'], true)) {
            $actionsResponse = $client->get('/repos/'.$repoPath.'/actions/runs', [
                'per_page' => 10,
            ]);
        } else {
            $warnings[] = 'ActionsはGitHub Appのread権限がないため取得していません。';
        }

        if (! $branchesResponse->successful()) {
            $warnings[] = 'branchesは取得できませんでした。';
        }
        if ($pullsResponse && ! $pullsResponse->successful()) {
            $warnings[] = 'pull requestsは取得できませんでした。';
        }
        if ($issuesResponse && ! $issuesResponse->successful()) {
            $warnings[] = 'issuesは取得できませんでした。';
        }
        if ($actionsResponse && ! $actionsResponse->successful()) {
            $warnings[] = 'actionsは取得できませんでした。';
        }

        $branches = $branchesResponse->successful()
            ? collect((array) $branchesResponse->json())
                ->filter(fn ($item) => is_array($item))
                ->take(12)
                ->map(fn (array $item) => [
                    'name' => mb_substr((string) ($item['name'] ?? ''), 0, 255),
                    'protected' => (bool) ($item['protected'] ?? false),
                    'sha' => mb_substr((string) data_get($item, 'commit.sha', ''), 0, 64),
                ])
                ->filter(fn (array $item) => $item['name'] !== '')
                ->values()
                ->all()
            : [];

        $pullRequests = $pullsResponse && $pullsResponse->successful()
            ? collect((array) $pullsResponse->json())
                ->filter(fn ($item) => is_array($item))
                ->take(12)
                ->map(fn (array $item) => [
                    'number' => (int) ($item['number'] ?? 0),
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                    'draft' => (bool) ($item['draft'] ?? false),
                    'head' => mb_substr((string) data_get($item, 'head.ref', ''), 0, 255),
                    'base' => mb_substr((string) data_get($item, 'base.ref', ''), 0, 255),
                    'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['number'] > 0)
                ->values()
                ->all()
            : [];

        $issues = $issuesResponse && $issuesResponse->successful()
            ? collect((array) $issuesResponse->json())
                ->filter(fn ($item) =>
                    is_array($item) && ! array_key_exists('pull_request', $item)
                )
                ->take(12)
                ->map(fn (array $item) => [
                    'number' => (int) ($item['number'] ?? 0),
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                    'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['number'] > 0)
                ->values()
                ->all()
            : [];

        $runData = $actionsResponse && $actionsResponse->successful()
            ? $actionsResponse->json()
            : [];
        $runs = is_array($runData)
            ? collect($runData['workflow_runs'] ?? [])
                ->filter(fn ($item) => is_array($item))
                ->take(10)
                ->map(fn (array $item) => [
                    'id' => (int) ($item['id'] ?? 0),
                    'name' => mb_substr((string) ($item['name'] ?? ''), 0, 255),
                    'event' => mb_substr((string) ($item['event'] ?? ''), 0, 100),
                    'status' => mb_substr((string) ($item['status'] ?? ''), 0, 100),
                    'conclusion' => filled($item['conclusion'] ?? null)
                        ? mb_substr((string) $item['conclusion'], 0, 100)
                        : null,
                    'branch' => mb_substr((string) ($item['head_branch'] ?? ''), 0, 255),
                    'updated_at' => $this->dateValue($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['id'] > 0)
                ->values()
                ->all()
            : [];

        return [
            'version' => 2,
            'source' => 'github_app_rest',
            'repo_full_name' => (string) ($repository['full_name'] ?? $repoFullName),
            'fetched_at' => now()->toIso8601String(),
            'rate_limit_remaining' => $this->integerHeader(
                $repositoryResponse->header('X-RateLimit-Remaining'),
            ),
            'repository' => [
                'description' => filled($repository['description'] ?? null)
                    ? mb_substr((string) $repository['description'], 0, 1200)
                    : null,
                'default_branch' => mb_substr((string) ($repository['default_branch'] ?? ''), 0, 255),
                'language' => filled($repository['language'] ?? null)
                    ? mb_substr((string) $repository['language'], 0, 100)
                    : null,
                'visibility' => mb_substr((string) ($repository['visibility'] ?? ((bool) ($repository['private'] ?? false) ? 'private' : 'public')), 0, 50),
                'archived' => (bool) ($repository['archived'] ?? false),
                'fork' => (bool) ($repository['fork'] ?? false),
                'stars' => max(0, (int) ($repository['stargazers_count'] ?? 0)),
                'forks' => max(0, (int) ($repository['forks_count'] ?? 0)),
                'open_issues_count' => max(0, (int) ($repository['open_issues_count'] ?? 0)),
                'updated_at' => $this->dateValue($repository['updated_at'] ?? null),
                'pushed_at' => $this->dateValue($repository['pushed_at'] ?? null),
                'url' => $this->githubUrl($repository['html_url'] ?? null),
            ],
            'branches' => $branches,
            'pull_requests' => $pullRequests,
            'issues' => $issues,
            'actions_runs' => $runs,
            'limits' => [
                'branches' => 12,
                'pull_requests' => 12,
                'issues' => 12,
                'actions_runs' => 10,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Return a bounded initial Development activity set for a newly connected
     * Repository. Provider text is deliberately limited to titles; bodies,
     * commit messages, diffs and source code are never returned.
     *
     * @return array<int,array<string,mixed>>
     */
    public function bootstrapDevelopmentActivity(
        string $repoFullName,
        int $limit = 20,
    ): array {
        $limit = max(1, min(20, $limit));
        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $permissions = (array) $context['permissions'];
        $repoPath = (string) $context['repo_path'];
        $facts = collect();

        $repositoryResponse = $client->get('/repos/'.$repoPath);
        $repository = $repositoryResponse->successful()
            && is_array($repositoryResponse->json())
                ? $repositoryResponse->json()
                : [];
        $defaultBranch = mb_substr(
            trim((string) ($repository['default_branch'] ?? '')),
            0,
            255,
        );

        if (in_array(
            $permissions['pull_requests'] ?? null,
            ['read', 'write'],
            true,
        )) {
            $pullsResponse = $client->get('/repos/'.$repoPath.'/pulls', [
                'state' => 'open',
                'sort' => 'updated',
                'direction' => 'desc',
                'per_page' => $limit,
            ]);

            if ($pullsResponse->successful()) {
                collect((array) $pullsResponse->json())
                    ->filter(fn ($pull) => is_array($pull))
                    ->take($limit)
                    ->each(function (array $pull) use ($facts) {
                        $number = (int) ($pull['number'] ?? 0);
                        if ($number <= 0) {
                            return;
                        }

                        $state = (string) ($pull['state'] ?? 'open');
                        $facts->push([
                            'kind' => 'pull_request',
                            'identity' => (string) $number,
                            'provider_number' => $number,
                            'title' => mb_substr(
                                (string) ($pull['title'] ?? ''),
                                0,
                                255,
                            ),
                            'state' => (bool) ($pull['draft'] ?? false)
                                && $state === 'open'
                                    ? 'draft'
                                    : mb_substr($state, 0, 64),
                            'ref' => mb_substr(
                                (string) data_get($pull, 'head.ref', ''),
                                0,
                                255,
                            ),
                            'sha' => mb_strtolower(mb_substr(
                                (string) data_get($pull, 'head.sha', ''),
                                0,
                                64,
                            )),
                            'url' => $this->githubUrl(
                                $pull['html_url'] ?? null,
                            ),
                            'occurred_at' => $this->dateValue(
                                $pull['updated_at'] ?? null,
                            ),
                        ]);
                    });
            }
        }

        if (in_array(
            $permissions['issues'] ?? null,
            ['read', 'write'],
            true,
        )) {
            $issuesResponse = $client->get('/repos/'.$repoPath.'/issues', [
                'state' => 'open',
                'sort' => 'updated',
                'direction' => 'desc',
                'per_page' => $limit,
            ]);

            if ($issuesResponse->successful()) {
                collect((array) $issuesResponse->json())
                    ->filter(fn ($issue) =>
                        is_array($issue)
                        && ! array_key_exists('pull_request', $issue)
                    )
                    ->take($limit)
                    ->each(function (array $issue) use ($facts) {
                        $number = (int) ($issue['number'] ?? 0);
                        if ($number <= 0) {
                            return;
                        }

                        $facts->push([
                            'kind' => 'issue',
                            'identity' => (string) $number,
                            'provider_number' => $number,
                            'title' => mb_substr(
                                (string) ($issue['title'] ?? ''),
                                0,
                                255,
                            ),
                            'state' => mb_substr(
                                (string) ($issue['state'] ?? 'open'),
                                0,
                                64,
                            ),
                            'url' => $this->githubUrl(
                                $issue['html_url'] ?? null,
                            ),
                            'occurred_at' => $this->dateValue(
                                $issue['updated_at'] ?? null,
                            ),
                        ]);
                    });
            }
        }

        if ($defaultBranch !== '') {
            $commitsResponse = $client->get('/repos/'.$repoPath.'/commits', [
                'sha' => $defaultBranch,
                'per_page' => $limit,
            ]);

            if ($commitsResponse->successful()) {
                $commits = collect((array) $commitsResponse->json())
                    ->filter(fn ($commit) => is_array($commit))
                    ->take($limit)
                    ->values();

                $headSha = mb_strtolower(mb_substr(
                    (string) data_get($commits->first(), 'sha', ''),
                    0,
                    64,
                ));

                if ($headSha !== '') {
                    $facts->push([
                        'kind' => 'branch',
                        'identity' => $defaultBranch,
                        'state' => 'active',
                        'ref' => $defaultBranch,
                        'sha' => $headSha,
                        'occurred_at' => now()->toIso8601String(),
                    ]);
                }

                $commits->each(function (array $commit) use (
                    $facts,
                    $defaultBranch,
                ) {
                    $sha = mb_strtolower(mb_substr(
                        (string) ($commit['sha'] ?? ''),
                        0,
                        64,
                    ));

                    if (! preg_match('/^[a-f0-9]{7,64}$/', $sha)) {
                        return;
                    }

                    $facts->push([
                        'kind' => 'commit',
                        'identity' => $sha,
                        'state' => 'observed',
                        'ref' => $defaultBranch,
                        'sha' => $sha,
                        'url' => $this->githubUrl(
                            $commit['html_url'] ?? null,
                        ),
                        'occurred_at' => $this->dateValue(
                            data_get($commit, 'commit.committer.date')
                            ?? data_get($commit, 'commit.author.date'),
                        ),
                    ]);
                });
            }
        }

        return $facts
            ->filter(fn ($fact) => is_array($fact))
            ->unique(fn (array $fact) =>
                (string) ($fact['kind'] ?? '')
                .'|'.(string) ($fact['identity'] ?? '')
            )
            ->take(61)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   repo_path:string,
     *   installation_id:int,
     *   permissions:array<string,mixed>,
     *   client:PendingRequest
     * }
     */
    /**
     * Narrow read-only GitHub App interface. Never returns an installation
     * token or HTTP client to callers; always pins content to a commit SHA.
     *
     * @return array{sha: string, content: string|null}
     */
    public function readDevelopmentRoadmapMarkdown(string $repoFullName): array
    {
        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );

        /** @var PendingRequest $client */
        $client = $context['client'];
        $repoPath = (string) $context['repo_path'];

        $repositoryResponse = $client->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException('Repository情報を取得できませんでした。');
        }

        $repository = $repositoryResponse->json();
        // GitHub App installation permission is not proof that the current
        // Canovia actor has GitHub membership in a private repository.
        // Fail closed until actor-scoped GitHub authorization is available.
        if (! is_array($repository)
            || ($repository['private'] ?? null) !== false
            || ($repository['visibility'] ?? null) !== 'public') {
            throw new RuntimeException('Private / Internal Repositoryのロードマップ閲覧には本人のGitHub権限確認が必要です。');
        }

        $branch = trim((string) ($repository['default_branch'] ?? ''));
        if ($branch === '' || strlen($branch) > 255) {
            throw new RuntimeException('Default branchを確認できませんでした。');
        }

        $headResponse = $client->get(
            '/repos/'.$repoPath.'/commits/'.rawurlencode($branch),
        );
        $head = $headResponse->successful() ? $headResponse->json() : null;
        $sha = is_array($head) ? (string) ($head['sha'] ?? '') : '';
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new RuntimeException('Roadmapの参照commitを確認できませんでした。');
        }

        $response = $client->get(
            '/repos/'.$repoPath.'/contents/docs/development/ROADMAP.md',
            ['ref' => $sha],
        );
        if ($response->status() === 404) {
            return ['sha' => $sha, 'content' => null];
        }
        if (! $response->successful()) {
            throw new RuntimeException('Roadmapを取得できませんでした。');
        }

        $file = $response->json();
        if (! is_array($file) || ($file['type'] ?? null) !== 'file'
            || ($file['encoding'] ?? null) !== 'base64'
            || ! is_string($file['content'] ?? null)
            || (int) ($file['size'] ?? -1) < 0
            || (int) ($file['size'] ?? -1) > self::MAX_CONTENT_BYTES) {
            throw new RuntimeException('Roadmapの形式またはサイズが不正です。');
        }

        $content = base64_decode(
            preg_replace('/\s+/', '', $file['content']) ?: '',
            true,
        );
        if (! is_string($content)
            || strlen($content) > self::MAX_CONTENT_BYTES
            || strlen($content) !== (int) $file['size']) {
            throw new RuntimeException('Roadmapを安全に読み取れませんでした。');
        }

        return ['sha' => $sha, 'content' => $content];
    }

    /**
     * Read a small, explicit set of PR references. No Plan/Task updates.
     * An installed GitHub App is NOT user authorization for private repos:
     * the Phase 2 reader fails closed on private/internal repositories.
     *
     * @param array<int,int> $numbers
     * @return array{signals:array<int,array<string,mixed>>, observed_at:string, warning:?string}
     */
    public function readDevelopmentRoadmapPullRequestSignals(
        string $repoFullName,
        array $numbers,
    ): array {
        $numbers = array_values(array_unique(array_filter(
            $numbers,
            fn ($number) => is_int($number) && $number > 0 && $number <= 999999,
        )));
        $numbers = array_slice($numbers, 0, 10);

        if ($numbers === []) {
            return ['signals' => [], 'observed_at' => now()->toIso8601String(), 'warning' => null];
        }

        $context = $this->developmentReadContext(
            $repoFullName,
            'contents',
            'Contents',
        );
        /** @var PendingRequest $client */
        $client = $context['client'];
        $repoPath = (string) $context['repo_path'];
        $permissions = (array) $context['permissions'];

        $repoResponse = $client->get('/repos/'.$repoPath);
        $repository = $repoResponse->successful() ? $repoResponse->json() : null;
        if (! is_array($repository)
            || ($repository['private'] ?? null) !== false
            || ($repository['visibility'] ?? null) !== 'public') {
            throw new RuntimeException(
                'Private / Internal RepositoryのPR検証には本人のGitHub権限確認が必要です。',
            );
        }

        $defaultBranch = (string) ($repository['default_branch'] ?? '');
        if ($defaultBranch === '' || strlen($defaultBranch) > 255) {
            throw new RuntimeException('GitHubのdefault branchを確認できませんでした。');
        }

        $signals = [];
        $canReadPulls = in_array($permissions['pull_requests'] ?? null, ['read', 'write'], true);
        $canReadChecks = in_array($permissions['checks'] ?? null, ['read', 'write'], true);
        $checkBudget = 4;

        foreach ($numbers as $number) {
            $signal = [
                'number' => $number,
                'url' => 'https://github.com/'.$repoFullName.'/pull/'.$number,
                'merge_state' => 'unknown',
                'merge_sha' => null,
                'ci_state' => 'unknown',
                'ci_sha' => null,
            ];

            if (! $canReadPulls) {
                $signals[$number] = $signal;
                continue;
            }

            $response = $client->get('/repos/'.$repoPath.'/pulls/'.$number);
            if ($response->status() === 404) {
                $signal['merge_state'] = 'missing';
                $signals[$number] = $signal;
                continue;
            }
            if (! $response->successful()) {
                $signals[$number] = $signal;
                continue;
            }

            $pr = $response->json();
            if (! is_array($pr) || (int) ($pr['number'] ?? 0) !== $number) {
                $signals[$number] = $signal;
                continue;
            }

            $merged = ($pr['merged'] ?? null) === true;
            $baseBranch = (string) data_get($pr, 'base.ref', '');
            $prState = (string) ($pr['state'] ?? '');
            $signal['merge_state'] = $merged
                ? ($baseBranch === $defaultBranch ? 'merged_default' : 'merged_other')
                : match ($prState) {
                    'open' => 'open',
                    'closed' => 'closed_unmerged',
                    default => 'unknown',
                };
            $mergeSha = (string) ($pr['merge_commit_sha'] ?? '');
            $signal['merge_sha'] = $merged && preg_match('/^[a-f0-9]{40}$/D', $mergeSha)
                ? $mergeSha
                : null;

            // CI applies to the exact PR head SHA, not to the merge commit,
            // deployed artifact or entire roadmap workstream.
            $headSha = (string) data_get($pr, 'head.sha', '');
            if ($canReadChecks && $checkBudget > 0
                && preg_match('/^[a-f0-9]{40}$/D', $headSha)) {
                $checkBudget--;
                $signal['ci_sha'] = $headSha;
                $checksResponse = $client->get(
                    '/repos/'.$repoPath.'/commits/'.$headSha.'/check-runs',
                    ['per_page' => 30],
                );

                if ($checksResponse->successful()) {
                    $checks = $checksResponse->json();
                    if (is_array($checks)) {
                        $count = (int) ($checks['total_count'] ?? 0);
                        $runs = (array) ($checks['check_runs'] ?? []);
                        if ($count > 0 && $count <= 30
                            && count($runs) === $count) {
                            $signal['ci_state'] = $this->roadmapObservedCheckState($runs);
                        }
                    }
                }
            }

            $signals[$number] = $signal;
        }

        return [
            'signals' => $signals,
            'observed_at' => now()->toIso8601String(),
            'warning' => ! $canReadPulls
                ? 'GitHub AppにPull Requestsのread権限がないためPR状態は未確認です。'
                : (! $canReadChecks
                    ? 'Checksのread権限がないためCI状態は未確認です。'
                    : null),
        ];
    }

    /**
     * An observed check-run summary is never proof that required branch
     * protection checks passed, or that production/device verification passed.
     *
     * @param array<int,mixed> $runs
     */
    private function roadmapObservedCheckState(array $runs): string
    {
        $pending = false;
        $allSucceeded = true;
        foreach ($runs as $run) {
            if (! is_array($run)) {
                return 'unknown';
            }
            $status = (string) ($run['status'] ?? '');
            $conclusion = (string) ($run['conclusion'] ?? '');
            if (in_array($conclusion, ['failure', 'timed_out', 'cancelled', 'action_required'], true)) {
                return 'failed';
            }
            if ($status !== 'completed') {
                $pending = true;
            }
            if ($status !== 'completed' || $conclusion !== 'success') {
                $allSucceeded = false;
            }
        }

        return $pending ? 'pending' : ($allSucceeded ? 'observed_pass' : 'unknown');
    }

    private function developmentReadContext(
        string $repoFullName,
        string $permission,
        string $permissionLabel,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);

        $appJwt = $this->appJwt();
        $installationResponse = $this->appClient($appJwt)
            ->get('/repos/'.$repoPath.'/installation');

        if ($installationResponse->status() === 404) {
            throw new RuntimeException('Canovia GitHub AppがこのRepositoryに接続されていません。');
        }

        if (! $installationResponse->successful()) {
            throw new RuntimeException('GitHub AppのRepository接続を確認できませんでした。');
        }

        $installationId = (int) data_get(
            $installationResponse->json(),
            'id',
            0,
        );
        if ($installationId <= 0) {
            throw new RuntimeException('GitHub App installationを確認できませんでした。');
        }

        $tokenResponse = $this->appClient($appJwt)
            ->post('/app/installations/'.$installationId.'/access_tokens');

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('GitHub Appの一時Access Tokenを発行できませんでした。');
        }

        $token = trim((string) data_get($tokenResponse->json(), 'token', ''));
        $permissions = (array) data_get(
            $tokenResponse->json(),
            'permissions',
            [],
        );

        if ($token === '') {
            throw new RuntimeException('GitHub Appの一時Access Tokenを取得できませんでした。');
        }

        if (! in_array(
            ($permissions[$permission] ?? null),
            ['read', 'write'],
            true,
        )) {
            throw new RuntimeException(
                'GitHub Appに'.$permissionLabel.'のread権限がありません。',
            );
        }

        return [
            'repo_path' => $repoPath,
            'installation_id' => $installationId,
            'permissions' => $permissions,
            'client' => $this->installationClient($token),
        ];
    }

    private function appClient(string $jwt): PendingRequest
    {
        return $this->baseClient()->withToken($jwt);
    }

    private function installationClient(string $token): PendingRequest
    {
        return $this->baseClient()->withToken($token);
    }

    private function baseClient(): PendingRequest
    {
        return Http::baseUrl(rtrim(
            (string) config('services.github.api_url', 'https://api.github.com'),
            '/',
        ))
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Canovia',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->connectTimeout(3)
            ->timeout(8);
    }

    private function appJwt(): string
    {
        $appId = trim((string) config('services.github.app_id', ''));
        $privateKey = $this->configuredPrivateKey();

        if ($appId === '' || $privateKey === null) {
            throw new RuntimeException('GitHub AppがCanoviaに設定されていません。');
        }

        $now = now()->timestamp;
        $header = $this->base64Url((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));
        $payload = $this->base64Url((string) json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $appId,
        ], JSON_UNESCAPED_SLASHES));

        $unsigned = $header.'.'.$payload;
        $signature = '';

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GitHub App認証署名を作成できませんでした。');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    private function configuredPrivateKey(): ?string
    {
        $encoded = trim((string) config('services.github.app_private_key_base64', ''));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);

            return is_string($decoded) && str_contains($decoded, 'PRIVATE KEY')
                ? $decoded
                : null;
        }

        $raw = trim((string) config('services.github.app_private_key', ''));
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['\\r\\n', '\\n'], ["\n", "\n"], $raw);

        return str_contains($raw, 'PRIVATE KEY') ? $raw : null;
    }

    /** @return array{0:string,1:string} */
    private function splitRepo(string $repoFullName): array
    {
        $repoFullName = trim($repoFullName);

        if (! preg_match('/^([A-Za-z0-9.-]{1,100})\/([A-Za-z0-9_.-]{1,100})$/', $repoFullName, $matches)) {
            throw new RuntimeException('GitHub Repository名を確認できませんでした。');
        }

        return [(string) $matches[1], (string) $matches[2]];
    }

    private function normalizeFilePath(string $filePath): string
    {
        $path = str_replace('\\', '/', trim($filePath));
        $path = ltrim($path, '/');

        if (
            $path === ''
            || strlen($path) > 240
            || str_contains($path, "\0")
            || collect(explode('/', $path))->contains(fn (string $part) => $part === '' || $part === '.' || $part === '..')
        ) {
            throw new RuntimeException('変更対象のfile pathを確認してください。');
        }

        $lower = mb_strtolower($path);
        if ($lower === '.github/workflows' || str_starts_with($lower, '.github/workflows/')) {
            throw new RuntimeException('.github/workflows 配下はV46.4の安全境界では変更できません。');
        }

        return $path;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function branchName(): string
    {
        return 'canovia/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<int,array<string,mixed>> $actionsRuns
     * @param array<int,array<string,mixed>> $checks
     * @param array<string,mixed>|null $combinedStatus
     */
    private function ciState(
        array $actionsRuns,
        array $checks,
        ?array $combinedStatus,
    ): string {
        $failureConclusions = ['failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure', 'stale'];
        $pendingStatuses = ['queued', 'in_progress', 'requested', 'waiting', 'pending'];

        $explicitSuccess = false;

        foreach (array_merge($actionsRuns, $checks) as $item) {
            $status = strtolower((string) ($item['status'] ?? ''));
            $conclusion = strtolower((string) ($item['conclusion'] ?? ''));

            if (in_array($conclusion, $failureConclusions, true)) {
                return 'failure';
            }

            if (in_array($status, $pendingStatuses, true) || $conclusion === '') {
                return 'pending';
            }

            if ($conclusion === 'success') {
                $explicitSuccess = true;
            } elseif (! in_array($conclusion, ['neutral', 'skipped'], true)) {
                return 'unknown';
            }
        }

        $combinedState = strtolower((string) ($combinedStatus['state'] ?? ''));
        if (in_array($combinedState, ['failure', 'error'], true)) {
            return 'failure';
        }
        if ($combinedState === 'pending') {
            return 'pending';
        }
        if ($combinedState === 'success') {
            $explicitSuccess = true;
        }

        $hasSignals = $actionsRuns !== [] || $checks !== [] || $combinedStatus !== null;
        if (! $hasSignals) {
            return 'unknown';
        }

        return $explicitSuccess ? 'success' : 'unknown';
    }

    private function integerHeader(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function boundedProviderText(
        mixed $value,
        int $limit,
    ): ?string {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $text = preg_replace('/\r\n?|\n/u', "\n", $text) ?? $text;

        return mb_substr($text, 0, max(1, $limit));
    }

    private function positiveInteger(mixed $value): ?int
    {
        $number = is_numeric($value) ? (int) $value : 0;

        return $number > 0 ? $number : null;
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 64) : null;
    }

    private function githubUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' && in_array($host, ['github.com', 'www.github.com'], true)
            ? mb_substr($url, 0, 2048)
            : null;
    }
}
