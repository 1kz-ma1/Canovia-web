<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExecutionDistributionService
{
    public const MAX_TARGETS = 8;

    public function __construct(
        private readonly ExecutionOrchestrationContextService $contexts,
        private readonly ExecutionPacketService $packets,
        private readonly FeatureAccessService $access,
    ) {}

    /**
     * Prepare multiple task-scoped execution instructions from one Plan state.
     *
     * The bundle is projection-only and is stored in session by the controller.
     * Task / Plan / Dependency data remains canonical.
     *
     * @param Collection<int,Task> $tasks
     * @param array<int|string,array<string,mixed>> $targetConfig
     * @return array<string,mixed>
     */
    public function prepare(
        Request $request,
        Plan $plan,
        Collection $tasks,
        array $targetConfig,
        string $generationMode,
    ): array {
        $tasks = $tasks->take(self::MAX_TARGETS)->values();

        if ($generationMode === 'native') {
            foreach ($tasks as $task) {
                $this->access->authorizeUse(
                    $request->user(),
                    FeatureKey::AutomaticAiExecution,
                    ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
                );
            }
        }

        $targets = [];

        foreach ($tasks as $task) {
            $config = is_array($targetConfig[$task->id] ?? null)
                ? $targetConfig[$task->id]
                : [];

            $actorType = (string) ($config['actor_type'] ?? 'human_ai');
            if (! array_key_exists($actorType, ExecutionPacketService::ACTOR_TYPES)) {
                $actorType = 'human_ai';
            }

            $actorLabel = mb_substr(trim((string) ($config['actor_label'] ?? '')), 0, 80);
            if ($actorLabel === '') {
                $actorLabel = (string) $task->title;
            }

            $availableMinutes = isset($config['available_minutes']) && $config['available_minutes'] !== ''
                ? max(5, min(1440, (int) $config['available_minutes']))
                : null;

            $context = $this->contexts->snapshot($request, $plan, $task);
            $taskState = $request->session()->get(
                ExecutionRequestHandoffService::sessionKey($plan, $task),
                [],
            );
            $executionRequest = is_array($taskState['execution_request'] ?? null)
                ? $taskState['execution_request']
                : null;

            $target = [
                'task_id' => (int) $task->id,
                'task_title' => (string) $task->title,
                'actor_label' => $actorLabel,
                'actor_type' => $actorType,
                'available_minutes' => $availableMinutes,
                'dependency_state' => (string) ($context['dependency_state'] ?? 'ready'),
                'context_fingerprint' => (string) ($context['context_fingerprint'] ?? ''),
                'execution_request' => $executionRequest,
                'packet' => null,
                'handoff_prompt' => null,
                'native_error' => null,
                'stale' => false,
            ];

            if ($generationMode === 'native') {
                if ($this->packets->nativeConfigured()) {
                    try {
                        $generated = $this->packets->generateNative(
                            $context,
                            $plan,
                            $task,
                            $request->user(),
                            $actorType,
                            $availableMinutes,
                            $executionRequest,
                            $actorLabel,
                        );
                        $target['packet'] = $generated['packet'];
                        $target['native_run_id'] = $generated['run_id'];
                    } catch (NativeAiExecutionException $exception) {
                        $target['native_error'] = mb_substr($exception->getMessage(), 0, 1200);
                        $target['handoff_prompt'] = $this->packets->prompt(
                            $context,
                            $actorType,
                            $availableMinutes,
                            $executionRequest,
                            $actorLabel,
                        );
                    }
                } else {
                    $target['native_error'] = 'Native AIが利用できないため、外部AI用Promptへ切り替えました。';
                    $target['handoff_prompt'] = $this->packets->prompt(
                        $context,
                        $actorType,
                        $availableMinutes,
                        $executionRequest,
                        $actorLabel,
                    );
                }
            } else {
                $target['handoff_prompt'] = $this->packets->prompt(
                    $context,
                    $actorType,
                    $availableMinutes,
                    $executionRequest,
                    $actorLabel,
                );
            }

            $targets[] = $target;
        }

        $fingerprintPayload = collect($targets)
            ->map(fn (array $target) => [
                'task_id' => $target['task_id'],
                'context_fingerprint' => $target['context_fingerprint'],
                'actor_label' => $target['actor_label'],
                'actor_type' => $target['actor_type'],
                'available_minutes' => $target['available_minutes'],
            ])
            ->values()
            ->all();

        return [
            'schema_version' => '1.0',
            'flow' => 'execution_distribution',
            'plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
            ],
            'generation_mode' => $generationMode,
            'targets' => $targets,
            'bundle_fingerprint' => hash(
                'sha256',
                (string) json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ),
        ];
    }

    /**
     * Re-evaluate whether each target still matches the canonical context.
     *
     * @return array<string,mixed>
     */
    public function refreshStaleness(Request $request, Plan $plan, array $bundle): array
    {
        $targets = is_array($bundle['targets'] ?? null) ? $bundle['targets'] : [];
        $taskIds = collect($targets)
            ->pluck('task_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $tasks = $plan->tasks()
            ->whereIn('id', $taskIds->all())
            ->get()
            ->keyBy(fn (Task $task) => (int) $task->id);

        $bundle['targets'] = collect($targets)
            ->map(function ($target) use ($request, $plan, $tasks) {
                if (! is_array($target)) {
                    return null;
                }

                $taskId = (int) ($target['task_id'] ?? 0);
                $task = $tasks->get($taskId);
                if (! $task instanceof Task) {
                    $target['stale'] = true;
                    $target['stale_reason'] = '対象Taskが現在のPlanに存在しません。';

                    return $target;
                }

                $context = $this->contexts->snapshot($request, $plan, $task);
                $expected = (string) ($target['context_fingerprint'] ?? '');
                $current = (string) ($context['context_fingerprint'] ?? '');

                $target['stale'] = $expected === '' || ! hash_equals($current, $expected);
                $target['stale_reason'] = $target['stale']
                    ? 'Task / Dependency / EvidenceなどのContextが生成後に変わっています。'
                    : null;
                $target['dependency_state'] = (string) ($context['dependency_state'] ?? 'ready');

                return $target;
            })
            ->filter()
            ->values()
            ->all();

        return $bundle;
    }

    public static function sessionKey(Plan $plan): string
    {
        return 'execution_distribution.'.$plan->id;
    }
}
