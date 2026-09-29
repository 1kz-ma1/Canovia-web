<?php

namespace App\Services;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

final class MapExecutionContextService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly UserBehaviorService $behavior,
        private readonly UserStateService $states,
        private readonly DashboardGuidanceService $guidance,
    ) {}

    /**
     * Resolve the legacy V43 execution scope without building any Map nodes.
     *
     * This service selects which execution context is currently projected.
     * Navigation graph construction and visual attention are handled elsewhere.
     *
     * @return array<string,mixed>
     */
    public function resolve(Request $request): array
    {
        $actorToken = $this->core->actorToken($request);
        $plans = $this->core->plans($request, ['tasks', 'task_dependencies', 'plan_resources']);

        if ($plans->isNotEmpty()) {
            (new EloquentCollection($plans->all()))->loadMissing('goalContext');
        }

        $editablePlans = $plans
            ->filter(fn (Plan $plan) => $this->core->canEdit($request, $plan))
            ->values();

        $requestedPlanId = max(0, (int) $request->query('plan', 0));
        $requestedPlan = $requestedPlanId > 0
            ? $plans->firstWhere('id', $requestedPlanId)
            : null;

        $guidanceDeck = collect();

        if ($editablePlans->isNotEmpty()) {
            $baseline = $this->behavior->baseline($actorToken);
            $state = $this->states->calculate($actorToken, $baseline, $editablePlans);

            $guidancePlans = $requestedPlan instanceof Plan
                ? collect([$requestedPlan])
                : $plans;
            $guidanceEditableIds = $requestedPlan instanceof Plan
                && $editablePlans->contains(fn (Plan $plan) => (int) $plan->id === (int) $requestedPlan->id)
                    ? [(int) $requestedPlan->id]
                    : $editablePlans->pluck('id')->map(fn ($id) => (int) $id)->all();

            $guidanceDeck = $this->guidance->build(
                $guidancePlans,
                $state,
                $actorToken,
                $guidanceEditableIds,
                $request->user(),
            );
        }

        $primaryGuidance = $guidanceDeck->first();
        $plan = $requestedPlan
            ?? data_get($primaryGuidance, 'plan')
            ?? $editablePlans->first()
            ?? $plans->first();
        $currentTask = data_get($primaryGuidance, 'task');
        $primaryTool = data_get($primaryGuidance, 'recommended_tool');

        if ($currentTask instanceof Task) {
            (new EloquentCollection([$currentTask]))->loadMissing([
                'evidences' => fn ($query) => $query->limit(1),
            ]);
        }

        $nextTask = $plan instanceof Plan && $currentTask instanceof Task
            ? $this->nextTask($plan, $currentTask)
            : null;

        [$pendingInboxCount, $latestInbox] = $this->pendingInbox($request, $actorToken);

        return [
            'actor_token' => $actorToken,
            'plans' => $plans,
            'editable_plans' => $editablePlans,
            'plan' => $plan,
            'current_task' => $currentTask,
            'primary_tool' => $primaryTool,
            'next_task' => $nextTask,
            'pending_inbox_count' => $pendingInboxCount,
            'latest_inbox' => $latestInbox,
            'requested_plan_id' => $requestedPlan?->id,
        ];
    }

    private function nextTask(Plan $plan, Task $currentTask): ?Task
    {
        $currentOrder = (int) ($currentTask->sort_order ?? PHP_INT_MAX);

        return $plan->tasks
            ->filter(fn (Task $task) => (int) $task->id !== (int) $currentTask->id
                && ! in_array($task->status, ['done', 'cancelled'], true)
                && (int) $task->progress_percent < 100)
            ->sort(function (Task $left, Task $right) use ($currentTask, $currentOrder) {
                $dependency = (in_array((int) $currentTask->id, $left->dependencyIds(), true) ? 0 : 1)
                    <=> (in_array((int) $currentTask->id, $right->dependencyIds(), true) ? 0 : 1);
                if ($dependency !== 0) {
                    return $dependency;
                }

                $afterCurrent = ((int) ($left->sort_order ?? PHP_INT_MAX) > $currentOrder ? 0 : 1)
                    <=> ((int) ($right->sort_order ?? PHP_INT_MAX) > $currentOrder ? 0 : 1);
                if ($afterCurrent !== 0) {
                    return $afterCurrent;
                }

                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));
                if ($priority !== 0) {
                    return $priority;
                }

                $sortOrder = (int) ($left->sort_order ?? PHP_INT_MAX)
                    <=> (int) ($right->sort_order ?? PHP_INT_MAX);

                return $sortOrder !== 0
                    ? $sortOrder
                    : (int) $left->id <=> (int) $right->id;
            })
            ->first();
    }

    /**
     * @return array{0:int,1:?InboxItem}
     */
    private function pendingInbox(Request $request, string $actorToken): array
    {
        $userId = $request->user()?->id;

        $query = InboxItem::query()
            ->where(function (Builder $query) use ($userId, $actorToken) {
                if ($userId) {
                    $query->where('user_id', $userId)
                        ->orWhere('actor_token', $actorToken);
                    return;
                }

                $query->whereNull('user_id')->where('actor_token', $actorToken);
            })
            ->whereIn('status', ['new', 'review']);

        return [
            (clone $query)->count(),
            $query->latest('id')->first(),
        ];
    }
}
