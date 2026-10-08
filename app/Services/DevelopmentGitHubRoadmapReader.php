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
        private readonly DevelopmentRoadmapEvidenceLinker $linker,
    ) {}

    /** @return array<string, mixed> */
    public function read(string $repository, bool $verifyGithub = false): array
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

        $roadmap = $this->parser->parse($markdown, $repository, $path, $sha);
        $roadmap['github_evidence_checked'] = false;

        // Extra remote calls happen only when the actor explicitly requests
        // verification. The regular roadmap remains cheap/read-only.
        if (! $verifyGithub) {
            return $roadmap;
        }

        $references = $this->linker->references($roadmap['workstreams']);
        $numbers = $this->linker->uniqueNumbers($references);
        if ($numbers === []) {
            $roadmap['warnings'][] = 'No explicit PR references to verify.';
            return $roadmap;
        }

        try {
            $verification = $this->github->readDevelopmentRoadmapPullRequestSignals(
                $repository,
                $numbers,
            );
        } catch (\RuntimeException $exception) {
            $roadmap['warnings'][] = 'GitHub PR verification unavailable: '.$exception->getMessage();
            return $this->linker->attach($roadmap, $references, []);
        }

        $roadmap = $this->linker->attach(
            $roadmap,
            $references,
            (array) ($verification['signals'] ?? []),
        );
        $roadmap['github_evidence_checked'] = true;
        $roadmap['github_evidence_observed_at'] = $verification['observed_at'] ?? null;
        if (! empty($verification['warning'])) {
            $roadmap['warnings'][] = (string) $verification['warning'];
        }

        return $roadmap;
    }
}
