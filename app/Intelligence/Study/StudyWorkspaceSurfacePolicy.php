<?php

namespace App\Intelligence\Study;

use App\Intelligence\Presentation\PlanIntelligencePresentation;
use App\Models\Plan;
use App\Models\Task;

final class StudyWorkspaceSurfacePolicy
{
    public function __construct(
        private readonly StudyWorkspaceSurfaceRegistry $registry,
    ) {}

    /**
     * @param array<string,mixed> $learningType
     * @param array<string,mixed> $state
     * @return array{
     *   surfaces:array<int,array<string,mixed>>,
     *   blocks_execution:bool,
     *   primary_action:array<string,mixed>
     * }
     */
    public function compose(
        Plan $plan,
        array $learningType,
        array $state,
        ?PlanIntelligencePresentation $presentation,
        ?Task $navigationTask,
        bool $canEdit,
        ?array $recommendation = null,
        ?array $methodRecommendation = null,
        ?array $apSubjectACoverage = null,
        ?array $weaknessInterventionOutcomes = null,
    ): array {
        $surfaces = [];
        $type = (string) ($learningType['key'] ?? 'general_learning');
        $hasScope = (bool) ($state['has_confirmed_scope'] ?? false);
        $currentPositionKnown = (bool) (
            $state['current_position_known'] ?? false
        );

        $surfaces[] = $this->registry->surface('goal_summary', [
            'plan' => $plan,
            'learning_type' => $learningType,
            'state' => $state,
        ]);

        if (
            $canEdit
            && (bool) (
                $learningType['needs_confirmation']
                ?? false
            )
        ) {
            $surfaces[] = $this->registry->surface(
                'learning_type_confirmation',
                [
                    'plan' => $plan,
                    'learning_type' => $learningType,
                ],
            );
        }

        $surfaces[] = $this->registry->surface('current_state', [
            'plan' => $plan,
            'learning_type' => $learningType,
            'state' => $state,
        ]);

        $context = $this->missingContext(
            $plan,
            $type,
            $state,
            $navigationTask,
        );

        if ($context !== null) {
            $surfaces[] = $this->registry->surface(
                'missing_context',
                $context,
            );
        }

        $hasMethodRecommendation = is_array($methodRecommendation)
            && $methodRecommendation !== [];

        if ($hasMethodRecommendation) {
            $surfaces[] = $this->registry->surface(
                'study_method_recommendation',
                $methodRecommendation ?? [],
            );
        }

        $usesRecommendation = $this->usesPracticeRecommendation(
            $type,
            $state,
            $presentation,
            $recommendation,
            $methodRecommendation,
        );

        if ($usesRecommendation) {
            $surfaces[] = $this->registry->surface(
                'study_recommendation',
                $recommendation ?? [],
            );
        }

        if ($hasScope && $presentation) {
            $surfaces[] = $this->registry->surface('readiness', [
                'presentation' => $presentation,
                'state' => $state,
            ]);
            $surfaces[] = $this->registry->surface('biggest_gap', [
                'presentation' => $presentation,
            ]);
        }

        if (
            is_array($apSubjectACoverage)
            && (bool) (
                $apSubjectACoverage['available']
                ?? false
            )
        ) {
            $surfaces[] = $this->registry->surface(
                'ap_subject_a_coverage',
                [
                    'coverage' =>
                        $apSubjectACoverage,
                ],
            );
        }

        if (
            is_array($weaknessInterventionOutcomes)
            && (bool) (
                $weaknessInterventionOutcomes['available']
                ?? false
            )
        ) {
            $surfaces[] = $this->registry->surface(
                'weakness_intervention_outcomes',
                [
                    'outcomes' =>
                        $weaknessInterventionOutcomes,
                ],
            );
        }

        $primaryAction = $this->primaryAction(
            $plan,
            $type,
            $state,
            $presentation,
            $navigationTask,
            $canEdit,
        );
        // Even when a learning-method card is available, the first action
        // for a known official exam must be immediately visible and actionable.
        $officialBaselineNeeded = ! $hasScope
            && is_array($state['official_exam_reference'] ?? null);
        if (($officialBaselineNeeded && $presentation)
            || (! $usesRecommendation && ! $hasMethodRecommendation)) {
            $surfaces[] = $this->registry->surface(
                'current_action',
                $primaryAction,
            );
        }

        if (! empty($state['weaknesses'])) {
            $surfaces[] = $this->registry->surface('weaknesses', [
                'items' => (array) $state['weaknesses'],
            ]);
        }

        if (! empty($state['recent_scores'])) {
            $surfaces[] = $this->registry->surface('recent_results', [
                'items' => (array) $state['recent_scores'],
                'average_score_percent' =>
                    $state['recent_average_score_percent'] ?? null,
            ]);
        }

        if (
            $hasScope
            && ! empty($state['priority_remaining_scope'])
        ) {
            $surfaces[] = $this->registry->surface('scope_coverage', [
                'items' => (array) $state['priority_remaining_scope'],
                'plan' => $plan,
            ]);
        }

        $surfaces[] = $this->registry->surface('study_methods', [
            'plan' => $plan,
            'navigation_task' => $navigationTask,
            'learning_type' => $learningType,
            'current_position_known' => $currentPositionKnown,
            'method_recommendation' => $methodRecommendation,
        ]);

        return [
            'surfaces' => $surfaces,
            'blocks_execution' => (bool) ($context['blocks_execution'] ?? false),
            'uses_study_recommendation' => $usesRecommendation,
            'uses_study_method_recommendation' => $hasMethodRecommendation,
            'primary_action' => $primaryAction,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed>|null $recommendation
     */
    private function usesPracticeRecommendation(
        string $type,
        array $state,
        ?PlanIntelligencePresentation $presentation,
        ?array $recommendation,
        ?array $methodRecommendation,
    ): bool {
        if (! is_array($recommendation) || $recommendation === []) {
            return false;
        }

        if (
            is_array($methodRecommendation)
            && (string) data_get(
                $methodRecommendation,
                'primary.key',
                '',
            ) !== \App\Services\StudyActivityPolicyService::QUESTION_PRACTICE
        ) {
            return false;
        }

        if (! in_array(
            $type,
            [
                'certification_exam',
                'score_exam',
                'general_learning',
                'school_test',
            ],
            true,
        )) {
            return false;
        }

        $hasScope = (bool) ($state['has_confirmed_scope'] ?? false);

        if ($type === 'school_test' && ! $hasScope) {
            return false;
        }

        if ((bool) ($recommendation['resume'] ?? false)) {
            return true;
        }

        if ($hasScope && $presentation) {
            return (string) data_get(
                $presentation->action->metadata,
                'route_kind',
                '',
            ) === 'study_practice';
        }

        return true;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null
     */
    private function missingContext(
        Plan $plan,
        string $type,
        array $state,
        ?Task $navigationTask,
    ): ?array {
        $hasScope = (bool) ($state['has_confirmed_scope'] ?? false);
        $currentPositionKnown = (bool) (
            $state['current_position_known'] ?? false
        );

        if ($type === 'school_test' && ! $hasScope) {
            return [
                'kind' => 'study_scope',
                'eyebrow' => 'MISSING CONTEXT',
                'title' => '今回の試験範囲がまだ分かりません',
                'detail' => '定期テストでは出題範囲が次の学習配分を大きく変えるため、ここだけは先に確認すると精度が上がります。',
                'action_url' => route('plans.study_scope.index', $plan),
                'action_label' => '試験範囲を追加',
                'blocks_execution' => true,
            ];
        }

        if (
            $type === 'score_exam'
            && ! (bool) (
                $state['has_external_score_baseline']
                ?? false
            )
        ) {
            return [
                'kind' => 'current_score',
                'eyebrow' => 'CURRENT POSITION',
                'title' => '現在スコアがまだ分かりません',
                'detail' => 'Canovia演習の正答率は外部試験スコアとは別尺度です。直近の公式結果・模試・自己申告スコアをEvidenceとして記録すると、目標との差を正しく扱えます。',
                'action_url' => route(
                    'plans.study_scores.index',
                    $plan,
                ),
                'action_label' => '現在スコアを記録',
                'blocks_execution' => false,
            ];
        }

        if (
            $type === 'skill_learning'
            && ! $currentPositionKnown
        ) {
            return [
                'kind' => 'practical_baseline',
                'eyebrow' => 'CURRENT POSITION',
                'title' => 'まず実践結果から現在地を作ります',
                'detail' => 'スキル学習では問題を解くだけでなく、実際に作る・使う・試す結果をEvidenceとして現在地に反映します。',
                'action_url' => $navigationTask
                    ? route(
                        'plans.tasks.guided_execution.show',
                        [$plan, $navigationTask],
                    )
                    : route('plans.show', $plan),
                'action_label' => $navigationTask
                    ? '実行を始める'
                    : 'Plan内容を確認',
                'blocks_execution' => false,
            ];
        }

        if (
            in_array(
                $type,
                ['certification_exam', 'general_learning'],
                true,
            )
            && ! $currentPositionKnown
        ) {
            return [
                'kind' => 'baseline',
                'eyebrow' => 'CURRENT POSITION',
                'title' => 'まず現在地を1回だけ測ります',
                'detail' => '試験範囲を先に固定するのではなく、今あるTaskから短い演習・実践Evidenceを作り、その結果を次の判断に使います。',
                'action_url' => $navigationTask
                    ? route(
                        'plans.tasks.study_practice.show',
                        [$plan, $navigationTask],
                    )
                    : route('plans.show', $plan),
                'action_label' => $navigationTask
                    ? '現在地を診断'
                    : 'Plan内容を確認',
                'blocks_execution' => false,
            ];
        }

        if ($type === 'memorization' && ! $currentPositionKnown) {
            return [
                'kind' => 'retention_baseline',
                'eyebrow' => 'CURRENT POSITION',
                'title' => '最初の定着度を確認します',
                'detail' => '暗記目標では範囲入力より、思い出せるかどうかのEvidenceを先に作る方が次の復習間隔を決めやすくなります。',
                'action_url' => $navigationTask
                    ? route(
                        'plans.tasks.study_recall.show',
                        [$plan, $navigationTask],
                    )
                    : route('plans.show', $plan),
                'action_label' => $navigationTask
                    ? 'Recallを始める'
                    : 'Plan内容を確認',
                'blocks_execution' => false,
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function primaryAction(
        Plan $plan,
        string $type,
        array $state,
        ?PlanIntelligencePresentation $presentation,
        ?Task $navigationTask,
        bool $canEdit,
    ): array {
        $hasScope = (bool) ($state['has_confirmed_scope'] ?? false);
        $currentPositionKnown = (bool) (
            $state['current_position_known'] ?? false
        );

        if ($type === 'school_test' && ! $hasScope) {
            return [
                'eyebrow' => 'NEXT ACTION',
                'title' => '試験範囲を取り込む',
                'detail' => 'このPlanでは範囲情報が学習順序を直接変えるため、先に範囲を確定します。',
                'url' => route('plans.study_scope.index', $plan),
                'method' => 'GET',
                'label' => '試験範囲を追加',
                'enabled' => true,
            ];
        }

        // Published qualification scope may be known before the learner has
        // confirmed any personal scope items. Honor its baseline Action.
        if (($hasScope || is_array($state['official_exam_reference'] ?? null)) && $presentation) {
            return [
                'eyebrow' => 'CURRENT ACTION',
                'title' => $presentation->action?->title
                    ?? '次のActionを確認',
                'detail' => $presentation->action?->intent
                    ?? '確定済みのStudy Stateから次の一手を選びます。',
                'url' => $presentation->actionUrl,
                'method' => $presentation->actionMethod,
                'label' => $presentation->actionLabel,
                'enabled' => $presentation->actionMethod !== 'POST'
                    || $canEdit,
                'reason' => $presentation->decision?->summary,
            ];
        }

        if ($navigationTask) {
            if ($type === 'memorization') {
                return [
                    'eyebrow' => 'CURRENT ACTION',
                    'title' => $currentPositionKnown
                        ? '定着確認を続ける'
                        : '最初の定着度を確認する',
                    'detail' => 'Recall Evidenceを使って、次に復習すべき内容と間隔を更新します。',
                    'url' => route(
                        'plans.tasks.study_recall.show',
                        [$plan, $navigationTask],
                    ),
                    'method' => 'GET',
                    'label' => 'Recallを開く',
                    'enabled' => true,
                ];
            }

            return [
                'eyebrow' => 'CURRENT ACTION',
                'title' => $currentPositionKnown
                    ? '今の状態から次の演習へ進む'
                    : 'まず現在地を診断する',
                'detail' => $currentPositionKnown
                    ? 'これまでの演習結果・弱点を引き継いで、Canoviaが次の問題セットを決めます。'
                    : 'このTaskを入口に最初のEvidenceを作り、その結果から以後の学習方法を切り替えます。',
                'url' => route(
                    'plans.tasks.study_practice.show',
                    [$plan, $navigationTask],
                ),
                'method' => 'GET',
                'label' => $currentPositionKnown
                    ? '演習を続ける'
                    : '現在地を診断',
                'enabled' => true,
            ];
        }

        return [
            'eyebrow' => 'CURRENT ACTION',
            'title' => 'Planの学習Taskを確認する',
            'detail' => '実行できる学習Taskがまだないため、Planの内容を確認して次の学習対象を用意します。',
            'url' => route('plans.show', $plan),
            'method' => 'GET',
            'label' => 'Planを開く',
            'enabled' => true,
        ];
    }
}
