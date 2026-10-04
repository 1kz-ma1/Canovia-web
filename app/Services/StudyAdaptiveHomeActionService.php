<?php

namespace App\Services;

use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Plan;
use Illuminate\Support\Collection;

final class StudyAdaptiveHomeActionService
{
    public function __construct(
        private readonly StudyAdaptiveActionService $actions,
        private readonly PlanCategoryProfileService $profiles,
        private readonly PlanPriorityService $priorities,
    ) {}

    /**
     * Evaluate at most one Study Plan on Home to avoid per-Plan Intelligence queries.
     *
     * @param Collection<int,Plan> $editablePlans
     * @param Collection<int,array<string,mixed>> $guidanceDeck
     * @return array<string,mixed>|null
     */
    public function primary(
        Collection $editablePlans,
        Collection $guidanceDeck,
    ): ?array {
        $primaryGuidance = $guidanceDeck->first();
        $plan = data_get($primaryGuidance, 'plan');

        if ($plan instanceof Plan) {
            if ($this->profiles->forPlan($plan)->key !== 'study') {
                return null;
            }
        } else {
            $plan = $editablePlans
                ->filter(
                    fn (Plan $candidate) =>
                        $this->profiles->forPlan($candidate)->key === 'study'
                )
                ->sort(function (Plan $left, Plan $right) {
                    $priority = (int) data_get(
                        $this->priorities->evaluate($left),
                        'priority',
                        3,
                    ) <=> (int) data_get(
                        $this->priorities->evaluate($right),
                        'priority',
                        3,
                    );

                    if ($priority !== 0) {
                        return $priority;
                    }

                    $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
                        <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

                    return $deadline !== 0
                        ? $deadline
                        : (int) $left->id <=> (int) $right->id;
                })
                ->first();

            if (! $plan instanceof Plan) {
                return null;
            }
        }

        $result = $this->actions->evaluate($plan);
        $action = $result->primaryAction();

        if (! $action) {
            return null;
        }

        $targetTaskId = filter_var(
            data_get($action->metadata, 'target_task_id'),
            FILTER_VALIDATE_INT,
        );
        $targetTask = null;

        if ($targetTaskId !== false && (int) $targetTaskId > 0) {
            $targetTask = $plan->relationLoaded('tasks')
                ? $plan->tasks->firstWhere('id', (int) $targetTaskId)
                : $plan->tasks()->whereKey((int) $targetTaskId)->first();
        }

        return [
            'plan' => $plan,
            'action' => $action,
            'decision' => $result->decision,
            'readiness' => $result->intelligence->readiness,
            'target_task' => $targetTask,
            'execute_url' => route('plans.study_action.execute', $plan),
            'action_label' => $this->label(
                (string) data_get($action->metadata, 'route_kind', ''),
                $action->kind,
            ),
            'requires_task_projection' => (
                (string) data_get($action->metadata, 'route_kind')
            ) === 'project_task',
        ];
    }

    private function label(string $routeKind, string $kind): string
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
}
