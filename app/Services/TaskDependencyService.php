<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskDependencyService
{
    /** @param array<int,int|string> $dependencyIds */
    public function sync(Task $task, array $dependencyIds): void
    {
        $dependencyIds = collect($dependencyIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        if ($dependencyIds->contains((int) $task->id)) {
            throw ValidationException::withMessages([
                'dependency_task_ids' => 'Task自身を前提Taskにはできません。',
            ]);
        }

        $validIds = Task::query()
            ->where('plan_id', $task->plan_id)
            ->whereIn('id', $dependencyIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        if ($validIds->count() !== $dependencyIds->count()) {
            throw ValidationException::withMessages([
                'dependency_task_ids' => '同じPlan内のTaskだけを前提として選択できます。',
            ]);
        }

        $this->assertAcyclic($task, $validIds->all());

        DB::transaction(function () use ($task, $validIds) {
            $task->prerequisites()->sync($validIds->all());
            Task::query()->whereKey($task->id)->update([
                'depends_on_task_id' => $validIds->first(),
            ]);
            $task->setAttribute('depends_on_task_id', $validIds->first());
            $task->unsetRelation('prerequisites');
        });
    }

    /** @param array<int,int> $replacementDependencies */
    private function assertAcyclic(Task $task, array $replacementDependencies): void
    {
        $tasks = Task::query()
            ->where('plan_id', $task->plan_id)
            ->get(['id', 'depends_on_task_id']);
        $taskIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
        $graph = array_fill_keys($taskIds, []);

        DB::table('task_dependencies')
            ->whereIn('task_id', $taskIds)
            ->get(['task_id', 'prerequisite_task_id'])
            ->each(function ($edge) use (&$graph) {
                $taskId = (int) $edge->task_id;
                $dependencyId = (int) $edge->prerequisite_task_id;
                $graph[$taskId] = array_values(array_unique([
                    ...($graph[$taskId] ?? []),
                    $dependencyId,
                ]));
            });

        foreach ($tasks as $item) {
            if (! $item->depends_on_task_id) {
                continue;
            }

            $id = (int) $item->id;
            $graph[$id] = array_values(array_unique([
                ...($graph[$id] ?? []),
                (int) $item->depends_on_task_id,
            ]));
        }

        $graph[(int) $task->id] = $replacementDependencies;

        foreach ($replacementDependencies as $dependencyId) {
            if ($this->reaches($graph, $dependencyId, (int) $task->id, [])) {
                throw ValidationException::withMessages([
                    'dependency_task_ids' => '循環するDependencyは作成できません。',
                ]);
            }
        }
    }

    /** @param array<int,array<int,int>> $graph @param array<int,bool> $visited */
    private function reaches(array $graph, int $from, int $target, array $visited): bool
    {
        if ($from === $target) {
            return true;
        }
        if (isset($visited[$from])) {
            return false;
        }

        $visited[$from] = true;
        foreach ($graph[$from] ?? [] as $next) {
            if ($this->reaches($graph, (int) $next, $target, $visited)) {
                return true;
            }
        }

        return false;
    }
}
