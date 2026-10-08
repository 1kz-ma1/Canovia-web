<?php

namespace App\Services;

/**
 * Domain-independent start-position policy for a target and its prerequisites.
 * A single diagnostic never proves mastery or permanently locks advancement.
 */
final class AdaptiveStartingPointPolicyService
{
    /**
     * @param array<string,mixed> $topicScores
     * @param array<int,string> $prerequisiteKeys
     * @return array{status:string,weak_topics:array<int,string>}
     */
    public function resolve(
        int $overallPercent,
        array $topicScores,
        array $prerequisiteKeys,
        bool $wantsStretch,
    ): array {
        $weak = [];
        foreach ($prerequisiteKeys as $topic) {
            $value = $topicScores[$topic] ?? null;
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                return ['status' => 'diagnostic_needed', 'weak_topics' => []];
            }

            if ((float) $value < 67) {
                $weak[] = $topic;
            }
        }

        if ($weak !== []) {
            return ['status' => 'review_prerequisites', 'weak_topics' => $weak];
        }

        return [
            'status' => $overallPercent >= 83 && $wantsStretch
                ? 'trial_next_grade'
                : 'review_and_verify',
            'weak_topics' => [],
        ];
    }
}
