<?php

namespace App\Services;

/**
 * Authorized, read-only GitHub roadmap projection.
 * Token exchange stays encapsulated in GitHubRepositoryWriter.
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
        $snapshot = $this->github->readDevelopmentRoadmapMarkdown($repository);
        $sha = (string) $snapshot['sha'];
        $path = 'docs/development/ROADMAP.md';
        $markdown = $snapshot['content'];

        if ($markdown === null) {
            return [
                'source' => ['repository' => $repository, 'path' => $path, 'sha' => $sha],
                'sections' => [],
                'workstreams' => [],
                'warnings' => ['No canonical roadmap found. Import is read-only; no tasks inferred.'],
            ];
        }

        return $this->parser->parse($markdown, $repository, $path, $sha);
    }
}
