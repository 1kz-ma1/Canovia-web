<?php

namespace App\Intelligence\Study;

use App\Intelligence\Contracts\ActionGenerator;
use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use InvalidArgumentException;

final class StudyAdaptiveActionGenerator implements ActionGenerator
{
    /**
     * @return array<int,ActionProposal>
     */
    public function generate(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        Decision $decision,
    ): array {
        if ($state->domain !== IntelligenceDomain::Study) {
            throw new InvalidArgumentException(
                'StudyAdaptiveActionGenerator only supports the study domain.',
            );
        }

        $targetTaskId = $this->intOrNull(
            data_get($decision->metadata, 'target_task_id'),
        );
        $subject = $this->stringOrNull(
            data_get($decision->metadata, 'subject'),
        );
        $unit = $this->stringOrNull(
            data_get($decision->metadata, 'unit'),
        );
        $label = $this->scopeLabel($subject, $unit);
        $readinessScore = $readiness->score;
        $pressure = (string) data_get(
            $state->facts,
            'deadline_pressure',
            'unknown',
        );

        [$kind, $title, $intent, $routeKind, $successSignals] = match ($decision->type) {
            'establish_official_exam_baseline' => [
                'study_official_exam_baseline',
                '最初の演習で理解度を確認する',
                '応用情報技術者試験の公式シラバスはIPAが公開済みです。'
                    .'科目A・科目Bの必要範囲をユーザーが決め直す必要はありません。'
                    .'まず演習結果から現在地を把握しましょう。'
                    .'なお、公式の試験範囲とCanoviaに登録した学習単元は別の情報です。',
                $targetTaskId ? 'study_practice' : 'project_task',
                ['practice_evidence_observed'],
            ],
            'capture_scope' => [
                'study_scope_capture',
                '試験範囲を確定する',
                '試験範囲がまだ確定していないため、先に対象範囲を決めてCanoviaが現在地を判断できる状態にします。',
                'study_scope',
                ['confirmed_scope_available'],
            ],
            'reduce_deadline_risk' => [
                'study_deadline_recovery',
                $label !== null
                    ? $label.'を先に進める'
                    : '残り負荷が大きい範囲を先に進める',
                $this->deadlineIntent($state, $label, $pressure),
                $targetTaskId ? 'study_practice' : 'project_task',
                ['remaining_effort_reduced', 'deadline_pressure_reduced'],
            ],
            'establish_scope_baseline' => [
                'study_baseline_check',
                $label !== null
                    ? $label.'の現在地を確認する'
                    : '未観測の試験範囲を確認する',
                ($label !== null ? $label.'は' : 'この範囲は')
                    .'まだ十分な学習Evidenceがないため、最初の演習で理解度を確認します。',
                $targetTaskId ? 'study_practice' : 'project_task',
                ['scope_practice_observed'],
            ],
            'reinforce_scope_mastery' => [
                'study_mastery_reinforcement',
                $label !== null
                    ? $label.'の弱点を補強する'
                    : '理解度が低い範囲を補強する',
                $this->masteryIntent($label, $decision),
                $targetTaskId ? 'study_practice' : 'project_task',
                ['scope_mastery_target_met'],
            ],
            'verify_scope_retention' => [
                'study_retention_check',
                $label !== null
                    ? $label.'が定着しているか確認する'
                    : '理解した内容の定着を確認する',
                $this->retentionIntent($label, $decision),
                $targetTaskId ? 'study_recall' : 'project_task',
                ['scope_retention_target_met'],
            ],
            'resolve_exam_date' => [
                'study_exam_date_review',
                '試験日を確認する',
                $decision->reasonCode === 'exam_date_conflict'
                    ? '確定済みの範囲資料で試験日が食い違っているため、正しい日付を確認して残り負荷の判断を安定させます。'
                    : '試験日が未確定のため、日付を確認すると残り負荷と優先順位の精度が上がります。',
                'study_scope',
                ['exam_date_resolved'],
            ],
            'maintain_readiness' => [
                'study_readiness_maintenance',
                $label !== null
                    ? $label.'を仕上げ確認する'
                    : '仕上げ確認を1セット行う',
                '現在の準備度'.($readinessScore !== null ? $readinessScore.'点' : '')
                    .'を維持するため、残り負荷が最も大きい範囲を短く再確認します。',
                $targetTaskId ? 'study_practice' : 'project_task',
                ['readiness_maintained'],
            ],
            default => [
                'study_continue',
                $label !== null
                    ? $label.'を続ける'
                    : '学習を続けてEvidenceを増やす',
                '現在は突出したGapがないため、次のEvidenceを増やして判断精度を高めます。',
                $targetTaskId ? 'study_practice' : 'project_task',
                ['study_evidence_added'],
            ],
        };

        return [new ActionProposal(
            kind: $kind,
            title: $title,
            intent: $intent,
            confidence: $decision->confidence,
            estimatedMinutes: null,
            successSignals: $successSignals,
            metadata: [
                'policy_version' => 'study_action_generator_v1',
                'official_source_url' => data_get($decision->metadata, 'official_source_url'),
                'official_syllabus_version' => data_get($decision->metadata, 'official_syllabus_version'),
                'reason_code' => $decision->reasonCode,
                'target_scope_item_id' => $this->intOrNull(
                    data_get($decision->metadata, 'target_scope_item_id'),
                ),
                'target_task_id' => $targetTaskId,
                'route_kind' => $routeKind,
                'subject' => $subject,
                'unit' => $unit,
                'remaining_unit' => data_get(
                    $decision->metadata,
                    'remaining_unit',
                ),
                'mastery_score_percent' => data_get(
                    $decision->metadata,
                    'mastery_score_percent',
                ),
                'retention_score_percent' => data_get(
                    $decision->metadata,
                    'retention_score_percent',
                ),
                'readiness_score' => $readiness->score,
                'deadline_pressure' => $pressure,
                'projection_policy' => 'task_optional_user_triggered',
            ],
        )];
    }

