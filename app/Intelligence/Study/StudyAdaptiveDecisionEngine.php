<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\CandidateDecisionEngine;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\DecisionCandidate;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use App\Intelligence\Support\IntelligenceFingerprint;
use InvalidArgumentException;

final class StudyAdaptiveDecisionEngine implements CandidateDecisionEngine
{
    public function decide(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        array $context = [],
    ): Decision {
        $candidates = $this->candidates($state, $readiness, $context);
        $selected = $candidates[0];

        return $selected->toDecision(
            IntelligenceFingerprint::decisionInput($state, $readiness),
            [
                'policy_version' => 'study_adaptive_action_v1',
                'candidate_count' => count($candidates),
                'candidate_types' => array_values(array_map(
                    fn (DecisionCandidate $candidate) => $candidate->type,
                    $candidates,
                )),
            ],
        );
    }

    /**
     * @return array<int,DecisionCandidate>
     */
    public function candidates(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        array $context = [],
    ): array {
        if ($state->domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException(
                'StudyAdaptiveDecisionEngine only supports the study domain.',
            );
        }

        $scopeCount = max(0, (int) data_get(
            $state->metrics,
            'confirmed_scope_count',
            0,
        ));

        if ($scopeCount === 0 && is_array(data_get($state->facts, 'official_exam_reference'))) {
            // A published IPA syllabus cannot be "undecided" merely because
            // the user has not imported personalized scope items.
            $reference = (array) data_get($state->facts, 'official_exam_reference');
            $taskId = (int) data_get($state->facts, 'official_exam_baseline_task_id', 0);

            return [new DecisionCandidate(
                type: 'establish_official_exam_baseline',
                reasonCode: 'official_scope_known_mastery_unmeasured',
                summary: '公式試験範囲は公開済みです。最初の演習で理解度を確認します。',
                priority: 100,
                confidence: new Confidence(0.94),
                reasons: ['official_reference_known', 'learner_scope_unobserved'],
                metadata: [
                    'target_scope_item_id' => null,
                    'target_task_id' => $taskId > 0 ? $taskId : null,
                    'official_source_kind' => (string) ($reference['source_kind'] ?? ''),
                    'official_source_url' => (string) ($reference['source_url'] ?? ''),
                    'official_syllabus_version' => (string) ($reference['syllabus_version'] ?? ''),
                ],
            )];
        }

        if ($scopeCount === 0) {
            return [new DecisionCandidate(
                type: 'capture_scope',
                reasonCode: 'confirmed_scope_missing',
                summary: '試験範囲を確定して、判断できる状態を作る',
                priority: 100,
                confidence: new Confidence(0.99),
                reasons: ['confirmed_scope_missing'],
                metadata: [
                    'target_scope_item_id' => null,
                    'target_task_id' => null,
                ],
            )];
        }

        $scopeStates = collect((array) data_get(
            $state->facts,
            'scope_item_states',
            [],
        ))
            ->filter(fn ($item) => is_array($item))
            ->values();

        $priorityScopes = $scopeStates
            ->sort(function (array $left, array $right) {
                $remaining = ((float) ($right['remaining_unit'] ?? 0))
                    <=> ((float) ($left['remaining_unit'] ?? 0));

                if ($remaining !== 0) {
                    return $remaining;
                }

                $leftMastery = is_numeric($left['mastery_score_percent'] ?? null)
                    ? (int) $left['mastery_score_percent']
                    : -1;
                $rightMastery = is_numeric($right['mastery_score_percent'] ?? null)
                    ? (int) $right['mastery_score_percent']
                    : -1;

                return $leftMastery <=> $rightMastery;
            })
            ->values();

        $candidates = [];
        $pressure = (string) data_get(
            $state->facts,
            'deadline_pressure',
            'unknown',
        );

        if (in_array($pressure, ['high', 'overdue'], true)) {
            $target = $priorityScopes->first(
                fn (array $item) => (float) ($item['remaining_unit'] ?? 0) > 0.05,
            );

            if (is_array($target)) {
                $candidates[] = $this->scopeCandidate(
                    'reduce_deadline_risk',
                    $pressure === 'overdue'
                        ? 'exam_deadline_passed'
                        : 'remaining_load_pressure_high',
                    '期限に対して残り負荷が大きいため、最も重い範囲を先に進める',
                    98,
                    max(0.70, $readiness->confidence->value),
                    $target,
                    [$pressure === 'overdue'
                        ? 'exam_deadline_passed'
                        : 'remaining_load_pressure_high'],
                );
            }
        }

        $unobserved = $priorityScopes->first(
            fn (array $item) => ! (bool) ($item['observed'] ?? false),
        );

        if (is_array($unobserved)) {
            $candidates[] = $this->scopeCandidate(
                'establish_scope_baseline',
                'coverage_below_target',
                'まだ観測できていない試験範囲の現在地を確認する',
                92,
                max(0.72, $readiness->confidence->value),
                $unobserved,
                ['coverage_below_target'],
            );
        }

        $masteryGap = $priorityScopes->first(function (array $item) {
            if (! (bool) ($item['observed'] ?? false)) {
                return false;
            }

            $mastery = $item['mastery_score_percent'] ?? null;

            return $mastery === null
                || (is_numeric($mastery)
                    && (int) $mastery < StudyExamReadinessEvaluator::MASTERY_TARGET);
        });

        if (is_array($masteryGap)) {
            $mastery = is_numeric($masteryGap['mastery_score_percent'] ?? null)
                ? (int) $masteryGap['mastery_score_percent']
                : null;

            $candidates[] = $this->scopeCandidate(
                'reinforce_scope_mastery',
                $mastery === null
                    ? 'mastery_unmeasured'
                    : 'mastery_below_target',
                $mastery === null
                    ? '観測はあるが理解度を測り切れていない範囲を再確認する'
                    : '理解度が目標に届いていない範囲を優先して補強する',
                $mastery !== null && $mastery < 50 ? 90 : 86,
                max(0.66, $readiness->confidence->value),
                $masteryGap,
                [$mastery === null
                    ? 'mastery_unmeasured'
                    : 'mastery_below_target'],
            );
        }

        $retentionGap = $priorityScopes->first(function (array $item) {
            $mastery = $item['mastery_score_percent'] ?? null;
            $retention = $item['retention_score_percent'] ?? null;

            return is_numeric($mastery)
                && (int) $mastery >= StudyExamReadinessEvaluator::MASTERY_TARGET
                && (
                    $retention === null
                    || (is_numeric($retention)
                        && (int) $retention < StudyExamReadinessEvaluator::RETENTION_TARGET)
                );
        });

        if (is_array($retentionGap)) {
            $retention = is_numeric($retentionGap['retention_score_percent'] ?? null)
                ? (int) $retentionGap['retention_score_percent']
                : null;

            $candidates[] = $this->scopeCandidate(
                'verify_scope_retention',
                $retention === null
                    ? 'retention_unverified'
                    : 'retention_below_target',
                $retention === null
                    ? '理解できた範囲が時間を空けても残っているか確認する'
                    : '定着が弱い範囲を短く再確認する',
                78,
                max(0.64, $readiness->confidence->value),
                $retentionGap,
                [$retention === null
                    ? 'retention_unverified'
                    : 'retention_below_target'],
            );
        }

        if ((bool) data_get($state->facts, 'exam_date_conflict', false)) {
            $candidates[] = new DecisionCandidate(
                type: 'resolve_exam_date',
                reasonCode: 'exam_date_conflict',
                summary: '試験日が複数候補に分かれているため確認する',
                priority: 60,
                confidence: new Confidence(0.98),
                reasons: ['exam_date_conflict'],
                metadata: [
                    'target_scope_item_id' => null,
                    'target_task_id' => null,
                ],
            );
        } elseif (data_get($state->metrics, 'days_until_exam') === null) {
            $candidates[] = new DecisionCandidate(
                type: 'resolve_exam_date',
                reasonCode: 'exam_date_unknown',
                summary: '試験日を確認して残り負荷の判断精度を上げる',
                priority: 36,
                confidence: new Confidence(0.90),
                reasons: ['exam_date_unknown'],
                metadata: [
                    'target_scope_item_id' => null,
                    'target_task_id' => null,
                ],
            );
        }

        if ($readiness->level === ReadinessLevel::Ready) {
            $target = $priorityScopes->first();

            $candidates[] = $this->scopeCandidate(
                'maintain_readiness',
                'readiness_target_met',
                '準備度を維持するため、最も残り負荷が大きい範囲を軽く仕上げ確認する',
                50,
                max(0.70, $readiness->confidence->value),
                is_array($target) ? $target : [],
                ['readiness_target_met'],
            );
        }

        if ($candidates === []) {
            $target = $priorityScopes->first();

            $candidates[] = $this->scopeCandidate(
                'continue_study',
                'no_dominant_gap',
                '現在の状態を維持しながら追加Evidenceを集める',
                30,
                max(0.55, $readiness->confidence->value),
                is_array($target) ? $target : [],
                ['no_dominant_gap'],
            );
        }

        usort(
            $candidates,
            fn (DecisionCandidate $left, DecisionCandidate $right): int =>
                $right->priority <=> $left->priority
                ?: $right->confidence->value <=> $left->confidence->value
                ?: strcmp($left->type, $right->type),
        );

        return array_values($candidates);
    }

