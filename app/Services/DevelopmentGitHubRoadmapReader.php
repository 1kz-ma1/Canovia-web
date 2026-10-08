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
        $issueReferences = $this->linker->issueReferences($roadmap['workstreams']);
        $prNumbers = $this->linker->uniqueNumbers($references);
        $issueNumbers = $this->linker->uniqueNumbers($issueReferences);
        if ($prNumbers === [] && $issueNumbers === []) {
            $roadmap['warnings'][] = 'No explicit PR or Issue references to verify.';
            return $roadmap;
        }

        if ($prNumbers !== []) {
            try {
                $verification = $this->github->readDevelopmentRoadmapPullRequestSignals(
                    $repository,
                    $prNumbers,
                );
                $roadmap = $this->linker->attach(
                    $roadmap, $references, (array) ($verification['signals'] ?? []),
                );
                $roadmap['github_evidence_checked'] = true;
                $roadmap['github_evidence_observed_at'] = $verification['observed_at'] ?? null;
                if (! empty($verification['warning'])) {
                    $roadmap['warnings'][] = (string) $verification['warning'];
                }
            } catch (\RuntimeException $exception) {
                $roadmap['warnings'][] = 'GitHub PR verification unavailable: '.$exception->getMessage();
                $roadmap = $this->linker->attach($roadmap, $references, []);
            }
        }

        if ($issueNumbers !== []) {
            try {
                $verification = $this->github->readDevelopmentRoadmapIssueSignals(
                    $repository, $issueNumbers,
                );
                $roadmap = $this->linker->attachIssues(
                    $roadmap, $issueReferences, (array) ($verification['signals'] ?? []),
                );
                $roadmap['github_evidence_checked'] = true;
                $roadmap['github_evidence_observed_at'] = $verification['observed_at'] ?? null;
            } catch (\RuntimeException $exception) {
                $roadmap['warnings'][] = 'GitHub Issue verification unavailable: '.$exception->getMessage();
                $roadmap = $this->linker->attachIssues($roadmap, $issueReferences, []);
            }
        }

        return $roadmap;
    }
}