    private function deadlineIntent(
        StateSnapshot $state,
        ?string $label,
        string $pressure,
    ): string {
        $days = data_get($state->metrics, 'days_until_exam');
        $remaining = data_get($state->metrics, 'remaining_effort_percent');

        $parts = [];
        if (is_numeric($days)) {
            $parts[] = (int) $days >= 0
                ? '試験まで'.(int) $days.'日'
                : '試験日を過ぎています';
        }
        if (is_numeric($remaining)) {
            $parts[] = '残り負荷'.(int) $remaining.'%';
        }
        if ($label !== null) {
            $parts[] = $label.'の残り負荷が大きい';
        }

        return implode('・', $parts)
            .'ため、'.($pressure === 'overdue' ? '未消化範囲を整理し直します。' : '最優先で1つ進めます。');
    }

    private function masteryIntent(
        ?string $label,
        Decision $decision,
    ): string {
        $mastery = data_get(
            $decision->metadata,
            'mastery_score_percent',
        );

        if (is_numeric($mastery)) {
            return ($label !== null ? $label.'の' : '')
                .'Masteryが'.(int) $mastery.'%で目標80%に届いていないため、弱点を補強します。';
        }

        return ($label !== null ? $label.'は' : 'この範囲は')
            .'観測済みですがMasteryを十分に測れていないため、追加演習で確認します。';
    }

    private function retentionIntent(
        ?string $label,
        Decision $decision,
    ): string {
        $retention = data_get(
            $decision->metadata,
            'retention_score_percent',
        );

        if (is_numeric($retention)) {
            return ($label !== null ? $label.'の' : '')
                .'Retentionが'.(int) $retention.'%で目標70%を下回るため、時間を空けた再確認を優先します。';
        }

        return ($label !== null ? $label.'は' : 'この範囲は')
            .'理解度は十分ですが、時間を空けた定着確認がまだないため再確認します。';
    }

    private function scopeLabel(?string $subject, ?string $unit): ?string
    {
        if ($subject === null && $unit === null) {
            return null;
        }

        if ($subject !== null && $unit !== null) {
            return $subject.'「'.$unit.'」';
        }

        return $unit ?? $subject;
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
        return is_scalar($value) && trim((string) $value) !== ''
            ? mb_substr(trim((string) $value), 0, 255)
            : null;
    }
}
