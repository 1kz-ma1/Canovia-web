<?php

namespace App\Services;

/**
 * Pure, read-only projection of a GitHub Markdown roadmap.
 * Does not infer Task IDs, percentages, deployment or completion.
 */
final class DevelopmentRoadmapMarkdownParser
{
    public const MAX_BYTES = 200_000;

    /**
     * @return array{source: array{repository: string, path: string, sha: string}, sections: array<int, array{title: string, entries: array<int, array{text: string, status: string}>}>, workstreams: array<int, array<string, string>>, warnings: array<int, string>}
     */
    public function parse(string $markdown, string $repository, string $path, string $sha): array
    {
        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository)
            || ! preg_match('/^[a-f0-9]{40}$/D', $sha)
            || $path !== 'docs/development/ROADMAP.md') {
            throw new \InvalidArgumentException('Untrusted roadmap source.');
        }

        if (strlen($markdown) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Roadmap exceeds size limit.');
        }

        $sections = [];
        $workstreams = [];
        $tableOpen = false;
        $warnings = [];
        $current = null;
        $fenced = false;

        foreach (preg_split('/\r\n|\n|\r/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\x60{3,}|^~{3,}/', $trimmed)) {
                $fenced = ! $fenced;
                continue;
            }
            if ($fenced || $trimmed === '' || str_starts_with($trimmed, '<')) {
                continue;
            }
            if (preg_match('/^#{2,3}\s+(.+)$/u', $trimmed, $matches)) {
                $sections[] = ['title' => mb_substr(strip_tags($matches[1]), 0, 160), 'entries' => []];
                $current = count($sections) - 1;
                continue;
            }
            if (str_starts_with($trimmed, '|')) {
                $columns = array_map(
                    fn (string $cell) => trim(strip_tags($cell)),
                    explode('|', trim($trimmed, '|')),
                );

                if (count($columns) === 4 && strcasecmp($columns[0], 'Priority') === 0
                    && strcasecmp($columns[1], 'Workstream') === 0) {
                    $tableOpen = true;
                    continue;
                }

                if ($tableOpen && count($columns) === 4
                    && ! preg_match('/^:?-{3,}:?$/', $columns[0])) {
                    if (count($workstreams) < 40) {
                        $priority = strtoupper($columns[0]);
                        $workstreams[] = [
                            'priority' => preg_match('/^P[0-4]$/', $priority)
                                ? $priority
                                : 'UNSPECIFIED',
                            'title' => mb_substr($columns[1], 0, 180),
                            'evidence' => mb_substr($columns[2], 0, 500),
                            'next' => mb_substr($columns[3], 0, 650),
                            'status' => 'unverified',
                        ];
                    } elseif (! in_array('Roadmap rows truncated to 40.', $warnings, true)) {
                        $warnings[] = 'Roadmap rows truncated to 40.';
                    }
                }

                continue;
            }

            $tableOpen = false;
            if ($current === null) {
                continue;
            }
            if (preg_match('/^(?:[-*]\s+|\d+\.\s+)(.+)$/u', $trimmed, $matches)) {
                $text = mb_substr(strip_tags($matches[1]), 0, 1000);
                $sections[$current]['entries'][] = ['text' => $text, 'status' => 'unverified'];
            }
        }

        if ($sections === []) {
            $warnings[] = 'No Markdown sections found; no completion inferred.';
        }

        return [
            'source' => ['repository' => $repository, 'path' => $path, 'sha' => $sha],
            'sections' => $sections,
            'workstreams' => $workstreams,
            'warnings' => $warnings,
        ];
    }
}
