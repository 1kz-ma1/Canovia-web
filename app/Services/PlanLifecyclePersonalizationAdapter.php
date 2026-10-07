<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

final class PlanLifecyclePersonalizationAdapter
{
    private const COMPLETED_PLAN_MEMORY = 20;

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly PersonalizationLivingProfileService $livingProfile,
    ) {}

    public function observeCreated(
        Request $request,
        Plan $plan,
    ): void {
        if (! $this->eligible($request, $plan)) {
            return;
        }

        $context = $this->contexts->current($request);
        $lifecycle = $this->lifecycle($context);

        $lifecycle['last_created_plan_id'] = (int) $plan->id;
        $lifecycle['last_created_at'] = now()->toIso8601String();

        $this->contexts->storeObservedCandidate(
            $request,
            ['plan_lifecycle' => $lifecycle],
        );

        $this->livingProfile->refresh(
            $request,
            'new_plan',
            $plan,
        );
    }

    public function observeTaskUpdated(
        Request $request,
        Task $task,
    ): void {
        if (
            ! $task->wasChanged([
                'status',
                'progress_percent',
            ])
            || ! $this->taskIsComplete($task)
        ) {
            return;
        }

        $plan = $task->relationLoaded('plan')
            ? $task->plan
            : $task->plan()->first();

        if (! $plan instanceof Plan || ! $this->eligible($request, $plan)) {
            return;
        }

        $activeTaskCount = Task::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($activeTaskCount === 0) {
            return;
        }

        $incompleteExists = Task::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) {
                $query
                    ->where('status', '!=', 'done')
                    ->where('progress_percent', '<', 100);
            })
            ->exists();

        if ($incompleteExists) {
            return;
        }

        $context = $this->contexts->current($request);
        $lifecycle = $this->lifecycle($context);
        $completedIds = collect(
            $lifecycle['recent_completed_plan_ids']
            ?? [],
        )
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($completedIds->contains((int) $plan->id)) {
            return;
        }

        $completedIds->push((int) $plan->id);

        $lifecycle['recent_completed_plan_ids'] = $completedIds
            ->slice(-self::COMPLETED_PLAN_MEMORY)
            ->values()
            ->all();
        $lifecycle['last_completed_plan_id'] = (int) $plan->id;
        $lifecycle['last_completed_task_id'] = (int) $task->id;
        $lifecycle['last_completed_at'] = now()->toIso8601String();

        $this->contexts->storeObservedCandidate(
            $request,
            ['plan_lifecycle' => $lifecycle],
        );

        $this->livingProfile->refresh(
            $request,
            'plan_completed',
            $plan,
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function lifecycle(array $context): array
    {
        $value = data_get(
            $context,
            'context_sources.observed.plan_lifecycle',
            [],
        );

        return is_array($value) ? $value : [];
    }

    private function eligible(
        Request $request,
        Plan $plan,
    ): bool {
        $user = $request->user();

        if (
            ! $user
            || $plan->user_id === null
            || (int) $plan->user_id !== (int) $user->id
        ) {
            return false;
        }

        $context = $this->contexts->current($request);

        return
            (array) data_get(
                $context,
                'context_sources.self_reported',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.observed',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.inferred',
                [],
            ) !== [];
    }

    private function taskIsComplete(Task $task): bool
    {
        return
            $task->status === 'done'
            || (int) $task->progress_percent >= 100;
    }
}
