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
        $guidancePlan = data_get($primaryGuidance, 'plan');

        $topStudyPlan = $editablePlans
            ->filter(
                fn (Plan $candidate) =>
                    $this->profiles->forPlan($candidate)->key === 'study'
                    && ! $this->isCompletedPlan($candidate)
            )
            ->sort(fn (Plan $left, Plan $right) => $this->comparePlans($left, $right))
            ->first();

        if ($guidancePlan instanceof Plan) {
            if ($this->profiles->forPlan($guidancePlan)->key === 'study') {
                $plan = $guidancePlan;
            } elseif (
                $topStudyPlan instanceof Plan
                && $this->comparePlans($topStudyPlan, $guidancePlan) < 0
            ) {
                // A Study Plan without an executable Task can still own the
                // current Action when its Plan-level priority is genuinely higher.
                $plan = $topStudyPlan;
            } else {
                return null;
            }
        } else {
            $plan = $topStudyPlan;

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

    private function isCompletedPlan(Plan $plan): bool
    {
        $tasks = $plan->relationLoaded('tasks')
            ? $plan->tasks
            : $plan->tasks()->get([
                'id',
                'status',
                'progress_percent',
            ]);

        return $tasks->isNotEmpty()
            && $tasks->every(
                fn ($task) =>
                    in_array($task->status, ['done', 'cancelled'], true)
                    || (int) $task->progress_percent >= 100
            );
    }

    private function comparePlans(Plan $left, Plan $right): int
    {
        $leftPriority = (int) data_get(
            $this->priorities->evaluate($left),
            'priority',
            3,
        );
        $rightPriority = (int) data_get(
            $this->priorities->evaluate($right),
            'priority',
            3,
        );

        $priority = $leftPriority <=> $rightPriority;
        if ($priority !== 0) {
            return $priority;
        }

        $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
            <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

        return $deadline !== 0
            ? $deadline
            : (int) $left->id <=> (int) $right->id;
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
