<?php

namespace App\Services;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Support\Str;

class CompanionContextService
{
    public function __construct(
        private readonly GoalContextService $goalContexts,
        private readonly FutureMemoService $memories,
    ) {}

    /**
     * Compact context only. Companion is not a full database dump.
     *
     * @param array<string,mixed>|null $entryContext
     * @return array<string,mixed>
     */
    public function snapshot(
        User $user,
        ?Plan $plan = null,
        ?Task $task = null,
        ?array $entryContext = null,
    ): array {
        if ($task && (! $plan || (int) $task->plan_id !== (int) $plan->id)) {
            $task = null;
        }

        $snapshot = [
            'scope' => $task ? 'task' : ($plan ? 'plan' : 'global'),
            'entry' => $this->entrySnapshot($user, $plan, $task, $entryContext),
            'plan' => null,
            'task' => null,
            'goal_context' => null,
            'recent_evidence' => [],
            'memory' => $this->memories->forUser($user, true, 8)
                ->map(fn ($memo) => [
                    'kind' => $memo->kind,
                    'category' => $memo->category,
                    'content' => Str::limit(trim((string) $memo->content), 500, '…'),
                    'source' => $memo->source,
                    'captured_at' => $memo->captured_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'inbox' => [
                'pending_count' => InboxItem::query()
                    ->where('user_id', $user->id)
                    ->whereIn('status', ['new', 'review'])
                    ->count(),
            ],
        ];

        if (! $plan) {
            $snapshot['plans'] = Plan::query()
                ->where('user_id', $user->id)
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'title', 'category', 'deadline'])
                ->map(fn (Plan $item) => [
                    'id' => (int) $item->id,
                    'title' => $item->title,
                    'category' => $item->category,
                    'deadline' => $item->deadline?->toDateString(),
                ])
                ->values()
                ->all();

            return $snapshot;
        }

        $plan->loadMissing(['goalContext.facts']);
        $snapshot['plan'] = [
            'id' => (int) $plan->id,
            'title' => $plan->title,
            'category' => $plan->category,
            'description' => $plan->description,
            'start_date' => $plan->start_date?->toDateString(),
            'deadline' => $plan->deadline?->toDateString(),
        ];

        if ($task) {
            $snapshot['task'] = [
                'id' => (int) $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'progress_percent' => (int) $task->progress_percent,
                'next_action_note' => $task->next_action_note,
            ];
        }

        if ($plan->goalContext) {
            $goal = $this->goalContexts->snapshot($plan->goalContext);
            $snapshot['goal_context'] = [
                'desired_state' => $goal['desired_state'],
                'current_state_summary' => $goal['current_state_summary'],
                'readiness' => $goal['readiness'],
                'confirmed_facts' => collect($goal['confirmed_facts'])
                    ->take(10)
                    ->map(fn ($fact) => [
                        'type' => $fact->type,
                        'key' => $fact->key,
                        'label' => $fact->label,
                        'value' => $fact->value_json,
                        'confidence' => (float) $fact->confidence,
                    ])
                    ->values()
                    ->all(),
                'known_unknowns' => collect($goal['known_unknowns'])
                    ->take(8)
                    ->map(fn ($fact) => [
                        'key' => $fact->key,
                        'label' => $fact->label,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $evidenceQuery = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->latest('occurred_at')
            ->latest('id');

        if ($task) {
            $evidenceQuery->where('task_id', $task->id);
        }

        $snapshot['recent_evidence'] = $evidenceQuery
            ->limit(6)
            ->get()
            ->map(fn (TaskEvidence $evidence) => [
                'task_id' => (int) $evidence->task_id,
                'type' => $evidence->type,
                'label' => $evidence->typeLabel(),
                'summary' => $evidence->summary(),
                'confidence' => (float) $evidence->confidence,
                'occurred_at' => $evidence->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        if (
            data_get($snapshot, 'entry.type') === 'guided_execution'
            && is_array($snapshot['entry'])
        ) {
            $snapshot['entry']['latest_evidence'] = $snapshot['recent_evidence'][0] ?? null;
        }

        return $snapshot;
    }

    public function prompt(array $snapshot): string
    {
        return json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) ?: '{}';
    }

    /**
     * @param array<string,mixed>|null $entryContext
     * @return array<string,mixed>|null
     */
    private function entrySnapshot(
        User $user,
        ?Plan $plan,
        ?Task $task,
        ?array $entryContext,
    ): ?array {
        $entryType = trim((string) data_get($entryContext, 'entry_type', ''));
        if ($entryType === '') {
            return null;
        }

        $entry = [
            'type' => $entryType,
            'source_path' => data_get($entryContext, 'source_path'),
            'source_route' => data_get($entryContext, 'source_route'),
            'label' => match ($entryType) {
                'guided_execution' => $task ? '実行・振り返り · '.$task->title : '実行・振り返り',
                'task' => $task ? 'Task · '.$task->title : 'Task',
                'plan' => $plan ? 'Plan · '.$plan->title : 'Plan',
                'inbox_item' => 'Inbox item',
                default => 'Canovia全体',
            },
        ];

        if ($entryType !== 'inbox_item') {
            return $entry;
        }

        $inboxItemId = (int) data_get($entryContext, 'inbox_item_id', 0);
        if ($inboxItemId <= 0) {
            return $entry;
        }

        $item = InboxItem::query()
            ->whereKey($inboxItemId)
            ->where('user_id', $user->id)
            ->first();

        if (! $item) {
            return $entry;
        }

        $entry['label'] = 'Inbox · '.$item->displayTitle();
        $entry['inbox_item'] = [
            'id' => (int) $item->id,
            'plan_id' => $item->plan_id ? (int) $item->plan_id : null,
            'source_type' => $item->source_type,
            'status' => $item->status,
            'title' => $item->displayTitle(),
            'content' => $item->content ? Str::limit($item->content, 4000, '…') : null,
            'source_url' => $item->source_url,
            'created_at' => $item->created_at?->toIso8601String(),
        ];

        return $entry;
    }
}
