<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use InvalidArgumentException;

final class StudyExamReadinessEvaluator implements ReadinessEvaluator
{
    public const MASTERY_TARGET = 80;
    public const RETENTION_TARGET = 70;
    public const COVERAGE_TARGET = 85;

    public function evaluate(StateSnapshot $state): ReadinessAssessment
    {
        if ($state->domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException(
                'StudyExamReadinessEvaluator only supports the study domain.',
            );
        }

        $scopeCount = max(
            0,
            (int) data_get($state->metrics, 'confirmed_scope_count', 0),
        );

        if ($scopeCount === 0) {
            return new ReadinessAssessment(
                score: null,
                level: ReadinessLevel::Unknown,
                confidence: new Confidence(0.15),
                components: [
                    'coverage_percent' => null,
                    'mastery_score_percent' => null,
                    'retention_score_percent' => null,
                    'speed_score_percent' => null,
                    'remaining_effort_percent' => null,
                ],
                gaps: [[
                    'code' => is_array(data_get($state->facts, 'official_exam_reference'))
                        ? 'official_scope_known_mastery_unmeasured'
                        : 'confirmed_scope_missing',
                    'dimension' => 'scope',
                    'severity' => 'high',
                    'observed' => 0,
                    'target' => 1,
                ]],
                metadata: [
                    'policy' => 'study_exam_readiness_v1',
                    'meaning' => 'exam_relative_study_readiness',
                ],
            );
        }

        $coverage = $this->percent(
            data_get($state->metrics, 'coverage_percent', 0),
        );
        $mastery = $this->percentOrNull(
            data_get($state->metrics, 'mastery_score_percent'),
        );
        $retention = $this->percentOrNull(
            data_get($state->metrics, 'retention_score_percent'),
        );
        $remaining = $this->percent(
            data_get($state->metrics, 'remaining_effort_percent', 100),
        );
        $days = $this->intOrNull(
            data_get($state->metrics, 'days_until_exam'),
        );
        $practiceCount = max(
            0,
            (int) data_get($state->metrics, 'practice_attempt_count', 0),
        );
        $recallCount = max(
            0,
            (int) data_get($state->metrics, 'recall_review_count', 0),
        );
        $pressure = (string) data_get(
            $state->facts,
            'deadline_pressure',
            'unknown',
        );
        $dateConflict = (bool) data_get(
            $state->facts,
            'exam_date_conflict',
            false,
        );

        $score = (int) round(
            ($coverage * 0.35)
            + (($mastery ?? 0) * 0.45)
            + (($retention ?? 0) * 0.20),
        );
        $score = max(0, min(100, $score));

        $gaps = [];

        if ($coverage < self::COVERAGE_TARGET) {
            $gaps[] = $this->gap(
                'coverage_below_target',
                'coverage',
                $coverage < 50 ? 'high' : 'medium',
                $coverage,
                self::COVERAGE_TARGET,
            );
        }

        if ($mastery === null) {
            $gaps[] = [
                'code' => 'mastery_unmeasured',
                'dimension' => 'mastery',
                'severity' => 'high',
            ];
        } elseif ($mastery < self::MASTERY_TARGET) {
            $gaps[] = $this->gap(
                'mastery_below_target',
                'mastery',
                $mastery < 50 ? 'high' : 'medium',
                $mastery,
                self::MASTERY_TARGET,
            );
        }

        if ($retention === null) {
            $gaps[] = [
                'code' => 'retention_unverified',
                'dimension' => 'retention',
                'severity' => 'medium',
            ];
        } elseif ($retention < self::RETENTION_TARGET) {
            $gaps[] = $this->gap(
                'retention_below_target',
                'retention',
                $retention < 45 ? 'high' : 'medium',
                $retention,
                self::RETENTION_TARGET,
            );
        }

        $gaps[] = [
            'code' => 'speed_unmeasured',
            'dimension' => 'speed',
            'severity' => 'low',
        ];

        if ($dateConflict) {
            $gaps[] = [
                'code' => 'exam_date_conflict',
                'dimension' => 'deadline',
                'severity' => 'medium',
            ];
        } elseif ($days === null) {
            $gaps[] = [
                'code' => 'exam_date_unknown',
                'dimension' => 'deadline',
                'severity' => 'low',
            ];
        } elseif ($days < 0 && $remaining > 0) {
            $gaps[] = [
                'code' => 'exam_deadline_passed',
                'dimension' => 'deadline',
                'severity' => 'high',
                'observed' => $days,
                'target' => 0,
            ];
        } elseif ($pressure === 'high') {
            $gaps[] = [
                'code' => 'remaining_load_pressure_high',
                'dimension' => 'deadline',
                'severity' => 'high',
                'remaining_effort_percent' => $remaining,
                'days_until_exam' => $days,
            ];
        } elseif ($pressure === 'medium') {
            $gaps[] = [
                'code' => 'remaining_load_pressure_medium',
                'dimension' => 'deadline',
                'severity' => 'medium',
                'remaining_effort_percent' => $remaining,
                'days_until_exam' => $days,
            ];
        }

        $confidence = $this->confidence(
            $coverage,
            $practiceCount,
            $recallCount,
        );

        $ready = $score >= 80
            && $coverage >= self::COVERAGE_TARGET
            && $mastery !== null
            && $mastery >= self::MASTERY_TARGET
            && $retention !== null
            && $retention >= self::RETENTION_TARGET
            && $remaining <= 25
            && ! in_array($pressure, ['high', 'overdue'], true);

        $blocked = ($days !== null && $days < 0 && $remaining > 0)
            || $score < 40
            || $pressure === 'high';

        $level = match (true) {
            $ready => ReadinessLevel::Ready,
            $blocked => ReadinessLevel::Blocked,
            default => ReadinessLevel::Developing,
        };

        return new ReadinessAssessment(
            score: $score,
            level: $level,
            confidence: new Confidence($confidence),
            components: [
                'coverage_percent' => $coverage,
                'mastery_score_percent' => $mastery,
                'retention_score_percent' => $retention,
                'speed_score_percent' => null,
                'speed_status' => 'unmeasured',
                'remaining_effort_percent' => $remaining,
                'remaining_effort_units' => data_get(
                    $state->metrics,
                    'remaining_effort_units',
                ),
                'days_until_exam' => $days,
                'deadline_pressure' => $pressure,
            ],
            gaps: $gaps,
            metadata: [
                'policy' => 'study_exam_readiness_v1',
                'meaning' => 'exam_relative_study_readiness',
                'coverage_target_percent' => self::COVERAGE_TARGET,
                'mastery_target_percent' => self::MASTERY_TARGET,
                'retention_target_percent' => self::RETENTION_TARGET,
                'speed_policy' => 'unscored_until_authoritative_measurement',
                'effort_unit_policy' => 'one_confirmed_scope_item_equals_one_normalized_unit',
            ],
        );
    }

    private function confidence(
        int $coverage,
        int $practiceCount,
        int $recallCount,
    ): float {
        $value = 0.35;
        $value += min(0.25, ($coverage / 100) * 0.25);
        $value += min(0.15, ($practiceCount / 3) * 0.15);
        $value += min(0.10, ($recallCount / 3) * 0.10);

        // Speed is still unmeasured, so V1 must not claim near-certainty.
        return round(min(0.85, max(0.15, $value)), 4);
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

    private function percent(mixed $value): int
    {
        return $this->percentOrNull($value) ?? 0;
    }

    private function percentOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false
            ? null
            : max(0, min(100, (int) $value));
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }
}
