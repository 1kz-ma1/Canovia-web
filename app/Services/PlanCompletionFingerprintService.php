<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final class PlanCompletionFingerprintService
{
    public const SCHEMA_VERSION = 1;

    /**
     * Completion fingerprint describes the material Plan structure.
     *
     * Runtime completion state such as status/progress/remaining time is
     * deliberately excluded so reopen -> re-complete without material edits
     * converges to the same fingerprint.
     *
     * @return array{
     *   schema_version:int,
     *   fingerprint:string,
     *   task_count:int
     * }
     */
    public function snapshot(Plan $plan): array
    {
        $tasks = Task::query()
            ->where('plan_id', $plan->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get();

        $taskIds = $tasks
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $dependencies = [];

        if ($taskIds !== []) {
            DB::table('task_dependencies')
                ->whereIn('task_id', $taskIds)
                ->orderBy('task_id')
                ->orderBy('prerequisite_task_id')
                ->get([
                    'task_id',
                    'prerequisite_task_id',
                ])
                ->each(function ($edge) use (&$dependencies): void {
                    $taskId = (int) $edge->task_id;
                    $dependencies[$taskId] ??= [];
                    $dependencies[$taskId][] =
                        (int) $edge->prerequisite_task_id;
                });
        }

        $taskPayloads = $tasks
            ->map(function (Task $task) use ($dependencies): array {
                $dependencyIds = collect(
                    $dependencies[(int) $task->id]
                    ?? [],
                );

                if ($task->depends_on_task_id) {
                    $dependencyIds->push(
                        (int) $task->depends_on_task_id,
                    );
                }

                return [
                    'id' => (int) $task->id,
                    'title' => (string) $task->title,
                    'description' => $task->description,
                    'estimated_minutes' =>
                        (int) $task->estimated_minutes,
                    'priority' => (int) $task->priority,
                    'activation_cost' =>
                        (int) $task->activation_cost,
                    'sort_order' => (int) $task->sort_order,
                    'continuation_of_task_id' =>
                        $task->continuation_of_task_id !== null
                            ? (int) $task->continuation_of_task_id
                            : null,
                    'dependency_task_ids' => $dependencyIds
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => $id > 0)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
                'description' => $plan->description,
                'category' => $plan->category,
                'priority' => (int) $plan->priority,
                'priority_mode' =>
                    (string) $plan->priority_mode,
                'start_date' =>
                    $plan->start_date?->toDateString(),
                'deadline' =>
                    $plan->deadline?->toDateString(),
                'study_learning_type_override' =>
                    $plan->study_learning_type_override,
            ],
            'tasks' => $taskPayloads,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fingerprint' => hash(
                'sha256',
                (string) json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
            ),
            'task_count' => count($taskPayloads),
        ];
    }
}
