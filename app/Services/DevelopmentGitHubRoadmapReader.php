<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Authorized, read-only GitHub App roadmap snapshot.
 * No Canovia Plan or Task mutation, and no inferred completion state.
 */
final class DevelopmentGitHubRoadmapReader
{
    public function __construct(
        private readonly GitHubRepositoryWriter $github,
        private readonly DevelopmentRoadmapMarkdownParser $parser,
    ) {}

    /** @return array<string, mixed> */
    public function read(string $repository): array
    {
        $context = $this->github->developmentReadContext($repository, 'contents', 'Contents');
        /** @var PendingRequest $client */
        $client = $context['client'];
        $repoPath = (string) $context['repo_path'];

        $repoResponse = $client->get('/repos/'.$repoPath);
        if (! $repoResponse->successful()) {
            throw new RuntimeException('Repository情報を取得できませんでした。');
        }

        $repo = $repoResponse->json();
        $branch = is_array($repo) ? (string) ($repo['default_branch'] ?? '') : '';
        if ($branch === '' || strlen($branch) > 255) {
            throw new RuntimeException('Default branchを確認できませんでした。');
        }

        $headResponse = $client->get('/repos/'.$repoPath.'/commits/'.rawurlencode($branch));
        $head = $headResponse->successful() ? $headResponse->json() : null;
        $sha = is_array($head) ? (string) ($head['sha'] ?? '') : '';
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new RuntimeException('Roadmapの参照commitを確認できませんでした。');
        }

        // Fetch at immutable SHA, never at a moving branch ref.
        $path = 'docs/development/ROADMAP.md';
        $response = $client->get('/repos/'.$repoPath.'/contents/'.$path, ['ref' => $sha]);
        if ($response->status() === 404) {
            return [
                'source' => ['repository' => $repository, 'path' => $path, 'sha' => $sha],
                'sections' => [],
                'warnings' => ['No canonical roadmap found. Import is read-only; no tasks inferred.'],
            ];
        }
        if (! $response->successful()) {
            throw new RuntimeException('Roadmapを取得できませんでした。');
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['type'] ?? null) !== 'file'
            || ($payload['encoding'] ?? null) !== 'base64'
            || ! is_string($payload['content'] ?? null)
            || (int) ($payload['size'] ?? -1) < 0
            || (int) ($payload['size'] ?? -1) > DevelopmentRoadmapMarkdownParser::MAX_BYTES) {
            throw new RuntimeException('Roadmapの形式またはサイズが不正です。');
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $payload['content']) ?: '', true);
        if (! is_string($bytes) || strlen($bytes) > DevelopmentRoadmapMarkdownParser::MAX_BYTES
            || strlen($bytes) !== (int) $payload['size']) {
            throw new RuntimeException('Roadmapを安全に読み取れませんでした。');
        }

        return $this->parser->parse($bytes, $repository, $path, $sha);
    }
}
