<?php

namespace App\Intelligence\Study;

use App\Intelligence\Data\StudyAdaptiveActionResult;
use App\Intelligence\Services\ActionProjectionStore;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StudyActionTaskProjector
{
    public function __construct(
        private readonly ActionProjectionStore $actionStore,
    ) {}

    /**
     * @return array{task:Task,created:bool}
     */
    public function project(
        Plan $plan,
        StudyAdaptiveActionResult $result,
    ): array {
        $action = $result->primaryAction();
        $projection = $result->projection;

        if (! $action || ! $projection) {
            throw new InvalidArgumentException(
                'A persisted current Action is required before Task projection.',
            );
        }

        $existingProjected = $projection->projectedTask;
        if (
            $existingProjected
            && (int) $existingProjected->plan_id === (int) $plan->id
        ) {
            return [
                'task' => $existingProjected,
                'created' => false,
            ];
        }

        $targetTaskId = filter_var(
            data_get($action->metadata, 'target_task_id'),
            FILTER_VALIDATE_INT,
        );

        if ($targetTaskId !== false && (int) $targetTaskId > 0) {
            $target = Task::query()
                ->where('plan_id', $plan->id)
                ->whereKey((int) $targetTaskId)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->where('progress_percent', '<', 100)
                ->first();

            if ($target) {
                $this->actionStore->attachProjectedTask(
                    $projection,
                    $target,
                );

                return [
                    'task' => $target,
                    'created' => false,
                ];
            }
        }

        if (
            (string) data_get($action->metadata, 'route_kind')
            !== 'project_task'
        ) {
            throw new InvalidArgumentException(
                'This Action does not require a new Task projection.',
            );
        }

        $task = DB::transaction(function () use ($plan, $action) {
            $sortOrder = (int) Task::query()
                ->where('plan_id', $plan->id)
                ->max('sort_order');

            return Task::query()->create([
                'plan_id' => (int) $plan->id,
                'title' => mb_substr($action->title, 0, 255),
                'description' => mb_substr(
                    $action->intent
                    ."\n\nCanovia Intelligence Actionから作成。"
                    ." Task進捗はStudy Stateの真実としては使用しません。",
                    0,
                    4000,
                ),
                // V53.6 does not invent a minute estimate merely to satisfy Task shape.
                'estimated_minutes' => 0,
                'remaining_minutes' => 0,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
                'sort_order' => $sortOrder + 1,
                'next_action_note' => mb_substr($action->title, 0, 1000),
            ]);
        }, 3);

        $this->actionStore->attachProjectedTask(
            $projection,
            $task,
        );

        return [
            'task' => $task,
            'created' => true,
        ];
    }
}
