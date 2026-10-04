<?php

namespace App\Intelligence\Career;

use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use InvalidArgumentException;

final class CareerProcessReadinessEvaluator implements ReadinessEvaluator
{
    public function evaluate(StateSnapshot $state): ReadinessAssessment
    {
        if ($state->domain !== IntelligenceDomain::Career) {
            throw new InvalidArgumentException(
                'CareerProcessReadinessEvaluator only supports the career domain.',
            );
        }

        $hasSignal = (bool) data_get(
            $state->facts,
            'has_career_signal',
            false,
        );

        if (! $hasSignal) {
            return new ReadinessAssessment(
                score: null,
                level: ReadinessLevel::Unknown,
                confidence: new Confidence(0.2),
                components: [
                    'career_signal_count' => 0,
                    'application_count' => 0,
                    'pending_capture_count' => 0,
                    'review_due_count' => 0,
                ],
                gaps: [[
                    'code' => 'career_signal_missing',
                    'dimension' => 'evidence',
                    'severity' => 'medium',
                ]],
                metadata: [
                    'policy' => 'career_process_readiness_v1',
                    'meaning' =>
                        'career_process_observability_not_employability',
                ],
            );
        }

        $applications = max(
            0,
            (int) data_get($state->metrics, 'application_count', 0),
        );
        $captures = max(
            0,
            (int) data_get($state->metrics, 'capture_count', 0),
        );
        $pendingCaptures = max(
            0,
            (int) data_get(
                $state->metrics,
                'pending_capture_count',
                0,
            ),
        );
        $scheduledInterviews = max(
            0,
            (int) data_get(
                $state->metrics,
                'scheduled_interview_count',
                0,
            ),
        );
        $reviewDue = max(
            0,
            (int) data_get($state->metrics, 'review_due_count', 0),
        );
        $taskEvidence = max(
            0,
            (int) data_get(
                $state->metrics,
                'career_task_evidence_count',
                0,
            ),
        );

        $gaps = [];

        if ($reviewDue > 0) {
            $gaps[] = [
                'code' => 'interview_review_due',
                'dimension' => 'learning_loop',
                'severity' => 'high',
                'count' => $reviewDue,
            ];
        }

        if ($pendingCaptures > 0) {
            $gaps[] = [
                'code' => 'pending_capture_unorganized',
                'dimension' => 'capture',
                'severity' => 'medium',
                'count' => $pendingCaptures,
            ];
        }

        if ($applications === 0) {
            $gaps[] = [
                'code' => 'application_pipeline_missing',
                'dimension' => 'pipeline',
                'severity' => 'medium',
            ];
        }

        $confidence = min(
            0.9,
            0.4
                + min(0.2, $captures * 0.04)
                + min(0.2, $applications * 0.05)
                + min(0.06, $scheduledInterviews * 0.03)
                + min(0.04, $taskEvidence * 0.02),
        );

        return new ReadinessAssessment(
            score: null,
            level: ReadinessLevel::Developing,
            confidence: new Confidence(round($confidence, 4)),
            components: [
                'career_signal_count' =>
                    $captures + $applications + $taskEvidence,
                'capture_count' => $captures,
                'application_count' => $applications,
                'pending_capture_count' => $pendingCaptures,
                'scheduled_interview_count' => $scheduledInterviews,
                'review_due_count' => $reviewDue,
                'result_waiting_count' => max(
                    0,
                    (int) data_get(
                        $state->metrics,
                        'result_waiting_count',
                        0,
                    ),
                ),
                'offer_application_count' => max(
                    0,
                    (int) data_get(
                        $state->metrics,
                        'offer_application_count',
                        0,
                    ),
                ),
            ],
            gaps: $gaps,
            metadata: [
                'policy' => 'career_process_readiness_v1',
                'meaning' =>
                    'career_process_observability_not_employability',
            ],
        );
    }
}
