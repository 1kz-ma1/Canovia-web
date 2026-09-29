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
}
