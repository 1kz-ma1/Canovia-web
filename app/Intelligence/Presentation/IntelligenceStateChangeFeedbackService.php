<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\Plan;
use App\Models\TaskEvidence;

final class IntelligenceStateChangeFeedbackService
{
    private const SCORE_CHANGE_THRESHOLD = 5;

    /**
     * @return array<string,mixed>|null
     */
    public function latestForPlan(
        Plan $plan,
        IntelligenceDomain $domain,
    ): ?array {
        $traces = IntelligenceDecisionTrace::query()
            ->with('stateSnapshot')
            ->where('plan_id', $plan->id)
            ->where('domain', $domain->value)
            ->latest('created_at')
            ->latest('id')
            ->take(2)
            ->get();

        if ($traces->count() < 2) {
            return null;
        }

        /** @var IntelligenceDecisionTrace $current */
        $current = $traces->get(0);
        /** @var IntelligenceDecisionTrace $previous */
        $previous = $traces->get(1);

        $currentAction = $this->actionForTrace($current);
        $previousAction = $this->actionForTrace($previous);

        $scoreBefore = $previous->readiness_score;
        $scoreAfter = $current->readiness_score;
        $scoreDelta = $scoreBefore !== null && $scoreAfter !== null
            ? (int) $scoreAfter - (int) $scoreBefore
            : null;

        $levelBefore = $previous->readiness_level instanceof ReadinessLevel
            ? $previous->readiness_level->value
            : (string) $previous->getRawOriginal('readiness_level');
        $levelAfter = $current->readiness_level instanceof ReadinessLevel
            ? $current->readiness_level->value
            : (string) $current->getRawOriginal('readiness_level');

        $levelChanged = $levelBefore !== $levelAfter;
        $decisionChanged = (string) $previous->reason_code
            !== (string) $current->reason_code;

        $actionChanged = $currentAction instanceof IntelligenceActionProjection
            && $previousAction instanceof IntelligenceActionProjection
            && ! hash_equals(
                (string) $previousAction->action_fingerprint,
                (string) $currentAction->action_fingerprint,
            );

        $materialScoreChange = $scoreDelta !== null
            && abs($scoreDelta) >= self::SCORE_CHANGE_THRESHOLD;

        if (
            ! $levelChanged
            && ! $decisionChanged
            && ! $actionChanged
            && ! $materialScoreChange
        ) {
            return null;
        }

        $addedEvidence = $this->addedEvidence(
            $plan,
            $previous,
            $current,
        );

        return [
            'domain' => $domain->value,
            'plan' => $plan,
            'occurred_at' => $current->created_at,
            'kind' => $this->kind(
                $actionChanged,
                $decisionChanged,
                $levelChanged,
                $scoreDelta,
            ),
            'title' => $this->title(
                $actionChanged,
                $decisionChanged,
                $levelChanged,
                $scoreDelta,
            ),
            'readiness_before' => $scoreBefore,
            'readiness_after' => $scoreAfter,
            'readiness_delta' => $scoreDelta,
            'level_before' => $levelBefore,
            'level_after' => $levelAfter,
            'level_before_label' => $this->levelLabel(
                $domain,
                $levelBefore,
            ),
            'level_after_label' => $this->levelLabel(
                $domain,
                $levelAfter,
            ),
            'level_changed' => $levelChanged,
            'decision_before' => (string) $previous->decision_summary,
            'decision_after' => (string) $current->decision_summary,
            'decision_changed' => $decisionChanged,
            'reason_before' => (string) $previous->reason_code,
            'reason_after' => (string) $current->reason_code,
            'action_before' => $previousAction?->title,
            'action_after' => $currentAction?->title,
            'action_changed' => $actionChanged,
            'added_evidence_count' => $addedEvidence['count'],
            'added_evidence_labels' => $addedEvidence['labels'],
            'summary' => $this->summary(
                $scoreBefore,
                $scoreAfter,
                $scoreDelta,
                $decisionChanged,
                $actionChanged,
                $addedEvidence['count'],
                $addedEvidence['labels'],
            ),
        ];
    }

