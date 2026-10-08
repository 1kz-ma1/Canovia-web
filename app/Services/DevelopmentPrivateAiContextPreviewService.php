<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

/**
 * Whitelisted, private development context projection. Caller must authorize
 * the logged-in Plan owner and reject collaboration before invoking.
 *
 * This service issues no OAuth tokens or grants and performs no writes.
 */
final class DevelopmentPrivateAiContextPreviewService
{
    /**
     * @return array<string, mixed>
     */
    public function preview(Plan $plan, string $scope = 'overview', int $limit = 5): array
    {
        if (! in_array($scope, ['overview', 'tasks'], true)) {
            throw new \InvalidArgumentException('Invalid private context scope.');
        }

        $limit = max(1, min(8, $limit));
        $items = [];
        $truncated = false;

        if ($scope === 'tasks') {
            // No Eloquent model serialization. Select ONLY approved columns.
            $tasks = Task::query()
                ->where('plan_id', $plan->id)
                ->whereIn('status', ['doing', 'todo', 'paused'])
                ->orderByRaw("CASE status WHEN 'doing' THEN 0 WHEN 'todo' THEN 1 ELSE 2 END")
                ->orderBy('priority')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit($limit + 1)
                ->get(['title', 'status', 'progress_percent']);

            $truncated = $tasks->count() > $limit;
            $items = $tasks->take($limit)->map(static fn (Task $task) => [
                'title' => mb_substr((string) $task->title, 0, 160),
                'status' => (string) $task->status,
                'recorded_progress_percent' => $task->progress_percent !== null
                    ? max(0, min(100, (int) $task->progress_percent))
                    : null,
            ])->values()->all();
        }

        return [
            'schema' => 'canovia.development_private_context_preview.v1',
            'delivery' => 'owner_preview_only',
            'scope' => $scope,
            'as_of' => now()->toIso8601String(),
            'plan' => [
                'title' => mb_substr((string) $plan->title, 0, 160),
            ],
            'tasks' => $items,
            'truncated' => $truncated,
            'completion' => 'unverified',
            'caveat' => 'Only recorded Canovia data. Task status/percentage does not prove PR, CI, deployment or device verification. No external AI access was granted.',
        ];
    }
}
