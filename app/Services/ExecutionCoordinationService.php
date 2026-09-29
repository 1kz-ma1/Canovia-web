<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExecutionCoordinationService
{
    public const MAX_RECOMMENDED_TARGETS = 8;

    public function __construct(
        private readonly ExecutionOrchestrationContextService $contexts,
        private readonly ExecutionDistributionService $distribution,
    ) {}

    /**
     * Re-evaluate the execution surface after a completed Task changes the
     * dependency graph.
     *
     * This is projection-only. It never creates a Packet, changes another Task,
     * or chooses an actor automatically.
     *
     * @return array<string,mixed>|null
     */
    public function projection(
        Request $request,
        Plan $plan,
        Task $sourceTask,
    ): ?array {
        if (! $this->isComplete($sourceTask)) {
            return null;
        }

        $plan->load([
            'tasks' => fn ($query) => $query
                ->with('prerequisites')
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);

        /** @var Task|null $canonicalSource */
        $canonicalSource = $plan->tasks->firstWhere('id', (int) $sourceTask->id);
        if (! $canonicalSource instanceof Task || ! $this->isComplete($canonicalSource)) {
            return null;
        }

        $taskMap = $plan->tasks->keyBy(fn (Task $task) => (int) $task->id);

        $dependents = $plan->tasks
            ->filter(fn (Task $task) =>
                (int) $task->id !== (int) $canonicalSource->id
                && in_array((int) $canonicalSource->id, $task->dependencyIds(), true)
            )
            ->values();

        $readyDependents = collect();
        $blockedDependents = collect();
        $staleIndividual = collect();

        foreach ($dependents as $dependent) {
            if (in_array($dependent->status, ['done', 'cancelled'], true)) {
                continue;
            }

            $state = $this->dependentState($dependent, $taskMap);

            if ($state['dependency_state'] === 'ready') {
                $readyDependents->push($state);
            } else {
                $blockedDependents->push($state);
            }

            $individualState = $request->session()->get(
                ExecutionRequestHandoffService::sessionKey($plan, $dependent),
                [],
            );

            $hasGeneratedInstruction = is_array($individualState)
                && (
                    is_array($individualState['packet'] ?? null)
                    || filled($individualState['handoff_prompt'] ?? null)
                );

            if (! $hasGeneratedInstruction) {
                continue;
            }

            $context = $this->contexts->snapshot($request, $plan, $dependent);
            $expected = (string) ($individualState['context_fingerprint'] ?? '');
            $current = (string) ($context['context_fingerprint'] ?? '');

            if ($expected === '' || ! hash_equals($expected, $current)) {
                $staleIndividual->push([
                    'task_id' => (int) $dependent->id,
                    'task_title' => (string) $dependent->title,
                    'dependency_state' => (string) ($context['dependency_state'] ?? $state['dependency_state']),
                    'reason' => 'Dependency / Evidence / Task Contextが変わったため、以前の個別指示は再生成が必要です。',
                ]);
            }
        }

        $distributionBundle = $request->session()->get(
            ExecutionDistributionService::sessionKey($plan),
        );
        $staleDistribution = collect();

        if (is_array($distributionBundle)) {
            $distributionBundle = $this->distribution->refreshStaleness(
                $request,
                $plan,
                $distributionBundle,
            );
            $request->session()->put(
                ExecutionDistributionService::sessionKey($plan),
                $distributionBundle,
            );

            $staleDistribution = collect((array) ($distributionBundle['targets'] ?? []))
                ->filter(fn ($target) =>
                    is_array($target)
                    && (bool) ($target['stale'] ?? false)
                )
                ->map(function (array $target) use ($taskMap) {
                    $taskId = (int) ($target['task_id'] ?? 0);
                    $task = $taskMap->get($taskId);

                    return [
                        'task_id' => $taskId,
                        'task_title' => (string) ($target['task_title'] ?? $task?->title ?? 'Task'),
                        'actor_label' => (string) ($target['actor_label'] ?? ''),
                        'dependency_state' => (string) ($target['dependency_state'] ?? 'ready'),
                        'task_status' => $task?->status,
                        'active' => $task instanceof Task
                            && ! in_array($task->status, ['done', 'cancelled'], true),
                        'reason' => (string) ($target['stale_reason'] ?? 'Contextが変わったため再生成が必要です。'),
                    ];
                })
                ->values();
        }

        $recommendedTaskIds = collect()
            ->merge($readyDependents->pluck('task_id'))
            ->merge(
                $staleDistribution
                    ->filter(fn (array $target) =>
                        ($target['active'] ?? false)
                        && ($target['dependency_state'] ?? 'ready') === 'ready'
                    )
                    ->pluck('task_id')
            )
            ->merge(
                $staleIndividual
                    ->filter(fn (array $target) =>
                        ($target['dependency_state'] ?? 'ready') === 'ready'
                    )
                    ->pluck('task_id')
            )
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) =>
                $id > 0
                && $id !== (int) $canonicalSource->id
                && ($taskMap->get($id) instanceof Task)
                && ! in_array($taskMap->get($id)->status, ['done', 'cancelled'], true)
            )
            ->unique()
            ->take(self::MAX_RECOMMENDED_TARGETS)
            ->values();

        $hasImpact = $readyDependents->isNotEmpty()
            || $blockedDependents->isNotEmpty()
            || $staleIndividual->isNotEmpty()
            || $staleDistribution->isNotEmpty();

        if (! $hasImpact) {
            return null;
        }

        return [
            'schema_version' => '1.0',
            'flow' => 'execution_coordination',
            'source_task' => [
                'id' => (int) $canonicalSource->id,
                'title' => (string) $canonicalSource->title,
                'status' => (string) $canonicalSource->status,
                'progress_percent' => (int) $canonicalSource->progress_percent,
            ],
            'ready_dependents' => $readyDependents->values()->all(),
            'blocked_dependents' => $blockedDependents->values()->all(),
            'stale_individual_instructions' => $staleIndividual->values()->all(),
            'stale_distribution_targets' => $staleDistribution->values()->all(),
            'recommended_distribution_task_ids' => $recommendedTaskIds->all(),
            'summary' => [
                'ready_dependents' => $readyDependents->count(),
                'blocked_dependents' => $blockedDependents->count(),
                'stale_individual_instructions' => $staleIndividual->count(),
                'stale_distribution_targets' => $staleDistribution->count(),
            ],
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param Collection<int,Task> $taskMap
     * @return array<string,mixed>
     */
    private function dependentState(Task $task, Collection $taskMap): array
    {
        $dependencyIds = $task->dependencyIds();

        $blockers = collect($dependencyIds)
            ->map(fn (int $id) => $taskMap->get($id))
            ->filter(fn ($dependency) => $dependency instanceof Task)
            ->filter(fn (Task $dependency) => ! $this->isComplete($dependency))
            ->map(fn (Task $dependency) => [
                'task_id' => (int) $dependency->id,
                'title' => (string) $dependency->title,
                'status' => (string) $dependency->status,
                'progress_percent' => (int) $dependency->progress_percent,
            ])
            ->values();

        return [
            'task_id' => (int) $task->id,
            'task_title' => (string) $task->title,
            'status' => (string) $task->status,
            'progress_percent' => (int) $task->progress_percent,
            'dependency_state' => $blockers->isEmpty() ? 'ready' : 'blocked',
            'dependency_ids' => $dependencyIds,
            'blockers' => $blockers->all(),
        ];
    }

    private function isComplete(Task $task): bool
    {
        return $task->status === 'done' || (int) $task->progress_percent >= 100;
    }
}
