<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExecutionOrchestrationContextService
{
    public function __construct(
        private readonly CoreContextService $core,
    ) {}

    /**
     * Build a canonical, read-only snapshot for one execution node.
     *
     * Map and AI consume this snapshot; neither becomes a source of truth.
     *
     * @return array<string,mixed>
     */
    public function snapshot(Request $request, Plan $plan, Task $task): array
    {
        $plans = $this->core->plans($request, [
            'tasks',
            'task_dependencies',
            'task_artifacts',
            'plan_resources',
            'plan_artifacts',
            'work_logs',
        ]);

        $canonicalPlan = $plans->firstWhere('id', (int) $plan->id);
        abort_unless($canonicalPlan instanceof Plan, 404);

        $canonicalTask = $canonicalPlan->tasks->firstWhere('id', (int) $task->id);
        abort_unless($canonicalTask instanceof Task, 404);

        $canonicalPlan->loadMissing('goalContext.facts');
        $canonicalTask->loadMissing(['prerequisites', 'resources', 'artifacts']);

        $dependencyIds = $canonicalTask->dependencyIds();
        $dependencies = $canonicalPlan->tasks
            ->filter(fn (Task $candidate) => in_array((int) $candidate->id, $dependencyIds, true))
            ->values();

        $unmetDependencies = $dependencies
            ->filter(fn (Task $dependency) => ! $this->isComplete($dependency))
            ->values();

        $dependents = $canonicalPlan->tasks
            ->filter(fn (Task $candidate) => (int) $candidate->id !== (int) $canonicalTask->id)
            ->filter(fn (Task $candidate) => in_array((int) $canonicalTask->id, $candidate->dependencyIds(), true))
            ->values();

        $goal = $canonicalPlan->goalContext;
        $facts = collect($goal?->facts ?? []);
        $confirmedFacts = $facts->where('state', 'confirmed')->values();
        $knownUnknowns = $facts->where('state', 'unknown')->values();

        $recentEvidence = TaskEvidence::query()
            ->where('plan_id', $canonicalPlan->id)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(12)
            ->get();

        $snapshot = [
            'version' => 1,
            'plan' => [
                'id' => (int) $canonicalPlan->id,
                'title' => (string) $canonicalPlan->title,
                'category' => $canonicalPlan->category,
                'description' => $canonicalPlan->description,
                'deadline' => $canonicalPlan->deadline?->toDateString(),
                'goal' => $goal ? [
                    'desired_state' => $goal->desired_state,
                    'current_state_summary' => $goal->current_state_summary,
                    'readiness_state' => $goal->readiness_state,
                ] : null,
            ],
            'selected_node' => $this->taskSnapshot($canonicalTask),
            'dependency_state' => $unmetDependencies->isEmpty() ? 'ready' : 'blocked',
            'dependencies' => $dependencies->map(fn (Task $item) => [
                ...$this->taskSnapshot($item),
                'satisfied' => $this->isComplete($item),
            ])->all(),
            'blockers' => $unmetDependencies->map(fn (Task $item) => [
                'task_id' => (int) $item->id,
                'title' => (string) $item->title,
                'reason' => '前提Taskがまだ完了していません。',
            ])->all(),
            'dependents' => $dependents->map(fn (Task $item) => $this->taskSnapshot($item))->all(),
            'available_inputs' => $this->availableInputs($canonicalPlan, $canonicalTask, $dependencies),
            'current_tasks' => $canonicalPlan->tasks
                ->filter(fn (Task $item) => ! in_array($item->status, ['done', 'cancelled'], true))
                ->take(30)
                ->map(fn (Task $item) => $this->taskSnapshot($item))
                ->values()
                ->all(),
            'protected_scope' => $canonicalPlan->tasks
                ->filter(fn (Task $item) => (int) $item->id !== (int) $canonicalTask->id)
                ->take(30)
                ->map(fn (Task $item) => [
                    'task_id' => (int) $item->id,
                    'title' => (string) $item->title,
                    'description' => $item->description,
                    'rule' => 'このTaskの責任範囲は、明示的な変更確認なしに変更しない。',
                ])
                ->values()
                ->all(),
            'constraints' => $confirmedFacts
                ->where('type', 'constraint')
                ->map(fn ($fact) => $this->factSnapshot($fact))
                ->all(),
            'confirmed_context' => $confirmedFacts
                ->take(12)
                ->map(fn ($fact) => $this->factSnapshot($fact))
                ->all(),
            'known_unknowns' => $knownUnknowns
                ->take(10)
                ->map(fn ($fact) => $this->factSnapshot($fact))
                ->all(),
            'recent_evidence' => $recentEvidence
                ->map(fn (TaskEvidence $evidence) => [
                    'task_id' => (int) $evidence->task_id,
                    'type' => $evidence->type,
                    'label' => $evidence->typeLabel(),
                    'summary' => $evidence->summary(),
                    'confidence' => (float) $evidence->confidence,
                    'occurred_at' => $evidence->occurred_at?->toIso8601String(),
                ])
                ->all(),
            'recent_results' => $canonicalPlan->workLogs
                ->take(8)
                ->map(fn ($log) => [
                    'task_id' => $log->task_id ? (int) $log->task_id : null,
                    'task_title' => $log->task?->title,
                    'worked_on' => $log->worked_on?->toDateString(),
                    'outcome' => $log->outcome ?: $log->memo,
                    'progress_after_percent' => $log->progress_after_percent,
                ])
                ->values()
                ->all(),
            'personalization' => [
                'interaction_profile' => is_array($goal?->interaction_profile) ? $goal->interaction_profile : [],
                'activation_cost' => (int) ($canonicalTask->activation_cost ?? 3),
                'remaining_minutes' => (int) ($canonicalTask->remaining_minutes ?? 0),
            ],
        ];

        $snapshot['context_fingerprint'] = hash(
            'sha256',
            (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function taskSnapshot(Task $task): array
    {
        return [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
            'description' => $task->description,
            'status' => (string) $task->status,
            'progress_percent' => (int) $task->progress_percent,
            'remaining_minutes' => (int) ($task->remaining_minutes ?? 0),
            'priority' => (int) $task->priority,
            'next_action_note' => $task->next_action_note,
            'dependency_ids' => $task->dependencyIds(),
        ];
    }

    /** @return array<string,mixed> */
    private function factSnapshot($fact): array
    {
        return [
            'type' => $fact->type,
            'key' => $fact->key,
            'label' => $fact->label,
            'value' => $fact->value_json,
            'source' => $fact->source,
            'confidence' => (float) $fact->confidence,
        ];
    }

    /**
     * @param Collection<int,Task> $dependencies
     * @return array<int,array<string,mixed>>
     */
    private function availableInputs(Plan $plan, Task $task, Collection $dependencies): array
    {
        $inputs = collect();

        foreach ($plan->resources as $resource) {
            $inputs->push([
                'kind' => 'plan_resource',
                'source_task_id' => null,
                'title' => (string) $resource->title,
                'provider' => (string) $resource->provider,
                'url' => $resource->url,
            ]);
        }

        foreach ($plan->artifacts as $artifact) {
            $inputs->push([
                'kind' => 'plan_artifact',
                'source_task_id' => null,
                'title' => (string) $artifact->title,
                'provider' => (string) $artifact->provider,
                'url' => $artifact->url,
            ]);
        }

        foreach ($task->resources as $resource) {
            $inputs->push([
                'kind' => 'resource',
                'source_task_id' => (int) $task->id,
                'title' => (string) $resource->title,
                'provider' => (string) $resource->provider,
                'url' => $resource->url,
            ]);
        }

        foreach ($task->artifacts as $artifact) {
            $inputs->push([
                'kind' => 'artifact',
                'source_task_id' => (int) $task->id,
                'title' => (string) $artifact->title,
                'provider' => (string) $artifact->provider,
                'url' => $artifact->url,
            ]);
        }

        foreach ($dependencies as $dependency) {
            $dependency->loadMissing(['resources', 'artifacts']);

            foreach ($dependency->artifacts as $artifact) {
                $inputs->push([
                    'kind' => 'dependency_artifact',
                    'source_task_id' => (int) $dependency->id,
                    'source_task_title' => (string) $dependency->title,
                    'source_complete' => $this->isComplete($dependency),
                    'title' => (string) $artifact->title,
                    'provider' => (string) $artifact->provider,
                    'url' => $artifact->url,
                ]);
            }

            foreach ($dependency->resources as $resource) {
                $inputs->push([
                    'kind' => 'dependency_resource',
                    'source_task_id' => (int) $dependency->id,
                    'source_task_title' => (string) $dependency->title,
                    'source_complete' => $this->isComplete($dependency),
                    'title' => (string) $resource->title,
                    'provider' => (string) $resource->provider,
                    'url' => $resource->url,
                ]);
            }
        }

        return $inputs
            ->unique(fn (array $item) => implode('|', [
                $item['kind'] ?? '',
                $item['source_task_id'] ?? '',
                $item['title'] ?? '',
                $item['url'] ?? '',
            ]))
            ->take(30)
            ->values()
            ->all();
    }

    private function isComplete(Task $task): bool
    {
        return $task->status === 'done' || (int) $task->progress_percent >= 100;
    }
}
