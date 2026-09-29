<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Task;
use App\Services\PlanOwnershipService;
use App\Services\PlanActivityService;
use App\Services\TaskDependencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        PlanActivityService $activity,
        TaskDependencyService $dependencies,
    )
    {
        $this->authorizePlanOwner($plan);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'estimated_minutes' => ['required', 'integer', 'min:0'],
            'remaining_minutes' => ['required', 'integer', 'min:0'],
            'progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['required', 'in:todo,doing,done,cancelled'],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'activation_cost' => ['required', 'integer', 'min:1', 'max:5'],
            'next_action_note' => ['nullable', 'string', 'max:1000'],
            'depends_on_task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('plan_id', $plan->id)),
            ],
            'dependency_task_ids' => ['nullable', 'array', 'max:20'],
            'dependency_task_ids.*' => [
                'integer',
                Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('plan_id', $plan->id)),
            ],
        ]);

        $dependencyIds = $validated['dependency_task_ids']
            ?? (isset($validated['depends_on_task_id']) ? [(int) $validated['depends_on_task_id']] : []);

        $task = DB::transaction(function () use ($plan, $validated, $dependencyIds, $dependencies) {
            $task = Task::create([
                'plan_id' => $plan->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'estimated_minutes' => $validated['estimated_minutes'],
                'remaining_minutes' => $validated['status'] === 'done' ? 0 : $validated['remaining_minutes'],
                'progress_percent' => $validated['progress_percent'],
                'status' => $validated['status'],
                'priority' => $validated['priority'],
                'activation_cost' => $validated['activation_cost'],
                'next_action_note' => $validated['next_action_note'] ?? null,
                'depends_on_task_id' => null,
                'sort_order' => 0,
            ]);

            $dependencies->sync($task, $dependencyIds);

            return $task;
        });

        $activity->record($plan, $request->user(), 'task_created', 'task', (int) $task->id, [
            'task_title' => $task->title,
        ]);

        return redirect()->route('plans.show', $plan);
    }

    public function edit(Task $task)
    {
        $task->load(['plan.tasks', 'plan.resources', 'resources']);

        $this->authorizeOwner($task);

        return view('tasks.edit', compact('task'));
    }

    public function update(
        Request $request,
        Task $task,
        PlanActivityService $activity,
        TaskDependencyService $dependencies,
    )
    {
        $task->load('plan');

        $this->authorizeOwner($task);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'estimated_minutes' => ['required', 'integer', 'min:0'],
            'remaining_minutes' => ['required', 'integer', 'min:0'],
            'progress_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['required', 'in:todo,doing,done,cancelled'],
            'priority' => ['required', 'integer', 'min:1', 'max:5'],
            'activation_cost' => ['required', 'integer', 'min:1', 'max:5'],
            'next_action_note' => ['nullable', 'string', 'max:1000'],
            'depends_on_task_id' => [
                'nullable',
                'integer',
                Rule::exists('tasks', 'id')
                    ->where(fn ($query) => $query->where('plan_id', $task->plan_id)),
                Rule::notIn([$task->id]),
            ],
            'dependency_task_ids' => ['nullable', 'array', 'max:20'],
            'dependency_task_ids.*' => [
                'integer',
                Rule::exists('tasks', 'id')
                    ->where(fn ($query) => $query->where('plan_id', $task->plan_id)),
                Rule::notIn([$task->id]),
            ],
            'resource_ids' => ['nullable', 'array', 'max:100'],
            'resource_ids.*' => [
                'integer',
                Rule::exists('plan_resources', 'id')
                    ->where(fn ($query) => $query->where('plan_id', $task->plan_id)),
            ],
        ]);

        $beforeStatus = $task->status;
        $beforeTitle = $task->title;
        $dependencyIds = $validated['dependency_task_ids']
            ?? (isset($validated['depends_on_task_id']) ? [(int) $validated['depends_on_task_id']] : []);
        $resourceIds = collect($validated['resource_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($task, $validated, $dependencyIds, $resourceIds, $dependencies) {
            $task->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'estimated_minutes' => $validated['estimated_minutes'],
                'remaining_minutes' => $validated['status'] === 'done' ? 0 : $validated['remaining_minutes'],
                'progress_percent' => $validated['progress_percent'],
                'status' => $validated['status'],
                'priority' => $validated['priority'],
                'activation_cost' => $validated['activation_cost'],
                'next_action_note' => $validated['next_action_note'] ?? null,
            ]);

            $dependencies->sync($task, $dependencyIds);
            $task->resources()->sync($resourceIds);
        });

        $action = $beforeStatus !== 'done' && $task->status === 'done' ? 'task_completed' : 'task_updated';
        $activity->record($task->plan, $request->user(), $action, 'task', (int) $task->id, [
            'task_title' => $task->title ?: $beforeTitle,
        ]);

        return redirect()->route('plans.show', $task->plan);
    }

    public function destroy(Task $task, PlanActivityService $activity)
    {
        $task->load('plan');

        $this->authorizeOwner($task);

        $plan = $task->plan;
        $taskTitle = $task->title;
        $taskId = (int) $task->id;

        $task->delete();
        $activity->record($plan, request()->user(), 'task_deleted', 'task', $taskId, [
            'task_title' => $taskTitle,
        ]);

        return redirect()->route('plans.show', $plan);
    }

    private function authorizeOwner(Task $task): void
    {
        app(PlanOwnershipService::class)->authorizeTask(request(), $task);
    }

    private function authorizePlanOwner(Plan $plan): void
    {
        app(PlanOwnershipService::class)->authorizeEdit(request(), $plan);
    }
}
