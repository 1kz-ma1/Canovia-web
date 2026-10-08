<?php

namespace App\Services;

/**
 * Deterministic interpretation of *references*, never project completion.
 * Markdown statements remain unverified until separately observed on GitHub.
 */
final class DevelopmentRoadmapEvidenceLinker
{
    public const MAX_PR_REFERENCES = 10;
    public const MAX_PR_PER_WORKSTREAM = 8;

    /**
     * The same repo's explicit PR references only; do not interpret arbitrary
     * issue numbers, version numbers, free text or URLs as Pull Requests.
     *
     * @param array<int,array<string,mixed>> $workstreams
     * @return array<int,array<int,int>> Index => PR numbers
     */
    public function references(array $workstreams): array
    {
        $found = [];
        $unique = [];

        foreach ($workstreams as $index => $row) {
            $text = (string) ($row['evidence'] ?? '');
            $numbers = [];

            if (! preg_match('/\bPR\s*#/i', $text)) {
                continue;
            }

            // Explicit "PR #12", "PR #12–#14" and subsequent "#20–#22".
            // Never pick up unrelated single "#123" mentions.
            preg_match_all(
                '/\bPR\s*#\s*(\d{1,6})(?:\s*[-–—]\s*#?\s*(\d{1,6}))?|(?<![a-z0-9])#\s*(\d{1,6})\s*[-–—]\s*#?\s*(\d{1,6})/iu',
                $text,
                $matches,
                PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
            );

            foreach ($matches as $match) {
                $start = (int) ($match[1] ?: ($match[3] ?? 0));
                $end = (int) ($match[2] ?: ($match[4] ?? $start));
                if ($start < 1 || $end < $start || $end - $start > 7) {
                    continue;
                }

                for ($number = $start; $number <= $end; $number++) {
                    if (in_array($number, $numbers, true)) {
                        continue;
                    }
                    if (count($numbers) >= self::MAX_PR_PER_WORKSTREAM) {
                        break 2;
                    }
                    if (! in_array($number, $unique, true)
                        && count($unique) >= self::MAX_PR_REFERENCES) {
                        break 2;
                    }

                    $numbers[] = $number;
                    if (! in_array($number, $unique, true)) {
                        $unique[] = $number;
                    }
                }
            }

            if ($numbers !== []) {
                $found[$index] = $numbers;
            }
        }

        return $found;
    }

    /** @param array<int,array<int,int>> $references @return array<int,int> */
    public function uniqueNumbers(array $references): array
    {
        return array_values(array_unique(array_merge([], ...array_values($references))));
    }

    /**
     * @param array<string,mixed> $roadmap
     * @param array<int,array<int,int>> $references
     * @param array<int,array<string,mixed>> $observed Indexed by PR number
     * @return array<string,mixed>
     */
    public function attach(array $roadmap, array $references, array $observed): array
    {
        foreach ($roadmap['workstreams'] ?? [] as $index => &$row) {
            $signals = [];
            foreach ($references[$index] ?? [] as $number) {
                $signal = $observed[$number] ?? [
                    'number' => $number,
                    'merge_state' => 'unknown',
                    'ci_state' => 'unknown',
                    'ci_sha' => null,
                ];
                $signals[] = $signal;
            }
            $row['github_signals'] = $signals;
            // A PR that merged is evidence of a PR merge, not proof the whole
            // workstream or release is complete.
            $row['status'] = 'unverified';
        }
        unset($row);

        return $roadmap;
    }
}