    private function scopeCandidate(
        string $type,
        string $reasonCode,
        string $summary,
        int $priority,
        float $confidence,
        array $scope,
        array $reasons,
    ): DecisionCandidate {
        $taskId = collect((array) ($scope['candidate_task_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->first(fn ($id) => $id > 0);

        return new DecisionCandidate(
            type: $type,
            reasonCode: $reasonCode,
            summary: $summary,
            priority: $priority,
            confidence: new Confidence(min(0.99, max(0.0, $confidence))),
            reasons: $reasons,
            metadata: [
                'target_scope_item_id' => isset($scope['scope_item_id'])
                    ? (int) $scope['scope_item_id']
                    : null,
                'target_task_id' => $taskId ?: null,
                'subject' => $this->nullableString($scope['subject'] ?? null),
                'unit' => $this->nullableString($scope['unit'] ?? null),
                'remaining_unit' => isset($scope['remaining_unit'])
                    ? round((float) $scope['remaining_unit'], 2)
                    : null,
                'mastery_score_percent' => is_numeric(
                    $scope['mastery_score_percent'] ?? null
                )
                    ? (int) $scope['mastery_score_percent']
                    : null,
                'retention_score_percent' => is_numeric(
                    $scope['retention_score_percent'] ?? null
                )
                    ? (int) $scope['retention_score_percent']
                    : null,
            ],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, 255)
            : null;
    }
}
