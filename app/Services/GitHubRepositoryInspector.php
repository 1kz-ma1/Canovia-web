<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubRepositoryInspector
{
    public const SNAPSHOT_VERSION = 1;

    /**
     * Read a bounded, authoritative snapshot from GitHub REST.
     *
     * The snapshot is intentionally presentation-oriented. It is not a mirror
     * of GitHub and does not create Canovia Tasks / Evidence by itself.
     *
     * @return array<string,mixed>
     */
    public function inspect(string $repoFullName): array
    {
        [$owner, $repo] = $this->splitRepo($repoFullName);
        $repoPath = rawurlencode($owner).'/'.rawurlencode($repo);

        $repositoryResponse = $this->client()->get('/repos/'.$repoPath);
        if (! $repositoryResponse->successful()) {
            throw new RuntimeException($this->repositoryError($repositoryResponse));
        }

        $repository = $repositoryResponse->json();
        if (! is_array($repository)) {
            throw new RuntimeException('GitHub Repository情報を読み取れませんでした。');
        }

        // A service-owned token must never become an accidental cross-user
        // private-repository credential. V46.3 is public-repository inspection
        // only, even when a token is configured for rate-limit relief.
        $visibility = strtolower((string) ($repository['visibility'] ?? ''));
        if ((bool) ($repository['private'] ?? false) || ($visibility !== '' && $visibility !== 'public')) {
            throw new RuntimeException('現在のGitHub読み込みは公開Repositoryだけに対応しています。Private Repositoryはユーザー別GitHub接続が必要です。');
        }

        $warnings = [];
        $branchesResponse = $this->client()->get('/repos/'.$repoPath.'/branches', [
            'per_page' => 12,
        ]);
        $pullsResponse = $this->client()->get('/repos/'.$repoPath.'/pulls', [
            'state' => 'open',
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => 12,
        ]);
        $issuesResponse = $this->client()->get('/repos/'.$repoPath.'/issues', [
            'state' => 'open',
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => 20,
        ]);
        $actionsResponse = $this->client()->get('/repos/'.$repoPath.'/actions/runs', [
            'per_page' => 10,
        ]);

        foreach ([
            'branches' => $branchesResponse,
            'pull requests' => $pullsResponse,
            'issues' => $issuesResponse,
            'actions' => $actionsResponse,
        ] as $label => $response) {
            if (! $response->successful()) {
                $warnings[] = $label.'は取得できませんでした。';
            }
        }

        $branches = $branchesResponse->successful()
            ? collect($branchesResponse->json())
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

        $pullRequests = $pullsResponse->successful()
            ? collect($pullsResponse->json())
                ->filter(fn ($item) => is_array($item))
                ->take(12)
                ->map(fn (array $item) => [
                    'number' => (int) ($item['number'] ?? 0),
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                    'draft' => (bool) ($item['draft'] ?? false),
                    'head' => mb_substr((string) data_get($item, 'head.ref', ''), 0, 255),
                    'base' => mb_substr((string) data_get($item, 'base.ref', ''), 0, 255),
                    'updated_at' => $this->dateString($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['number'] > 0)
                ->values()
                ->all()
            : [];

        $issues = $issuesResponse->successful()
            ? collect($issuesResponse->json())
                ->filter(fn ($item) => is_array($item) && ! array_key_exists('pull_request', $item))
                ->take(12)
                ->map(fn (array $item) => [
                    'number' => (int) ($item['number'] ?? 0),
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
                    'updated_at' => $this->dateString($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['number'] > 0)
                ->values()
                ->all()
            : [];

        $runData = $actionsResponse->successful() ? $actionsResponse->json() : [];
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
                    'updated_at' => $this->dateString($item['updated_at'] ?? null),
                    'url' => $this->githubUrl($item['html_url'] ?? null),
                ])
                ->filter(fn (array $item) => $item['id'] > 0)
                ->values()
                ->all()
            : [];

        return [
            'version' => self::SNAPSHOT_VERSION,
            'source' => 'github_rest',
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
                'visibility' => mb_substr((string) ($repository['visibility'] ?? 'public'), 0, 50),
                'archived' => (bool) ($repository['archived'] ?? false),
                'fork' => (bool) ($repository['fork'] ?? false),
                'stars' => max(0, (int) ($repository['stargazers_count'] ?? 0)),
                'forks' => max(0, (int) ($repository['forks_count'] ?? 0)),
                'open_issues_count' => max(0, (int) ($repository['open_issues_count'] ?? 0)),
                'updated_at' => $this->dateString($repository['updated_at'] ?? null),
                'pushed_at' => $this->dateString($repository['pushed_at'] ?? null),
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

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim(
            (string) config('services.github.api_url', 'https://api.github.com'),
            '/',
        ))
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'Canovia',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->connectTimeout(3)
            ->timeout(6);

        $token = trim((string) config('services.github.read_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
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

    private function repositoryError(Response $response): string
    {
        if ($response->status() === 404) {
            return 'RepositoryをGitHubから取得できませんでした。公開Repositoryでない場合はGitHub接続が必要です。';
        }

        if ($response->status() === 403) {
            return 'GitHub APIの利用上限またはアクセス権によりRepositoryを取得できませんでした。';
        }

        return 'GitHub Repositoryの取得に失敗しました。時間を置いて再試行してください。';
    }

    private function githubUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['github.com', 'www.github.com'], true)
            ? mb_substr($url, 0, 2048)
            : null;
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 64) : null;
    }

    private function integerHeader(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
