<?php

namespace App\Services;

use App\Data\UserStateData;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class DashboardGuidanceService
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly PlanToolService $toolService,
        private readonly ExecutionActionPolicyService $executionActions,
        private readonly PlanPriorityService $priorityService,
    ) {}

    /**
     * Home-specific objective guidance.
     *
     * Selection is intentionally independent from behavioral personalization:
     * effective Plan priority (Auto/Manual) -> deadline -> Plan id, and inside a Plan
     * Task priority -> doing first -> sort order -> Task id.
     *
     * RecommendationService is used only after selection to suggest a workable
     * duration/reason for the exact selected Task.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        Collection $plans,
        UserStateData $state,
        string $actorToken,
        array $editablePlanIds,
        ?User $actor = null,
    ): Collection {
        $editable = collect($editablePlanIds)->map(fn ($id) => (int) $id)->flip();

        return $plans
            ->filter(fn (Plan $plan) => $editable->has((int) $plan->id))
            ->map(function (Plan $plan) use ($state, $actorToken, $actor) {
                $task = $this->selectTask($plan);

                if (! $task) {
                    return null;
                }

                $adaptive = $this->recommendationService->recommend(
                    collect([$plan]),
                    $state,
                    actorToken: $actorToken,
                    candidateTaskIds: [(int) $task->id],
                );

                $tools = collect($this->toolService->forTask($plan, $task, true, $actor));
                $recommendedTool = $this->executionActions->primary($tools);

                return [
                    'plan' => $plan,
                    'task' => $task,
                    'adaptive' => $adaptive,
                    'recommended_tool' => $recommendedTool,
                    'priority_evaluation' => $this->priorityService->evaluate($plan),
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right) {
                $priority = (int) data_get($left, 'priority_evaluation.priority', 3)
                    <=> (int) data_get($right, 'priority_evaluation.priority', 3);

                if ($priority !== 0) {
                    return $priority;
                }

                $leftDeadline = $left['plan']->deadline?->timestamp ?? PHP_INT_MAX;
                $rightDeadline = $right['plan']->deadline?->timestamp ?? PHP_INT_MAX;
                $deadline = $leftDeadline <=> $rightDeadline;

                if ($deadline !== 0) {
                    return $deadline;
                }

                return (int) $left['plan']->id <=> (int) $right['plan']->id;
            })
            ->values();
    }

    private function selectTask(Plan $plan): ?Task
    {
        return $plan->tasks
            ->filter(function (Task $task) use ($plan) {
                if (in_array($task->status, ['done', 'cancelled'], true) || (int) $task->progress_percent >= 100) {
                    return false;
                }

                $dependencyIds = $task->dependencyIds();
                if ($dependencyIds !== [] && collect($dependencyIds)->contains(function (int $dependencyId) use ($plan) {
                    $prerequisite = $plan->tasks->firstWhere('id', $dependencyId);

                    return ! $prerequisite
                        || ($prerequisite->status !== 'done' && (int) $prerequisite->progress_percent < 100);
                })) {
                    return false;
                }

                // Time is a planning estimate, not proof that the Task is complete.
                // A stale/zero remaining_minutes value must not hide an otherwise
                // unfinished Task from objective guidance.
                return true;
            })
            ->sort(function (Task $left, Task $right) {
                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));

                if ($priority !== 0) {
                    return $priority;
                }

                $status = ($left->status === 'doing' ? 0 : 1) <=> ($right->status === 'doing' ? 0 : 1);

                if ($status !== 0) {
                    return $status;
                }

                $sortOrder = (int) ($left->sort_order ?? PHP_INT_MAX) <=> (int) ($right->sort_order ?? PHP_INT_MAX);

                if ($sortOrder !== 0) {
                    return $sortOrder;
                }

                return (int) $left->id <=> (int) $right->id;
            })
            ->first();
    }
}
