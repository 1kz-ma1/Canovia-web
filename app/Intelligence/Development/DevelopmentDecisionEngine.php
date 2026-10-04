<?php

namespace App\Intelligence\Development;

use App\Intelligence\Contracts\CandidateDecisionEngine;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\DecisionCandidate;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Support\IntelligenceFingerprint;
use InvalidArgumentException;

final class DevelopmentDecisionEngine implements CandidateDecisionEngine
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
                'policy_version' => 'development_release_action_v1',
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
        if ($state->domain !== IntelligenceDomain::Development) {
            throw new InvalidArgumentException(
                'DevelopmentDecisionEngine only supports the development domain.',
            );
        }

        $focus = data_get($state->facts, 'focus_task_state');
        if (! is_array($focus)) {
            return [new DecisionCandidate(
                type: 'connect_development_evidence',
                reasonCode: 'development_evidence_missing',
                summary: 'GitHub項目をTaskへ結び、現在状態を観測できるようにする',
                priority: 100,
                confidence: new Confidence(0.95),
                reasons: ['development_evidence_missing'],
                metadata: [
                    'target_task_id' => null,
                    'target_gate' => 'evidence',
                    'gate_status' => 'unknown',
                ],
            )];
        }

        $taskId = (int) ($focus['task_id'] ?? 0);
        $gates = (array) ($focus['gates'] ?? []);
        $candidates = [];

        $failedPolicy = [
            'ci' => [
                'fix_ci',
                'ci_failed',
                '失敗しているCIを直す',
                100,
            ],
            'review' => [
                'address_review_feedback',
                'review_changes_requested',
                'レビュー指摘を解消する',
                98,
            ],
            'deploy' => [
                'fix_deployment',
                'production_deploy_failed',
                '失敗しているDeployを直す',
                96,
            ],
            'verification' => [
                'fix_verification',
                'verification_failed',
                '実機・本番確認で見つかった問題を直す',
                94,
            ],
            'merge' => [
                'replace_closed_pr',
                'merge_failed',
                '閉じたPRを見直し、Release経路を作り直す',
                92,
            ],
            'spec_sync' => [
                'sync_spec',
                'spec_sync_failed',
                '実装に合わせて仕様を同期する',
                90,
            ],
        ];

        foreach ($failedPolicy as $gate => [$type, $reason, $summary, $priority]) {
            if (data_get($gates, $gate.'.status') === 'failed') {
                $candidates[] = $this->candidate(
                    $type,
                    $reason,
                    $summary,
                    $priority,
                    $readiness,
                    $taskId,
                    $gate,
                    'failed',
                    $focus,
                );
            }
        }

        $sequence = [
            'implementation' => [
                'establish_implementation',
                'implementation_missing',
                '実装Evidenceを作り、Release候補を前へ進める',
                84,
            ],
            'ci' => [
                'establish_ci',
                'ci_unverified',
                'CIを実行し、テスト結果を確認する',
                82,
            ],
            'review' => [
                'obtain_review',
                'review_unverified',
                'Reviewを確認し、変更を第三者または明示的な確認へ通す',
                80,
            ],
            'merge' => [
                'merge_change',
                'merge_pending',
                'PRをmergeできる状態にする',
                78,
            ],
            'deploy' => [
                'deploy_release',
                'production_deploy_missing',
                'ProductionへのDeployを確認する',
                76,
            ],
            'verification' => [
                'verify_release',
                'verification_unconfirmed',
                '実機・本番環境で動作を確認する',
                74,
            ],
            'spec_sync' => [
                'sync_spec',
                'spec_sync_unconfirmed',
                '仕様と実装の同期状態を確認する',
                72,
            ],
        ];

        foreach ($sequence as $gate => [$type, $reason, $summary, $priority]) {
            $status = (string) data_get($gates, $gate.'.status', 'unknown');

            if ($status === 'passed' || $status === 'failed') {
                continue;
            }

            $candidates[] = $this->candidate(
                $type,
                $reason,
                $summary,
                $priority,
                $readiness,
                $taskId,
                $gate,
                $status,
                $focus,
            );
        }

        if ($candidates === []) {
            $candidates[] = $this->candidate(
                'release_ready',
                'all_release_gates_passed',
                'Release Ready。次の変更またはRelease判断へ進む',
                60,
                $readiness,
                $taskId,
                'release',
                'passed',
                $focus,
            );
        }

        usort(
            $candidates,
            fn (DecisionCandidate $left, DecisionCandidate $right): int =>
                ($right->priority <=> $left->priority)
                ?: ($right->confidence->value <=> $left->confidence->value)
                ?: strcmp($left->type, $right->type),
        );

        return $candidates;
    }

    private function candidate(
        string $type,
        string $reasonCode,
        string $summary,
        int $priority,
        ReadinessAssessment $readiness,
        int $taskId,
        string $gate,
        string $status,
        array $focus,
    ): DecisionCandidate {
        $confidence = max(
            0.45,
            min(0.95, $readiness->confidence->value),
        );

        return new DecisionCandidate(
            type: $type,
            reasonCode: $reasonCode,
            summary: $summary,
            priority: $priority,
            confidence: new Confidence($confidence),
            reasons: [$reasonCode],
            metadata: [
                'target_task_id' => $taskId > 0 ? $taskId : null,
                'target_gate' => $gate,
                'gate_status' => $status,
                'readiness_score' => $readiness->score,
                'pull_request_number' => $focus['pull_request_number'] ?? null,
                'deployment_environment' => $focus['deployment_environment'] ?? null,
            ],
        );
    }
}
