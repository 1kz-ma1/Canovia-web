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

final class StudyDecisionEngine implements CandidateDecisionEngine
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
                'policy_version' => 'study_decision_v1',
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
            throw new InvalidArgumentException('StudyDecisionEngine only supports the study domain.');
        }

        $attempts = max(0, (int) data_get($state->metrics, 'practice_attempt_count', 0));
        $recalls = max(0, (int) data_get($state->metrics, 'recall_review_count', 0));
        $latest = data_get($state->metrics, 'latest_score_percent');
        $weaknesses = collect((array) data_get($state->facts, 'observed_weaknesses', []))
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->unique()
            ->values()
            ->all();

        $gapCodes = collect($readiness->gaps)
            ->map(fn ($gap) => is_array($gap) ? ($gap['code'] ?? null) : null)
            ->filter()
            ->values()
            ->all();

        $candidates = [];

        if ($attempts === 0 || $latest === null) {
            $candidates[] = new DecisionCandidate(
                type: 'collect_baseline_evidence',
                reasonCode: 'practice_evidence_missing',
                summary: '基準となる演習結果を集める',
                priority: 100,
                confidence: new Confidence(0.95),
                reasons: ['practice_evidence_missing'],
            );
        }

        if ($weaknesses !== [] || (is_int($latest) && $latest < StudyReadinessEvaluator::TARGET_SCORE)) {
            $candidates[] = new DecisionCandidate(
                type: 'reinforce_observed_gap',
                reasonCode: $weaknesses !== []
                    ? 'observed_weaknesses'
                    : 'mastery_below_target',
                summary: '観測された弱点を優先して補強する',
                priority: is_int($latest) && $latest < 50 ? 92 : 85,
                confidence: new Confidence(max(0.55, min(0.92, $readiness->confidence->value + 0.05))),
                reasons: array_values(array_intersect(
                    $gapCodes,
                    ['mastery_below_target', 'observed_weaknesses'],
                )),
                metadata: [
                    'weakness_count' => count($weaknesses),
                ],
            );
        }

        if ($attempts > 0 && $attempts < 3) {
            $candidates[] = new DecisionCandidate(
                type: 'expand_practice_sample',
                reasonCode: 'practice_evidence_thin',
                summary: '演習を追加して状態推定の信頼度を上げる',
                priority: 72,
                confidence: new Confidence(0.88),
                reasons: ['practice_evidence_thin'],
            );
        }

        if ($attempts > 0 && $recalls < 1) {
            $candidates[] = new DecisionCandidate(
                type: 'verify_retention',
                reasonCode: 'retention_unverified',
                summary: '時間を空けて定着を確認する',
                priority: 64,
                confidence: new Confidence(0.82),
                reasons: ['retention_unverified'],
            );
        }

        if ($readiness->level === ReadinessLevel::Ready) {
            $candidates[] = new DecisionCandidate(
                type: 'advance_scope',
                reasonCode: 'readiness_target_met',
                summary: '次の範囲へ進みつつ定着を維持する',
                priority: 50,
                confidence: new Confidence(max(0.7, $readiness->confidence->value)),
                reasons: ['readiness_target_met'],
            );
        }

        if ($candidates === []) {
            $candidates[] = new DecisionCandidate(
                type: 'continue_observation',
                reasonCode: 'no_dominant_gap',
                summary: '現在の学習を継続し追加Evidenceを観測する',
                priority: 30,
                confidence: new Confidence(max(0.5, $readiness->confidence->value)),
                reasons: ['no_dominant_gap'],
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
}
