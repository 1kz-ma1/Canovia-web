<?php

namespace App\Services;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;

class CompanionContextService
{
    public function __construct(
        private readonly GoalContextService $goalContexts,
    ) {}

    /**
     * Compact context only. Companion is not a full database dump.
     *
     * @return array<string,mixed>
     */
    public function snapshot(User $user, ?Plan $plan = null, ?Task $task = null): array
    {
        if ($task && (! $plan || (int) $task->plan_id !== (int) $plan->id)) {
            $task = null;
        }

        $snapshot = [
            'scope' => $task ? 'task' : ($plan ? 'plan' : 'global'),
            'plan' => null,
            'task' => null,
            'goal_context' => null,
            'recent_evidence' => [],
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
                'deadline' => $task->deadline?->toDateString(),
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

        return $snapshot;
    }

    public function prompt(array $snapshot): string
    {
        return json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) ?: '{}';
    }
}
