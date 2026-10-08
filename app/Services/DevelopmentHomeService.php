<?php

namespace App\Services;

use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

final class DevelopmentHomeService
{
    public function __construct(
        private readonly DevelopmentTaskMatchService $taskMatches,
    ) {}

    /**
     * Build the user-facing Developer Home composition from existing Plan,
     * Task and GitHub observation state.
     *
     * @return array{
     *   recent_activity:Collection<int,DevelopmentActivityObservation>,
     *   unresolved_activity:Collection<int,DevelopmentActivityObservation>,
     *   association_tasks:Collection<int,Task>,
     *   active_tasks:Collection<int,Task>,
     *   recent_completed_task:?Task,
     *   completed_task_count:int,
     *   recent_completed_has_work_log:bool
     * }
     */
    public function build(
        Plan $plan,
        ?int $focusTaskId = null,
    ): array {
        // V57.2 suggestion refresh is deterministic and local. It never
        // creates a Task relation or calls GitHub.
        $this->taskMatches->refreshPlan($plan);

        $recentActivity = DevelopmentActivityObservation::query()
            ->with([
                'suggestedTask:id,plan_id,title,status,sort_order',
                'repositoryArtifact:id,plan_id,title,url',
                'resolvedArtifact' => fn ($query) => $query
                    ->select([
                        'id',
                        'plan_id',
                        'provider',
                        'artifact_type',
                        'title',
                        'url',
                    ])
                    ->with([
                        'tasks:id,plan_id,title,status,sort_order',
                    ]),
            ])
            ->where('plan_id', $plan->id)
            ->where('resolution_status', '!=', 'ignored')
            ->latest('last_observed_at')
            ->latest('id')
            ->limit(12)
            ->get();

        $unresolved = $recentActivity
            ->filter(fn (DevelopmentActivityObservation $observation) =>
                in_array(
                    $observation->resolution_status,
                    ['unlinked', 'suggested'],
                    true,
                )
                && in_array(
                    $observation->kind,
                    ['pull_request', 'issue', 'branch', 'commit'],
                    true,
                )
            )
            ->values();

        $plan->loadMissing([
            'tasks.artifacts' => fn ($query) =>
                $query->where('provider', 'github'),
        ]);

        $activeTasks = $plan->tasks
            ->filter(fn (Task $task) =>
                ! in_array($task->status, ['done', 'cancelled'], true)
            )
            ->sort(function (Task $left, Task $right) use ($focusTaskId) {
                $leftFocus = $focusTaskId !== null
                    && (int) $left->id === (int) $focusTaskId;
                $rightFocus = $focusTaskId !== null
                    && (int) $right->id === (int) $focusTaskId;

                if ($leftFocus !== $rightFocus) {
                    return $leftFocus ? -1 : 1;
                }

                $statusRank = [
                    'doing' => 0,
                    'todo' => 1,
                    'paused' => 2,
                ];

                $status = ($statusRank[$left->status] ?? 3)
                    <=> ($statusRank[$right->status] ?? 3);

                if ($status !== 0) {
                    return $status;
                }

                $priority = (int) $left->priority
                    <=> (int) $right->priority;

                if ($priority !== 0) {
                    return $priority;
                }

                $order = (int) $left->sort_order
                    <=> (int) $right->sort_order;

                return $order !== 0
                    ? $order
                    : ((int) $left->id <=> (int) $right->id);
            })
            ->take(6)
            ->values();

        $associationTasks = $plan->tasks
            ->filter(fn (Task $task) =>
                ! in_array($task->status, ['done', 'cancelled'], true)
            )
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        // A completed Task status is a user-recorded result, not proof of
        // CI/Deploy/Release success. Surface it as follow-up context without
        // persisting new Evidence or changing readiness decisions.
        $completedTasks = $plan->tasks
            ->filter(fn (Task $task) => $task->status === 'done')
            ->sort(fn (Task $left, Task $right): int =>
                (($right->updated_at?->getTimestamp() ?? 0)
                    <=> ($left->updated_at?->getTimestamp() ?? 0))
                ?: ((int) $right->id <=> (int) $left->id)
            )
            ->values();
        $recentCompletedTask = $completedTasks->first();
        $hasWorkLog = $recentCompletedTask instanceof Task
            && ($plan->relationLoaded('workLogs')
                ? $plan->workLogs->contains(fn ($log) =>
                    (int) $log->task_id === (int) $recentCompletedTask->id
                )
                : $plan->workLogs()
                    ->where('task_id', $recentCompletedTask->id)
                    ->exists());

        return [
            'recent_activity' => $recentActivity,
            'unresolved_activity' => $unresolved,
            'association_tasks' => $associationTasks,
            'active_tasks' => $activeTasks,
            'recent_completed_task' => $recentCompletedTask,
            'completed_task_count' => $completedTasks->count(),
            'recent_completed_has_work_log' => $hasWorkLog,
        ];
    }
}
