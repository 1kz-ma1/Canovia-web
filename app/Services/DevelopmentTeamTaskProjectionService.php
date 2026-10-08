<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * Read-only projection of recorded team tasks and their actual dependencies.
 *
 * A Task has no direct assignee field. Artifact ownership and update history
 * must never be presented as proof that a person owns a Task.
 */
final class DevelopmentTeamTaskProjectionService
{
    private const MAX_DISPLAY = 80;

    /**
     * @return array{
     *     total_open:int,
     *     total_done:int,
     *     visible_count:int,
     *     truncated:bool,
     *     blocked_count:int,
     *     paused_count:int,
     *     tasks:Collection<int,array<string,mixed>>
     * }
     */
    public function project(Plan $plan): array
    {
        if (! $plan->is_collaborative) {
            return $this->empty();
        }

        $tasks = $plan->tasks()
            ->whereIn('status', ['todo', 'doing', 'paused'])
            ->with([
                'prerequisites:id,plan_id,title,status',
                'prerequisite:id,plan_id,title,status',
            ])
            ->orderBy('priority')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_DISPLAY)
            ->get();

        $totalOpen = $plan->tasks()
            ->whereIn('status', ['todo', 'doing', 'paused'])
            ->count();

        $totalDone = $plan->tasks()
            ->where('status', 'done')
            ->count();

        $items = $tasks->map(function (Task $task) use ($plan): array {
            $prerequisites = $task->prerequisites
                ->concat($task->prerequisite ? [$task->prerequisite] : [])
                ->filter(fn (Task $candidate): bool =>
                    (int) $candidate->plan_id === (int) $plan->id
                    && (int) $candidate->id !== (int) $task->id
                )
                ->unique('id')
                ->values();

            $blockedBy = $prerequisites
                ->filter(fn (Task $candidate): bool => $candidate->status !== 'done')
                ->map(fn (Task $candidate): array => [
                    'id' => (int) $candidate->id,
                    'title' => (string) $candidate->title,
                    'status' => (string) $candidate->status,
                ])
                ->values()
                ->all();

            return [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'progress_percent' => (int) ($task->progress_percent ?? 0),
                'blocked_by' => $blockedBy,
                'is_blocked' => count($blockedBy) > 0,
            ];
        });

        // Priority within the inspected sample: actual prerequisites first,
        // then paused work, then active and not-started work.
        $items = $items->sort(function (array $left, array $right): int {
            $rank = fn (array $task): int =>
                $task['is_blocked'] ? 0
                : ($task['status'] === 'paused' ? 1
                    : ($task['status'] === 'doing' ? 2 : 3));

            return $rank($left) <=> $rank($right)
                ?: $left['id'] <=> $right['id'];
        })->values();

        return [
            'total_open' => $totalOpen,
            'total_done' => $totalDone,
            'visible_count' => $items->count(),
            'truncated' => $totalOpen > $items->count(),
            // Counts refer to the inspected sample only, not all Tasks.
            'blocked_count' => $items->where('is_blocked', true)->count(),
            'paused_count' => $items->where('status', 'paused')->count(),
            'tasks' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function empty(): array
    {
        return [
            'total_open' => 0,
            'total_done' => 0,
            'visible_count' => 0,
            'truncated' => false,
            'blocked_count' => 0,
            'paused_count' => 0,
            'tasks' => collect(),
        ];
    }
}
