<?php

namespace App\Services;

/**
 * Deterministic, read-only comparison of two already-authorized roadmap
 * snapshots. No GitHub requests, task writes or completion inference.
 */
final class DevelopmentRoadmapRevisionDiffer
{
    /** @return array<string,mixed> */
    public function compare(array $before, array $after): array
    {
        $previous = $this->index((array) ($before['workstreams'] ?? []));
        $current = $this->index((array) ($after['workstreams'] ?? []));
        $changes = [];

        foreach ($current as $key => $row) {
            if (! isset($previous[$key])) {
                $changes[] = ['kind' => 'added', 'title' => $row['title'], 'after' => $row];
                continue;
            }

            $old = $previous[$key];
            if ($old['priority'] !== $row['priority'] || $old['next'] !== $row['next']
                || $old['evidence'] !== $row['evidence']) {
                $changes[] = [
                    'kind' => 'changed',
                    'title' => $row['title'],
                    'before' => $old,
                    'after' => $row,
                ];
            }
        }

        foreach ($previous as $key => $row) {
            if (! isset($current[$key])) {
                $changes[] = ['kind' => 'removed', 'title' => $row['title'], 'before' => $row];
            }
        }

        return [
            'schema' => 'canovia.development_roadmap_diff.v1',
            'before_sha' => data_get($before, 'source.sha'),
            'after_sha' => data_get($after, 'source.sha'),
            'changes' => $changes,
            'completion' => 'unverified',
            'caveat' => 'A Markdown change is intent only; it is not evidence of implementation, deployment or Task completion.',
        ];
    }

    /** @return array<string,array<string,string>> */
    private function index(array $rows): array
    {
        $indexed = [];
        foreach (array_slice($rows, 0, 40) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            // Duplicate titles are ambiguous: preserve each occurrence rather
            // than silently collapsing them into a single workstream.
            $key = mb_strtolower($title);
            $count = 1;
            $unique = $key;
            while (isset($indexed[$unique])) {
                $unique = $key.'#'.(++$count);
            }
            $indexed[$unique] = [
                'title' => $title,
                'priority' => (string) ($row['priority'] ?? 'UNSPECIFIED'),
                'evidence' => (string) ($row['evidence'] ?? ''),
                'next' => (string) ($row['next'] ?? ''),
            ];
        }
        return $indexed;
    }
}
