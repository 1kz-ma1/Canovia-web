<?php

namespace App\Intelligence\Career;

use App\Intelligence\Contracts\ActionGenerator;
use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use InvalidArgumentException;

final class CareerActionGenerator implements ActionGenerator
{
    /**
     * @return array<int,ActionProposal>
     */
    public function generate(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        Decision $decision,
    ): array {
        if ($state->domain !== IntelligenceDomain::Career) {
            throw new InvalidArgumentException(
                'CareerActionGenerator only supports the career domain.',
            );
        }

        $definition = $this->definition($decision->type);

        return [new ActionProposal(
            kind: $definition['kind'],
            title: $definition['title'],
            intent: $definition['intent'],
            confidence: $decision->confidence,
            estimatedMinutes: null,
            successSignals: $definition['success_signals'],
            metadata: [
                'policy_version' => 'career_process_action_v1',
                'reason_code' => $decision->reasonCode,
                'target_capture_id' => $this->intOrNull(
                    data_get(
                        $decision->metadata,
                        'target_capture_id',
                    ),
                ),
                'target_application_id' => $this->intOrNull(
                    data_get(
                        $decision->metadata,
                        'target_application_id',
                    ),
                ),
                'target_selection_event_id' => $this->intOrNull(
                    data_get(
                        $decision->metadata,
                        'target_selection_event_id',
                    ),
                ),
                'target_task_id' => $this->intOrNull(
                    data_get(
                        $decision->metadata,
                        'target_task_id',
                    ),
                ),
                'career_stage' => data_get(
                    $decision->metadata,
                    'career_stage',
                ),
                'route_kind' => $definition['route_kind'],
                'projection_policy' =>
                    'career_process_only_no_employment_decision_v55_0',
            ],
        )];
    }

    /**
     * @return array{
     *   kind:string,
     *   title:string,
     *   intent:string,
     *   route_kind:string,
     *   success_signals:array<int,string>
     * }
     */
    private function definition(string $type): array
    {
        return match ($type) {
            'capture_career_signal' => [
                'kind' => 'career_capture_signal',
                'title' => '求人・応募情報を1つ残す',
                'intent' =>
                    'Careerの現在地を判断できる事実がまだありません。求人URLや応募画面など、現実の情報を1つだけCaptureします。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    'Career CaptureまたはApplicationが1件以上ある',
                ],
            ],
            'complete_interview_review' => [
                'kind' => 'career_interview_review',
                'title' => '面接の振り返りを残す',
                'intent' =>
                    '面接後の学びは時間が経つほど失われます。良かった点・難しかった点・次に直す点を短く残します。',
                'route_kind' => 'career_interview_review',
                'success_signals' => [
                    'Interview Review status = completed',
                ],
            ],
            'prepare_interview' => [
                'kind' => 'career_interview_prep',
                'title' => '次の面接準備を確認する',
                'intent' =>
                    '面接予定が確定しています。企業選択の判断はせず、予定済み面接に必要な準備だけを確認します。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    '面接準備のCurrent Actionが確認済み',
                ],
            ],
            'review_offer_conditions' => [
                'kind' => 'career_offer_review',
                'title' => 'オファー条件を整理する',
                'intent' =>
                    '承諾・辞退はCanoviaが決めません。条件・回答期限・確認したい点を整理し、本人が判断できる状態を作ります。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    'オファー条件と未確認事項が整理されている',
                ],
            ],
            'organize_capture' => [
                'kind' => 'career_organize_capture',
                'title' => '未整理Captureを応募先へつなぐ',
                'intent' =>
                    '保存済みの求人・応募情報がまだPipelineへ接続されていません。必要なものだけApplicationとして整理します。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    '対象CaptureがApplicationへlinked',
                ],
            ],
            'structure_first_application' => [
                'kind' => 'career_structure_application',
                'title' => '最初の応募先をPipelineへ整理する',
                'intent' =>
                    'Career情報はありますが、Applicationとして現在地を追える状態ではありません。候補または応募先を1件だけ構造化します。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    'Career Applicationが1件以上ある',
                ],
            ],
            'advance_application' => [
                'kind' => 'career_advance_application',
                'title' => '準備中の応募先の次Actionを整理する',
                'intent' =>
                    '候補・応募準備中のApplicationがあります。提出・書類・選考準備のうち、次に必要な作業をCareer Pipeline上で確認します。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    'Application stageまたは関連Taskが更新される',
                ],
            ],
            default => [
                'kind' => 'career_review_pipeline',
                'title' => 'Career Pipelineを確認する',
                'intent' =>
                    '結果待ちを勝手に確定したり、新しい応募を強制したりせず、現在の選考・準備・待機状態を確認します。',
                'route_kind' => 'career_workspace',
                'success_signals' => [
                    '次に扱うCareer Actionが明確になる',
                ],
            ],
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false || (int) $value <= 0
            ? null
            : (int) $value;
    }
}