    private function actionForTrace(
        IntelligenceDecisionTrace $trace,
    ): ?IntelligenceActionProjection {
        return IntelligenceActionProjection::query()
            ->where(
                'intelligence_decision_trace_id',
                (int) $trace->id,
            )
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{count:int,labels:array<int,string>}
     */
    private function addedEvidence(
        Plan $plan,
        IntelligenceDecisionTrace $previous,
        IntelligenceDecisionTrace $current,
    ): array {
        // Snapshot references can be structured arrays; Collection::diff()
        // uses PHP array_diff() and crashes when comparing those values.
        $before = collect($previous->stateSnapshot?->evidence_references ?? [])
            ->filter(fn ($value) => is_string($value) || is_array($value))
            ->uniqueStrict();
        $after = collect($current->stateSnapshot?->evidence_references ?? [])
            ->filter(fn ($value) => is_string($value) || is_array($value))
            ->uniqueStrict();

        $added = $after->reject(
            fn ($value) => $before->containsStrict($value),
        )->values();
        $ids = $added
            ->map(function (mixed $reference): ?int {
                if (! is_string($reference)) {
                    return null;
                }

                if (! preg_match('/^task_evidence:(\d+)$/', $reference, $match)) {
                    return null;
                }

                return (int) $match[1];
            })
            ->filter(fn (?int $id) => $id !== null && $id > 0)
            ->values();

        if ($ids->isEmpty()) {
            return [
                'count' => $added->count(),
                'labels' => [],
            ];
        }

        $types = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $ids->all())
            ->pluck('type', 'id');

        $labels = $ids
            ->map(fn (int $id) => $this->evidenceLabel(
                (string) ($types[$id] ?? ''),
            ))
            ->filter()
            ->countBy()
            ->map(
                fn (int $count, string $label) =>
                    $count > 1 ? $label.' '.$count.'件' : $label,
            )
            ->values()
            ->take(3)
            ->all();

        return [
            'count' => $added->count(),
            'labels' => $labels,
        ];
    }

    private function evidenceLabel(string $type): string
    {
        return match ($type) {
            'study_practice_assessed' => 'Practice結果',
            'study_recall_reviewed' => 'Recall確認',
            'focus_session_completed',
            'focus_session_interrupted' => '作業実績',
            'github_commit_observed' => 'Commit',
            'github_branch_observed' => 'Branch',
            'github_issue_observed' => 'Issue',
            'pull_request_observed',
            'pull_request_merged' => 'Pull Request',
            'pull_request_ci_observed' => 'CI / Test',
            'pull_request_review_submitted' => 'Review',
            'github_deployment_observed' => 'Production Deploy',
            'development_quality_gate_confirmed' => 'Quality Gate確認',
            'interview_review_completed' => 'Interview Review',
            'interview_result_recorded' => 'Selection Result',
            default => 'Evidence',
        };
    }

    private function kind(
        bool $actionChanged,
        bool $decisionChanged,
        bool $levelChanged,
        ?int $scoreDelta,
    ): string {
        if ($actionChanged) {
            return 'action_changed';
        }

        if ($decisionChanged) {
            return 'decision_changed';
        }

        if ($levelChanged) {
            return 'level_changed';
        }

        return ($scoreDelta ?? 0) >= 0
            ? 'readiness_improved'
            : 'readiness_declined';
    }

    private function title(
        bool $actionChanged,
        bool $decisionChanged,
        bool $levelChanged,
        ?int $scoreDelta,
    ): string {
        if ($actionChanged) {
            return '次にやることが変わりました';
        }

        if ($decisionChanged) {
            return '最大Gapが変わりました';
        }

        if ($levelChanged) {
            return 'Readiness段階が変わりました';
        }

        return ($scoreDelta ?? 0) >= 0
            ? 'Readinessが前進しました'
            : 'Readinessを見直しました';
    }

    private function levelLabel(
        IntelligenceDomain $domain,
        string $level,
    ): string {
        if ($domain === IntelligenceDomain::Study) {
            return match ($level) {
                'ready' => '試験準備OK',
                'blocked' => '立て直しが必要',
                'developing' => '準備中',
                default => '判定準備中',
            };
        }

        if ($domain === IntelligenceDomain::Career) {
            return match ($level) {
                'ready' => '整理済み',
                'blocked' => '要整理',
                'developing' => '観測中',
                default => '未観測',
            };
        }

        return match ($level) {
            'ready' => 'Release Ready',
            'blocked' => 'Release Blocked',
            'developing' => 'Release準備中',
            default => '判定準備中',
        };
    }

    /**
     * @param array<int,string> $evidenceLabels
     */
    private function summary(
        ?int $scoreBefore,
        ?int $scoreAfter,
        ?int $scoreDelta,
        bool $decisionChanged,
        bool $actionChanged,
        int $addedEvidenceCount,
        array $evidenceLabels,
    ): string {
        $parts = [];

        if ($scoreBefore !== null && $scoreAfter !== null && $scoreDelta !== 0) {
            $parts[] = 'Readiness '.$scoreBefore.' → '.$scoreAfter;
        }

        if ($actionChanged) {
            $parts[] = 'Current Actionを更新';
        } elseif ($decisionChanged) {
            $parts[] = '優先Gapを更新';
        }

        if ($addedEvidenceCount > 0) {
            $label = $evidenceLabels !== []
                ? implode('・', $evidenceLabels)
                : '新しいEvidence';
            $parts[] = $label.'を反映';
        }

        return $parts !== []
            ? implode('。', $parts).'。'
            : '新しいStateを判断へ反映しました。';
    }
}
