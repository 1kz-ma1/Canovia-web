<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Collection;

final class SpecializedWorkspaceTopService
{
    public function __construct(
        private readonly PlanProgressService $progress,
    ) {}

    /**
     * Build bounded, provider-free Plan summaries for Study / Developer tops.
     *
     * The caller is responsible for authorization and profile filtering.
     * This service intentionally does not evaluate Intelligence or call
     * external providers.
     *
     * @param Collection<int,Plan> $plans
     * @return Collection<int,array<string,mixed>>
     */
    public function summarize(Collection $plans): Collection
    {
        return $plans
            ->map(fn (Plan $plan) => $this->plan($plan))
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    public function plan(Plan $plan): array
    {
        $progress = $this->progress->calculate($plan);

        $tasks = $plan->relationLoaded('tasks')
            ? $plan->tasks
            : $plan->tasks()->get();

        $activeTasks = $tasks->filter(
            fn ($task) => ! in_array(
                (string) $task->status,
                ['done', 'completed', 'cancelled'],
                true,
            ),
        );

        $completedTasks = $tasks->filter(
            fn ($task) => in_array(
                (string) $task->status,
                ['done', 'completed'],
                true,
            ),
        );

        return [
            'plan' => $plan,
            'plan_id' => (int) $plan->id,
            'title' => (string) $plan->title,
            'progress_percent' => max(
                0.0,
                min(
                    100.0,
                    (float) ($progress['weighted_progress_percent'] ?? 0),
                ),
            ),
            'status' => (string) ($progress['status'] ?? '未判定'),
            'deadline' => $plan->deadline,
            'remaining_days' => $progress['remaining_days'] ?? null,
            'remaining_minutes' => max(
                0,
                (int) ($progress['remaining_minutes'] ?? 0),
            ),
            'task_count' => $tasks->count(),
            'active_task_count' => $activeTasks->count(),
            'completed_task_count' => $completedTasks->count(),
        ];
    }
}
