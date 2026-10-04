<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Data\CareerAdaptiveActionResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\CareerSelectionEvent;
use App\Models\Plan;

final class CareerIntelligencePresentationAdapter
{
    public function adapt(
        Plan $plan,
        CareerAdaptiveActionResult $result,
    ): ?PlanIntelligencePresentation {
        $action = $result->primaryAction();
        if (! $action) {
            return null;
        }

        $readiness = $result->intelligence->readiness;
        $state = $result->intelligence->state;
        $hasSignal = (bool) data_get(
            $state->facts,
            'has_career_signal',
            false,
        );
        $routeKind = (string) data_get(
            $action->metadata,
            'route_kind',
            'career_workspace',
        );

        return new PlanIntelligencePresentation(
            domain: IntelligenceDomain::Career,
            plan: $plan,
            state: $state,
            readiness: $readiness,
            decision: $result->decision,
            action: $action,
            eyebrow: 'CAREER INTELLIGENCE',
            headline: '選考プロセスの現在地',
            sourceNote: '内定確率ではなく、Career Capture・応募・面接・Reviewなど記録済みの事実から次のProcess Actionを判断しています。',
            readinessLabel: 'Process Readiness',
            stateLabel: $hasSignal ? '観測中' : '未観測',
            gapLabel: $this->gapLabel(
                $result->decision->reasonCode,
            ),
            gapDetail: $result->decision->summary,
            detailUrl: route('plans.career.index', $plan),
            actionUrl: $this->actionUrl(
                $plan,
                $routeKind,
                data_get(
                    $action->metadata,
                    'target_selection_event_id',
                ),
            ),
            actionMethod: 'GET',
            actionLabel: $this->actionLabel($action->kind),
            metrics: $this->metrics($result),
            targetTask: null,
            requiresTaskProjection: false,
            qualitativeReadiness: true,
        );
    }

    /**
     * @return array<int,array{label:string,value:string,detail:?string}>
     */
    private function metrics(CareerAdaptiveActionResult $result): array
    {
        $metrics = $result->intelligence->state->metrics;

        return [
            [
                'label' => 'Capture',
                'value' => (string) max(
                    0,
                    (int) data_get($metrics, 'capture_count', 0),
                ),
                'detail' => 'Reality',
            ],
            [
                'label' => '応募先',
                'value' => (string) max(
                    0,
                    (int) data_get($metrics, 'application_count', 0),
                ),
                'detail' => 'Application',
            ],
            [
                'label' => '面接予定',
                'value' => (string) max(
                    0,
                    (int) data_get(
                        $metrics,
                        'scheduled_interview_count',
                        0,
                    ),
                ),
                'detail' => 'Interview',
            ],
            [
                'label' => 'Review待ち',
                'value' => (string) max(
                    0,
                    (int) data_get($metrics, 'review_due_count', 0),
                ),
                'detail' => 'Reflection',
            ],
        ];
    }

    private function gapLabel(string $reason): string
    {
        return match ($reason) {
            'career_signal_missing' => 'Careerの現実情報がまだない',
            'interview_review_due' => '面接Reviewが未完了',
            'interview_due_soon' => '直近の面接準備が必要',
            'interview_upcoming' => '面接予定がある',
            'offer_requires_user_review' => 'オファー条件の整理が必要',
            'pending_capture_unorganized' => '未整理Captureがある',
            'application_pipeline_missing' => '応募先がPipeline化されていない',
            'application_preparation_active' => '応募準備中の次Actionが必要',
            'selection_result_waiting' => '選考結果待ち',
            'no_dominant_career_gap' => '大きなProcess Gapなし',
            default => 'Career Pipelineを確認中',
        };
    }

    private function actionLabel(string $kind): string
    {
        return match ($kind) {
            'career_capture_signal' => 'Career情報を追加',
            'career_interview_review' => '面接Reviewを開く',
            'career_interview_prep' => '面接準備を開く',
            'career_offer_review' => '条件を整理',
            'career_organize_capture' => 'Captureを整理',
            'career_structure_application' => '応募先を整理',
            'career_advance_application' => '次Actionを確認',
            default => 'Career Pipelineを開く',
        };
    }

    private function actionUrl(
        Plan $plan,
        string $routeKind,
        mixed $eventId,
    ): string {
        if ($routeKind === 'career_interview_review') {
            $id = filter_var($eventId, FILTER_VALIDATE_INT);

            if ($id !== false && (int) $id > 0) {
                $event = CareerSelectionEvent::query()
                    ->whereKey((int) $id)
                    ->whereHas(
                        'application',
                        fn ($query) =>
                            $query->where('plan_id', $plan->id),
                    )
                    ->first();

                if ($event instanceof CareerSelectionEvent) {
                    return route(
                        'plans.career.interview_reviews.show',
                        [$plan, $event],
                    );
                }
            }
        }

        return route('plans.career.index', $plan);
    }
}
