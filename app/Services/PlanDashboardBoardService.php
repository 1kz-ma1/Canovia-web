<?php

namespace App\Services;

use App\Models\Plan;
final class PlanDashboardBoardService
{
    /**
     * Build a read-only, deterministic information-board projection.
     *
     * This intentionally does not call AI. The board summarizes canonical
     * Plan/Task/WorkLog state so the user can scan the whole Plan before
     * deciding where to zoom or what to execute.
     *
     * @param array<string,mixed> $progress
     * @param array<string,mixed> $roadmap
     * @param array<string,mixed> $roadmapSpatial
     * @return array<string,mixed>
     */
    public function build(
        Plan $plan,
        array $progress,
        array $roadmap,
        array $roadmapSpatial,
    ): array {
        $nodes = collect($roadmapSpatial['nodes'] ?? []);
        $roadmapNodes = collect($roadmap['nodes'] ?? []);

        $current = $nodes->firstWhere('visual_state', 'current')
            ?? $nodes->first(fn (array $node) => ($node['status'] ?? null) === 'doing')
            ?? $nodes->firstWhere('visual_state', 'ready')
            ?? $roadmapNodes->firstWhere('is_current', true);

        $ready = $nodes
            ->filter(fn (array $node) => ($node['visual_state'] ?? null) === 'ready')
            ->reject(fn (array $node) => (int) ($node['task_id'] ?? 0) === (int) ($current['task_id'] ?? 0))
            ->sortBy(fn (array $node) => sprintf(
                '%02d-%02d-%08d',
                max(1, (int) ($node['priority'] ?? 3)),
                max(1, (int) ($node['activation_cost'] ?? 3)),
                (int) ($node['sort_order'] ?? PHP_INT_MAX),
            ))
            ->values();

        $blocked = $nodes
            ->filter(fn (array $node) => ($node['visual_state'] ?? null) === 'blocked')
            ->sortBy(fn (array $node) => sprintf(
                '%02d-%08d',
                max(1, (int) ($node['priority'] ?? 3)),
                (int) ($node['sort_order'] ?? PHP_INT_MAX),
            ))
            ->values();

        $futureCount = $nodes
            ->where('visual_state', 'future')
            ->count();

        $activity = $plan->workLogs
            ->take(4)
            ->map(fn ($log) => [
                'id' => (int) $log->id,
                'task_id' => $log->task_id ? (int) $log->task_id : null,
                'task_title' => $log->task?->title
                    ?? $log->task_title_snapshot
                    ?? 'Plan作業',
                'worked_on' => $log->worked_on?->format('Y-m-d'),
                'actual_minutes' => (int) ($log->actual_minutes ?? 0),
                'progress_delta_percent' => (int) ($log->progress_delta_percent ?? 0),
                'memo' => filled($log->memo) ? (string) $log->memo : null,
            ])
            ->values()
            ->all();

        $status = (string) ($progress['status'] ?? '予定通り');
        $blockedCount = $blocked->count();
        $readyCount = $nodes->where('visual_state', 'ready')->count();

        return [
            'schema_version' => 1,
            'overview' => [
                'description' => filled($plan->description) ? (string) $plan->description : null,
                'category' => filled($plan->category) ? (string) $plan->category : '未分類',
                'task_count' => $roadmapNodes->count(),
                'completed_count' => (int) ($roadmap['completed_count'] ?? 0),
                'active_count' => (int) ($roadmap['active_count'] ?? 0),
            ],
            'progress' => [
                'status' => $status,
                'remaining_days' => $progress['remaining_days'] ?? null,
                'daily_required_minutes' => $progress['daily_required_minutes'] ?? null,
                'weighted_progress_percent' => $progress['weighted_progress_percent'] ?? null,
            ],
            'next' => $this->taskCard($current),
            'ready' => $ready->take(3)->map(fn (array $node) => $this->taskCard($node))->filter()->values()->all(),
            'blocked' => $blocked->take(3)->map(fn (array $node) => $this->taskCard($node))->filter()->values()->all(),
            'activity' => $activity,
            'signals' => [
                'ready_count' => $readyCount,
                'blocked_count' => $blockedCount,
                'future_count' => $futureCount,
                'parallel_cluster_count' => (int) ($roadmapSpatial['parallel_cluster_count'] ?? 0),
                'phase_count' => count($roadmapSpatial['phases'] ?? []),
                'attention' => $this->attention($status, $blockedCount, $readyCount, $current),
            ],
        ];
    }

    /**
     * @param array<string,mixed>|null $node
     * @return array<string,mixed>|null
     */
    private function taskCard(?array $node): ?array
    {
        $taskId = (int) ($node['task_id'] ?? 0);
        if ($taskId <= 0) {
            return null;
        }

        return [
            'task_id' => $taskId,
            'title' => (string) ($node['title'] ?? 'Task'),
            'status' => (string) ($node['status'] ?? 'todo'),
            'status_label' => (string) ($node['status_label'] ?? 'Task'),
            'progress_percent' => (int) ($node['progress_percent'] ?? 0),
            'remaining_minutes' => (int) ($node['remaining_minutes'] ?? 0),
            'priority' => (int) ($node['priority'] ?? 3),
            'next_action_note' => filled($node['next_action_note'] ?? null)
                ? (string) $node['next_action_note']
                : null,
            'blocker_count' => collect($node['blocker_task_ids'] ?? [])->filter()->unique()->count(),
            'template_id' => 'roadmap-task:'.$taskId,
        ];
    }

    /**
     * @param array<string,mixed>|null $current
     */
    private function attention(
        string $status,
        int $blockedCount,
        int $readyCount,
        ?array $current,
    ): array {
        if ($blockedCount > 0) {
            return [
                'level' => 'alert',
                'label' => '前提待ちあり',
                'message' => '前提待ちのTaskが'.$blockedCount.'件あります。Roadmapで依存関係を確認できます。',
            ];
        }

        if (in_array($status, ['期限切れ', '作業時間不足', '遅れ気味'], true)) {
            return [
                'level' => 'alert',
                'label' => $status,
                'message' => 'Plan全体の進み方に注意が必要です。Next Actionと必要時間を確認してください。',
            ];
        }

        if (is_array($current)) {
            return [
                'level' => 'primary',
                'label' => '進行中',
                'message' => '現在の中心Taskは「'.(string) ($current['title'] ?? 'Task').'」です。',
            ];
        }

        if ($readyCount > 0) {
            return [
                'level' => 'primary',
                'label' => '開始可能',
                'message' => '今すぐ開始できるTaskが'.$readyCount.'件あります。',
            ];
        }

        return [
            'level' => 'quiet',
            'label' => '確認',
            'message' => 'Roadmapで次に進めるTaskを確認してください。',
        ];
    }
}
