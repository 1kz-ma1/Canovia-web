<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Data\StudyAdaptiveActionResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\Plan;
use App\Models\Task;

final class StudyIntelligencePresentationAdapter
{
    public function adapt(
        Plan $plan,
        StudyAdaptiveActionResult $result,
    ): ?PlanIntelligencePresentation {
        $action = $result->primaryAction();
        if (! $action) {
            return null;
        }

        $readiness = $result->intelligence->readiness;
        $state = $result->intelligence->state;
        $targetTask = $this->targetTask(
            $plan,
            data_get($action->metadata, 'target_task_id'),
        );

        return new PlanIntelligencePresentation(
            domain: IntelligenceDomain::Study,
            plan: $plan,
            state: $state,
            readiness: $readiness,
            decision: $result->decision,
            action: $action,
            eyebrow: 'STUDY INTELLIGENCE',
            headline: '試験に向けた現在地',
            sourceNote: is_array(data_get($state->facts, 'official_exam_reference'))
                ? 'IPA公式シラバスの出典と、未観測の本人の理解度を分けて判断しています。'
                : 'Task進捗ではなく、確定した試験範囲とPractice / Recall Evidenceから判断しています。',
            readinessLabel: 'Exam Readiness',
            stateLabel: $this->stateLabel($readiness->level->value),
            gapLabel: $this->gapLabel($result->decision->reasonCode),
            gapDetail: $this->gapDetail($result),
            detailUrl: route('plans.study_scope.index', $plan),
            actionUrl: route('plans.study_action.execute', $plan),
            actionMethod: 'POST',
            actionLabel: $this->actionLabel(
                (string) data_get($action->metadata, 'route_kind', ''),
                $action->kind,
            ),
            metrics: $this->metrics($result),
            targetTask: $targetTask,
            requiresTaskProjection: (
                (string) data_get($action->metadata, 'route_kind')
            ) === 'project_task',
            workspaceUrl: route('workspace.study.index', [
                'plan_id' => $plan->id,
            ]),
            workspaceLabel: 'Study Workspace',
        );
    }

    /**
     * @return array<int,array{label:string,value:string,detail:?string}>
     */
    private function metrics(StudyAdaptiveActionResult $result): array
    {
        $components = $result->intelligence->readiness->components;

        return [
            [
                'label' => '範囲',
                'value' => $this->percent(
                    data_get($components, 'coverage_percent'),
                ),
                'detail' => 'Coverage',
            ],
            [
                'label' => '理解',
                'value' => $this->percent(
                    data_get($components, 'mastery_score_percent'),
                ),
                'detail' => 'Mastery',
            ],
            [
                'label' => '定着',
                'value' => $this->percent(
                    data_get($components, 'retention_score_percent'),
                ),
                'detail' => 'Retention',
            ],
            [
                'label' => '残り負荷',
                'value' => $this->percent(
                    data_get($components, 'remaining_effort_percent'),
                ),
                'detail' => 'Remaining',
            ],
        ];
    }

    private function gapDetail(StudyAdaptiveActionResult $result): string
    {
        $metadata = $result->decision->metadata;
        $subject = trim((string) ($metadata['subject'] ?? ''));
        $unit = trim((string) ($metadata['unit'] ?? ''));

        $scope = collect([$subject, $unit])
            ->filter()
            ->join(' / ');

        return $scope !== ''
            ? $scope.'を優先対象として判断しています。'
            : $result->decision->summary;
    }

    private function gapLabel(string $reason): string
    {
        return match ($reason) {
            'confirmed_scope_missing' => '試験範囲が未確定',
            'official_scope_known_mastery_unmeasured' => '公式範囲は公開済み・理解度が未測定',
            'coverage_below_target' => '未観測の試験範囲が残っている',
            'mastery_unmeasured' => '理解度をまだ測り切れていない',
            'mastery_below_target' => '理解度が目標に届いていない',
            'retention_unverified' => '定着をまだ確認できていない',
            'retention_below_target' => '定着が弱い',
            'remaining_load_pressure_high' => '期限に対して残り負荷が大きい',
            'exam_deadline_passed' => '試験期限を超えて残りがある',
            'exam_date_conflict' => '試験日情報が競合している',
            'exam_date_unknown' => '試験日が未確定',
            'readiness_target_met' => '大きな不足なし・仕上げ段階',
            'no_dominant_gap' => '追加Evidenceが必要',
            default => '現在の最大Gapを確認中',
        };
    }

    private function stateLabel(string $level): string
    {
        return match ($level) {
            'ready' => '試験準備OK',
            'blocked' => '立て直しが必要',
            'developing' => '準備中',
            default => '判定準備中',
        };
    }

    private function actionLabel(string $routeKind, string $kind): string
    {
        if ($routeKind === 'study_scope') {
            return $kind === 'study_exam_date_review'
                ? '試験日を確認'
                : '試験範囲を確認';
        }

        return match ($kind) {
            'study_baseline_check' => '現在地を確認',
            'study_mastery_reinforcement' => '演習で補強',
            'study_retention_check' => '定着を確認',
            'study_deadline_recovery' => 'この範囲から進める',
            'study_readiness_maintenance' => '仕上げ確認',
            default => 'このActionで進める',
        };
    }

    private function targetTask(Plan $plan, mixed $value): ?Task
    {
        $taskId = filter_var($value, FILTER_VALIDATE_INT);

        if ($taskId === false || (int) $taskId <= 0) {
            return null;
        }

        return $plan->relationLoaded('tasks')
            ? $plan->tasks->firstWhere('id', (int) $taskId)
            : $plan->tasks()->whereKey((int) $taskId)->first();
    }

    private function percent(mixed $value): string
    {
        return is_numeric($value)
            ? max(0, min(100, (int) $value)).'%'
            : '未測定';
    }
}
