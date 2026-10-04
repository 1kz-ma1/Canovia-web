<?php

namespace App\Intelligence\Career;

use App\Intelligence\Contracts\CandidateDecisionEngine;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\DecisionCandidate;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use InvalidArgumentException;

final class CareerDecisionEngine implements CandidateDecisionEngine
{
    public function decide(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        array $context = [],
    ): Decision {
        $candidates = $this->candidates(
            $state,
            $readiness,
            $context,
        );
        $selected = $candidates[0];

        return $selected->toDecision(
            IntelligenceFingerprint::decisionInput(
                $state,
                $readiness,
            ),
            [
                'policy_version' => 'career_process_action_v1',
                'candidate_count' => count($candidates),
                'candidate_types' => array_values(array_map(
                    fn (DecisionCandidate $candidate) =>
                        $candidate->type,
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
        if ($state->domain !== IntelligenceDomain::Career) {
            throw new InvalidArgumentException(
                'CareerDecisionEngine only supports the career domain.',
            );
        }

        $hasSignal = (bool) data_get(
            $state->facts,
            'has_career_signal',
            false,
        );

        if (! $hasSignal) {
            return [$this->candidate(
                type: 'capture_career_signal',
                reasonCode: 'career_signal_missing',
                summary:
                    '求人・応募・選考の事実を1つ残し、現在地を判断できる状態を作る',
                priority: 100,
                readiness: $readiness,
            )];
        }

        $candidates = [];
        $reviewDue = data_get($state->facts, 'review_due');

        if (is_array($reviewDue)) {
            $candidates[] = $this->candidate(
                type: 'complete_interview_review',
                reasonCode: 'interview_review_due',
                summary:
                    '面接の記憶が新しいうちに振り返りを残し、次の選考へ学びをつなぐ',
                priority: 100,
                readiness: $readiness,
                targetApplicationId: $this->intOrNull(
                    $reviewDue['application_id'] ?? null,
                ),
                targetSelectionEventId: $this->intOrNull(
                    $reviewDue['event_id'] ?? null,
                ),
                targetTaskId: $this->intOrNull(
                    $reviewDue['task_id'] ?? null,
                ),
                careerStage: $this->stringOrNull(
                    $reviewDue['stage'] ?? null,
                ),
            );
        }

        $nextInterview = data_get(
            $state->facts,
            'next_interview',
        );

        if (is_array($nextInterview)) {
            $hours = data_get(
                $state->metrics,
                'hours_until_next_interview',
            );
            $urgent = is_numeric($hours)
                && (int) $hours <= 72;

            $candidates[] = $this->candidate(
                type: 'prepare_interview',
                reasonCode: $urgent
                    ? 'interview_due_soon'
                    : 'interview_upcoming',
                summary: $urgent
                    ? '直近の面接に向けて準備項目を確認する'
                    : '予定済みの面接に向けて準備を進める',
                priority: $urgent ? 96 : 86,
                readiness: $readiness,
                targetApplicationId: $this->intOrNull(
                    $nextInterview['application_id'] ?? null,
                ),
                targetSelectionEventId: $this->intOrNull(
                    $nextInterview['event_id'] ?? null,
                ),
                targetTaskId: $this->intOrNull(
                    $nextInterview['task_id'] ?? null,
                ),
                careerStage: $this->stringOrNull(
                    $nextInterview['stage'] ?? null,
                ),
            );
        }

        $offerId = collect(
            (array) data_get(
                $state->facts,
                'offer_application_ids',
                [],
            ),
        )
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        if ($offerId) {
            $candidates[] = $this->candidate(
                type: 'review_offer_conditions',
                reasonCode: 'offer_requires_user_review',
                summary:
                    'オファーの条件・期限・確認事項を整理し、本人が判断できる状態にする',
                priority: 94,
                readiness: $readiness,
                targetApplicationId: $offerId,
                careerStage: 'offer',
            );
        }

        $pendingCaptureId = collect(
            (array) data_get(
                $state->facts,
                'pending_capture_ids',
                [],
            ),
        )
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        if ($pendingCaptureId) {
            $candidates[] = $this->candidate(
                type: 'organize_capture',
                reasonCode: 'pending_capture_unorganized',
                summary:
                    '未整理の求人・応募CaptureをCareer Pipelineへ接続する',
                priority: 90,
                readiness: $readiness,
                targetCaptureId: $pendingCaptureId,
            );
        }

        $applicationCount = max(
            0,
            (int) data_get(
                $state->metrics,
                'application_count',
                0,
            ),
        );

        if ($applicationCount === 0) {
            $candidates[] = $this->candidate(
                type: 'structure_first_application',
                reasonCode: 'application_pipeline_missing',
                summary:
                    'Career Captureまたは現在の候補を応募先として整理し、Pipelineの現在地を作る',
                priority: 84,
                readiness: $readiness,
                targetCaptureId: $pendingCaptureId ?: null,
            );
        }

        $candidateApplicationId = collect(
            (array) data_get(
                $state->facts,
                'candidate_application_ids',
                [],
            ),
        )
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        if ($candidateApplicationId) {
            $candidates[] = $this->candidate(
                type: 'advance_application',
                reasonCode: 'application_preparation_active',
                summary:
                    '準備中の応募先について、次の提出・選考準備を整理する',
                priority: 78,
                readiness: $readiness,
                targetApplicationId: $candidateApplicationId,
                careerStage: 'preparing',
            );
        }

        $waitingEventId = collect(
            (array) data_get(
                $state->facts,
                'result_waiting_event_ids',
                [],
            ),
        )
            ->map(fn ($id) => (int) $id)
            ->first(fn (int $id) => $id > 0);

        if ($waitingEventId) {
            $candidates[] = $this->candidate(
                type: 'review_pipeline',
                reasonCode: 'selection_result_waiting',
                summary:
                    '結果待ちは確定させず、Career Pipeline全体の現在地を確認する',
                priority: 58,
                readiness: $readiness,
                targetSelectionEventId: $waitingEventId,
            );
        }

        if ($candidates === []) {
            $candidates[] = $this->candidate(
                type: 'review_pipeline',
                reasonCode: 'no_dominant_career_gap',
                summary:
                    'Career Pipelineを確認し、次に動かす選考または準備を選ぶ',
                priority: 50,
                readiness: $readiness,
            );
        }

        usort(
            $candidates,
            fn (
                DecisionCandidate $left,
                DecisionCandidate $right,
            ): int =>
                ($right->priority <=> $left->priority)
                ?: (
                    $right->confidence->value
                    <=> $left->confidence->value
                )
                ?: strcmp($left->type, $right->type),
        );

        return array_values($candidates);
    }

    private function candidate(
        string $type,
        string $reasonCode,
        string $summary,
        int $priority,
        ReadinessAssessment $readiness,
        ?int $targetCaptureId = null,
        ?int $targetApplicationId = null,
        ?int $targetSelectionEventId = null,
        ?int $targetTaskId = null,
        ?string $careerStage = null,
    ): DecisionCandidate {
        return new DecisionCandidate(
            type: $type,
            reasonCode: $reasonCode,
            summary: $summary,
            priority: $priority,
            confidence: new Confidence(max(
                0.45,
                min(0.95, $readiness->confidence->value),
            )),
            reasons: [$reasonCode],
            metadata: [
                'target_capture_id' => $targetCaptureId,
                'target_application_id' => $targetApplicationId,
                'target_selection_event_id' =>
                    $targetSelectionEventId,
                'target_task_id' => $targetTaskId,
                'career_stage' => $careerStage,
            ],
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false || (int) $value <= 0
            ? null
            : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : mb_substr($value, 0, 64);
    }
}
