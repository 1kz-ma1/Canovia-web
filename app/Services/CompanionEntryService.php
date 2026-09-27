<?php

namespace App\Services;

use App\Models\CompanionThread;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanionEntryService
{
    public const ENTRY_TYPES = [
        'global',
        'plan',
        'task',
        'guided_execution',
        'inbox_item',
        'map',
    ];

    public function __construct(
        private readonly PlanOwnershipService $ownership,
        private readonly MapProjectionService $maps,
    ) {}

    /**
     * Open or reuse the active Companion thread for the current Canovia context.
     *
     * @param array<string,mixed> $input
     */
    public function open(Request $request, array $input): CompanionThread
    {
        $user = $request->user();
        abort_unless($user, 401);

        $entryType = trim((string) ($input['entry_type'] ?? 'global'));
        if (! in_array($entryType, self::ENTRY_TYPES, true)) {
            throw ValidationException::withMessages([
                'entry_type' => 'Companionの入口を確認してください。',
            ]);
        }

        $mapContext = null;
        if ($entryType === 'map') {
            $mapContext = $this->maps->companionContext(
                $request,
                trim((string) ($input['map_node_id'] ?? '')),
            );

            if (! $mapContext) {
                throw ValidationException::withMessages([
                    'map_node_id' => 'Mapの選択Contextを最新状態で確認できませんでした。',
                ]);
            }
        }

        [$plan, $task, $inboxItem] = $this->resolveTargets($request, $entryType, $input, $mapContext);

        $scope = $task ? 'task' : ($plan ? 'plan' : 'global');
        $entryKey = match ($entryType) {
            'inbox_item' => 'inbox:'.(int) $inboxItem->id,
            'task', 'guided_execution' => 'task:'.(int) $task->id,
            'plan' => 'plan:'.(int) $plan->id,
            'map' => $inboxItem
                ? 'inbox:'.(int) $inboxItem->id
                : ($task
                    ? 'task:'.(int) $task->id
                    : ($plan ? 'plan:'.(int) $plan->id : 'global')),
            default => 'global',
        };

        $contextScope = array_filter([
            'scope' => $scope,
            'entry_type' => $entryType,
            'entry_key' => $entryKey,
            'source_path' => $this->nullableTrim($input['source_path'] ?? null),
            'source_route' => $this->nullableTrim($input['source_route'] ?? null),
            'inbox_item_id' => $inboxItem?->id,
            'map_node_id' => $entryType === 'map' ? $this->nullableTrim($input['map_node_id'] ?? null) : null,
            'map_context' => $entryType === 'map' ? $mapContext : null,
        ], fn ($value) => $value !== null && $value !== '');

        $thread = $this->findReusableThread(
            userId: (int) $user->id,
            planId: $plan?->id,
            taskId: $task?->id,
            entryKey: $entryKey,
            scope: $scope,
            allowLegacyScopeReuse: $inboxItem === null && $entryType !== 'inbox_item',
        );

        if ($thread) {
            $thread->update(['context_scope' => $contextScope]);

            return $thread->fresh(['plan', 'task']);
        }

        return CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => $contextScope,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $mapContext
     * @return array{0:?Plan,1:?Task,2:?InboxItem}
     */
    private function resolveTargets(Request $request, string $entryType, array $input, ?array $mapContext = null): array
    {

        if ($entryType === 'map') {
            return $this->resolveMapTargets($request, $mapContext ?? []);
        }

        if ($entryType === 'global') {
            return [null, null, null];
        }

        if ($entryType === 'plan') {
            $plan = $this->requirePlan($input['plan_id'] ?? null);
            $this->ownership->authorizeEdit($request, $plan);

            return [$plan, null, null];
        }

        if (in_array($entryType, ['task', 'guided_execution'], true)) {
            $task = $this->requireTask($input['task_id'] ?? null);
            $task->loadMissing('plan');
            $this->ownership->authorizeTask($request, $task);

            if (filled($input['plan_id'] ?? null)) {
                abort_unless((int) $input['plan_id'] === (int) $task->plan_id, 404);
            }

            return [$task->plan, $task, null];
        }

        $inboxItem = InboxItem::query()
            ->with('plan')
            ->findOrFail((int) ($input['inbox_item_id'] ?? 0));

        abort_unless(
            $request->user()
            && (int) $inboxItem->user_id === (int) $request->user()->id,
            404,
        );

        $plan = $inboxItem->plan;
        if ($plan) {
            $this->ownership->authorizeEdit($request, $plan);
        }

        return [$plan, null, $inboxItem];
    }


    /**
     * @param array<string,mixed> $mapContext
     * @return array{0:?Plan,1:?Task,2:?InboxItem}
     */
    private function resolveMapTargets(Request $request, array $mapContext): array
    {
        $inboxItemId = (int) data_get($mapContext, 'target.inbox_item_id', 0);
        if ($inboxItemId > 0) {
            $item = InboxItem::query()->with('plan')->findOrFail($inboxItemId);

            abort_unless(
                $request->user()
                && (int) $item->user_id === (int) $request->user()->id,
                404,
            );

            if ($item->plan) {
                $this->ownership->authorizeEdit($request, $item->plan);
            }

            return [$item->plan, null, $item];
        }

        $taskId = (int) data_get($mapContext, 'target.task_id', 0);
        if ($taskId > 0) {
            $task = Task::query()->with('plan')->findOrFail($taskId);
            $this->ownership->authorizeTask($request, $task);

            $planId = (int) data_get($mapContext, 'target.plan_id', 0);
            if ($planId > 0) {
                abort_unless($planId === (int) $task->plan_id, 404);
            }

            return [$task->plan, $task, null];
        }

        $planId = (int) data_get($mapContext, 'target.plan_id', 0);
        if ($planId > 0) {
            $plan = Plan::query()->findOrFail($planId);
            $this->ownership->authorizeEdit($request, $plan);

            return [$plan, null, null];
        }

        return [null, null, null];
    }

    private function findReusableThread(
        int $userId,
        ?int $planId,
        ?int $taskId,
        string $entryKey,
        string $scope,
        bool $allowLegacyScopeReuse,
    ): ?CompanionThread {
        $threads = CompanionThread::query()
            ->where('user_id', $userId)
            ->where('status', CompanionThread::STATUS_ACTIVE)
            ->where('plan_id', $planId)
            ->where('task_id', $taskId)
            ->latest('last_message_at')
            ->latest('id')
            ->limit(20)
            ->get();

        $exact = $threads->first(
            fn (CompanionThread $thread) => data_get($thread->context_scope, 'entry_key') === $entryKey,
        );

        if ($exact || ! $allowLegacyScopeReuse) {
            return $exact;
        }

        return $threads->first(function (CompanionThread $thread) use ($scope) {
            $existingKey = data_get($thread->context_scope, 'entry_key');

            return blank($existingKey)
                && data_get($thread->context_scope, 'scope', 'global') === $scope;
        });
    }

    private function requirePlan(mixed $id): Plan
    {
        $planId = (int) $id;
        if ($planId <= 0) {
            throw ValidationException::withMessages(['plan_id' => 'Planを確認してください。']);
        }

        return Plan::query()->findOrFail($planId);
    }

    private function requireTask(mixed $id): Task
    {
        $taskId = (int) $id;
        if ($taskId <= 0) {
            throw ValidationException::withMessages(['task_id' => 'Taskを確認してください。']);
        }

        return Task::query()->findOrFail($taskId);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
