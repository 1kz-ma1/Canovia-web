<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use InvalidArgumentException;

final class StudyReadinessEvaluator implements ReadinessEvaluator
{
    public const TARGET_SCORE = 80;

    public function evaluate(StateSnapshot $state): ReadinessAssessment
    {
        if ($state->domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException('StudyReadinessEvaluator only supports the study domain.');
        }

        $attempts = max(0, (int) data_get($state->metrics, 'practice_attempt_count', 0));
        $recalls = max(0, (int) data_get($state->metrics, 'recall_review_count', 0));
        $latest = $this->percentOrNull(data_get($state->metrics, 'latest_score_percent'));
        $average = $this->percentOrNull(data_get($state->metrics, 'average_score_percent'));
        $weaknesses = $this->stringList(data_get($state->facts, 'observed_weaknesses', []));

        if ($latest === null) {
            return new ReadinessAssessment(
                score: null,
                level: ReadinessLevel::Unknown,
                confidence: new Confidence(0.2),
                components: [
                    'performance_score' => null,
                    'latest_score_percent' => null,
                    'average_score_percent' => $average,
                    'practice_attempt_count' => $attempts,
                    'recall_review_count' => $recalls,
                    'retention_verified' => $recalls > 0,
                    'observed_weakness_count' => count($weaknesses),
                ],
                gaps: [
                    $this->gap(
                        code: 'practice_evidence_missing',
                        dimension: 'evidence',
                        severity: 'high',
                        observed: $attempts,
                        target: 1,
                    ),
                ],
                metadata: [
                    'policy' => 'study_readiness_v1',
                    'target_score_percent' => self::TARGET_SCORE,
                ],
            );
        }

        $performance = (int) round(
            ($latest * 0.65) + (($average ?? $latest) * 0.35),
        );

        $weaknessPenalty = min(12, count($weaknesses) * 3);
        $score = max(0, min(100, $performance - $weaknessPenalty));

        $gaps = [];

        if ($attempts < 3) {
            $gaps[] = $this->gap(
                code: 'practice_evidence_thin',
                dimension: 'evidence',
                severity: $attempts <= 1 ? 'high' : 'medium',
                observed: $attempts,
                target: 3,
            );
        }

        if ($latest < self::TARGET_SCORE) {
            $gaps[] = $this->gap(
                code: 'mastery_below_target',
                dimension: 'performance',
                severity: $latest < 50 ? 'high' : 'medium',
                observed: $latest,
                target: self::TARGET_SCORE,
            );
        }

        if ($recalls < 1) {
            $gaps[] = $this->gap(
                code: 'retention_unverified',
                dimension: 'retention',
                severity: 'medium',
                observed: 0,
                target: 1,
            );
        }

        if ($weaknesses !== []) {
            $gaps[] = [
                'code' => 'observed_weaknesses',
                'dimension' => 'mastery',
                'severity' => $latest < 50 ? 'high' : 'medium',
                'items' => $weaknesses,
                'count' => count($weaknesses),
            ];
        }

        $confidence = $this->confidence($attempts, $recalls);

        $level = match (true) {
            $score < 50 => ReadinessLevel::Blocked,
            $score >= self::TARGET_SCORE
                && $attempts >= 3
                && $recalls >= 1
                && $weaknesses === [] => ReadinessLevel::Ready,
            default => ReadinessLevel::Developing,
        };

        return new ReadinessAssessment(
            score: $score,
            level: $level,
            confidence: new Confidence($confidence),
            components: [
                'performance_score' => $performance,
                'weakness_penalty' => $weaknessPenalty,
                'latest_score_percent' => $latest,
                'average_score_percent' => $average,
                'practice_attempt_count' => $attempts,
                'recall_review_count' => $recalls,
                'retention_verified' => $recalls > 0,
                'observed_weakness_count' => count($weaknesses),
            ],
            gaps: $gaps,
            metadata: [
                'policy' => 'study_readiness_v1',
                'target_score_percent' => self::TARGET_SCORE,
                'meaning' => 'evidence_based_study_readiness',
            ],
        );
    }

    private function confidence(int $attempts, int $recalls): float
    {
        $base = match (true) {
            $attempts <= 0 => 0.2,
            $attempts === 1 => 0.45,
            $attempts === 2 => 0.6,
            $attempts === 3 => 0.72,
            $attempts === 4 => 0.78,
            default => 0.82,
        };

        if ($recalls > 0) {
            $base += 0.08;
        }

        return min(0.9, $base);
    }

    private function gap(
        string $code,
        string $dimension,
        string $severity,
        int $observed,
        int $target,
    ): array {
        return [
            'code' => $code,
            'dimension' => $dimension,
            'severity' => $severity,
            'observed' => $observed,
            'target' => $target,
        ];
    }

    private function percentOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        if ($value === false) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
