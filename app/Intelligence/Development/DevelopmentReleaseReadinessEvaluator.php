<?php

namespace App\Intelligence\Development;

use App\Intelligence\Contracts\ReadinessEvaluator;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use InvalidArgumentException;

final class DevelopmentReleaseReadinessEvaluator implements ReadinessEvaluator
{
    public const WEIGHTS = [
        'implementation' => 20,
        'ci' => 20,
        'review' => 15,
        'merge' => 15,
        'deploy' => 15,
        'verification' => 10,
        'spec_sync' => 5,
    ];

    public function evaluate(StateSnapshot $state): ReadinessAssessment
    {
        if ($state->domain !== IntelligenceDomain::Development) {
            throw new InvalidArgumentException(
                'DevelopmentReleaseReadinessEvaluator only supports the development domain.',
            );
        }

        $focus = data_get($state->facts, 'focus_task_state');
        if (! is_array($focus)) {
            return new ReadinessAssessment(
                score: null,
                level: ReadinessLevel::Unknown,
                confidence: new Confidence(0.15),
                components: [
                    'focus_task_id' => null,
                    'gates' => [],
                ],
                gaps: [[
                    'code' => 'development_evidence_missing',
                    'dimension' => 'evidence',
                    'severity' => 'high',
                ]],
                metadata: [
                    'policy' => 'development_release_readiness_v1',
                    'meaning' => 'observed_release_gate_readiness',
                ],
            );
        }

        $gates = (array) ($focus['gates'] ?? []);
        $score = 0;
        $observed = 0;
        $failed = false;
        $components = [];

        foreach (self::WEIGHTS as $gate => $weight) {
            $status = (string) data_get($gates, $gate.'.status', 'unknown');

            if ($status === 'passed') {
                $score += $weight;
            }

            if ($status !== 'unknown') {
                $observed++;
            }

            if ($status === 'failed') {
                $failed = true;
            }

            $components[$gate] = [
                'status' => $status,
                'weight' => $weight,
                'score' => $status === 'passed' ? $weight : 0,
                'source_type' => data_get($gates, $gate.'.source_type'),
                'occurred_at' => data_get($gates, $gate.'.occurred_at'),
            ];
        }

        $gaps = $this->gaps($gates);
        $ready = collect(array_keys(self::WEIGHTS))
            ->every(fn (string $gate) =>
                data_get($gates, $gate.'.status', 'unknown') === 'passed'
            );

        $confidence = min(
            0.95,
            0.25
                + (($observed / count(self::WEIGHTS)) * 0.55)
                + min(
                    0.15,
                    ((int) ($focus['evidence_count'] ?? 0) / 10) * 0.15,
                ),
        );

        $level = match (true) {
            $ready => ReadinessLevel::Ready,
            $failed => ReadinessLevel::Blocked,
            default => ReadinessLevel::Developing,
        };

        return new ReadinessAssessment(
            score: max(0, min(100, $score)),
            level: $level,
            confidence: new Confidence(round($confidence, 4)),
            components: [
                'focus_task_id' => (int) ($focus['task_id'] ?? 0),
                'gates' => $components,
                'passed_gate_count' => collect($components)
                    ->where('status', 'passed')
                    ->count(),
                'observed_gate_count' => $observed,
            ],
            gaps: $gaps,
            metadata: [
                'policy' => 'development_release_readiness_v1',
                'meaning' => 'observed_release_gate_readiness',
                'target_score_percent' => 100,
            ],
        );
    }

    /**
     * @param array<string,mixed> $gates
     * @return array<int,array<string,mixed>>
     */
    private function gaps(array $gates): array
    {
        $gaps = [];

        foreach ([
            'implementation' => ['implementation_missing', 'implementation_in_progress', 'implementation_failed', 'implementation'],
            'ci' => ['ci_unverified', 'ci_pending', 'ci_failed', 'tests'],
            'review' => ['review_unverified', 'review_pending', 'review_changes_requested', 'review'],
            'merge' => ['merge_unverified', 'merge_pending', 'merge_failed', 'merge'],
            'deploy' => ['production_deploy_missing', 'production_deploy_pending', 'production_deploy_failed', 'deploy'],
            'verification' => ['verification_unconfirmed', 'verification_pending', 'verification_failed', 'verification'],
            'spec_sync' => ['spec_sync_unconfirmed', 'spec_sync_pending', 'spec_sync_failed', 'spec_sync'],
        ] as $gate => [$unknownCode, $pendingCode, $failedCode, $dimension]) {
            $status = (string) data_get($gates, $gate.'.status', 'unknown');

            if ($status === 'passed') {
                continue;
            }

            $severity = match ($gate) {
                'implementation', 'ci', 'verification' => 'high',
                'review', 'merge', 'deploy' => 'medium',
                default => 'low',
            };

            if ($status === 'failed') {
                $severity = in_array($gate, ['ci', 'review', 'deploy', 'verification'], true)
                    ? 'high'
                    : 'medium';
            }

            $gaps[] = [
                'code' => match ($status) {
                    'failed' => $failedCode,
                    'pending' => $pendingCode,
                    default => $unknownCode,
                },
                'dimension' => $dimension,
                'severity' => $severity,
                'status' => $status,
            ];
        }

        return $gaps;
    }
}
