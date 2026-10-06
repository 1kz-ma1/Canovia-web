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
     *   active_tasks:Collection<int,Task>
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

        return [
            'recent_activity' => $recentActivity,
            'unresolved_activity' => $unresolved,
            'association_tasks' => $associationTasks,
            'active_tasks' => $activeTasks,
        ];
    }
}
