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

    /**
     * Compare the current pinned roadmap with its immediately preceding
     * document edit. Intent differences only; GitHub evidence is not refreshed.
     *
     * @return array<string,mixed>
     */
    public function diffFromPrevious(
        string $repository,
        DevelopmentRoadmapRevisionDiffer $differ,
    ): array {
        $current = $this->read($repository);
        $currentSha = (string) data_get($current, 'source.sha', '');

        // A missing current document is not evidence of a deleted workstream.
        if (in_array('No canonical roadmap found. Import is read-only; no tasks inferred.',
            (array) ($current['warnings'] ?? []), true)) {
            return [
                'schema' => 'canovia.development_roadmap_diff.v1',
                'status' => 'current_missing',
                'before_sha' => null,
                'after_sha' => $currentSha,
                'changes' => [],
                'completion' => 'unverified',
            ];
        }

        $previous = $this->github->readPreviousDevelopmentRoadmapMarkdown(
            $repository,
            $currentSha,
        );
        if ($previous === null) {
            return [
                'schema' => 'canovia.development_roadmap_diff.v1',
                'status' => 'no_previous',
                'before_sha' => null,
                'after_sha' => $currentSha,
                'changes' => [],
                'completion' => 'unverified',
            ];
        }

        $before = $this->parser->parse(
            $previous['content'],
            $repository,
            'docs/development/ROADMAP.md',
            $previous['sha'],
        );
        $result = $differ->compare($before, $current);
        $result['status'] = 'compared';
        return $result;
    }

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
