<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActivityLog;
use Illuminate\Http\Request;

final class TimelinePageDataService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly AchievementProjectionService $achievements,
    ) {}

    public function build(Request $request): array
    {
        $plans = $this->core->plans($request, [
            'tasks',
            'work_logs',
            'activity_logs',
        ]);

        $plans->each(fn (Plan $plan) => $plan->loadMissing('adjustments'));

        $achievementConstellation = $plans
            ->filter(fn (Plan $plan) => $this->achievements->isCompleted($plan))
            ->sortByDesc(fn (Plan $plan) => sprintf(
                '%010d-%010d',
                $this->achievements->completedAt($plan)?->timestamp ?? 0,
                (int) $plan->id,
            ))
            ->values()
            ->map(fn (Plan $plan, int $index) => $this->achievements->project($plan, $index));

        $workEvents = $plans->flatMap(function (Plan $plan) {
            return $plan->workLogs->map(function ($log) use ($plan) {
                $occurredAt = $log->created_at
                    ?? $log->worked_on?->copy()->endOfDay();

                return [
                    'kind' => 'work',
                    'occurred_at' => $occurredAt,
                    'plan' => $plan,
                    'plan_id' => (int) $plan->id,
                    'title' => $log->task?->title ?? $log->task_title_snapshot ?? '計画全体',
                    'summary' => $log->memo,
                    'actor_name' => null,
                    'target_type' => 'work_log',
                    'target_id' => (int) $log->id,
                    'actual_minutes' => (int) $log->actual_minutes,
                    'url' => route('plans.dashboard', $plan),
                    'sort_key' => sprintf(
                        '%010d-work-%010d',
                        $occurredAt?->timestamp ?? 0,
                        (int) $log->id,
                    ),
                ];
            });
        });

        $collaborationEvents = $plans
            ->filter(fn (Plan $plan) => (bool) $plan->is_collaborative)
            ->flatMap(function (Plan $plan) {
                return $plan->activityLogs->map(function (PlanActivityLog $activity) use ($plan) {
                    $actorName = $activity->user?->name ?: '共同メンバー';

                    return [
                        'kind' => 'collaboration',
                        'occurred_at' => $activity->created_at,
                        'plan' => $plan,
                        'plan_id' => (int) $plan->id,
                        'title' => $actorName.'さんが'.$this->activityLabel($activity->action),
                        'summary' => $this->activitySubject($activity),
                        'actor_name' => $actorName,
                        'target_type' => $activity->target_type,
                        'target_id' => $activity->target_id,
                        'actual_minutes' => null,
                        'url' => route('plans.dashboard', $plan),
                        'sort_key' => sprintf(
                            '%010d-collaboration-%010d',
                            $activity->created_at?->timestamp ?? 0,
                            (int) $activity->id,
                        ),
                    ];
                });
            });

        $completionEvents = $achievementConstellation->map(function (array $achievement) {
            /** @var Plan $plan */
            $plan = $achievement['plan'];
            $completedAt = $achievement['completed_at'];

            return [
                'kind' => 'plan_completed',
                'occurred_at' => $completedAt,
                'plan' => $plan,
                'plan_id' => (int) $plan->id,
                'title' => $plan->title.'を達成しました',
                'summary' => $achievement['completed_tasks'].'件のTask · '
                    .$achievement['actual_minutes'].'分の積み上げ',
                'actor_name' => null,
                'target_type' => 'plan',
                'target_id' => (int) $plan->id,
                'actual_minutes' => null,
                'url' => route('achievements.show', $plan),
                'sort_key' => sprintf(
                    '%010d-complete-%010d',
                    $completedAt?->timestamp ?? 0,
                    (int) $plan->id,
                ),
            ];
        });

        $events = $workEvents
            ->concat($collaborationEvents)
            ->concat($completionEvents)
            ->sortByDesc('sort_key')
            ->take(80)
            ->values();

        $items = $events->groupBy(
            fn (array $event) => $event['occurred_at']?->format('Y-m-d') ?? '日付不明',
        );

        $categories = $plans->pluck('category')->filter()->unique()->values();
        $similarPlans = collect();

        if ($categories->isNotEmpty()) {
            $similarPlans = Plan::query()
                ->with('user')
                ->where('is_public', true)
                ->whereNotNull('public_slug')
                ->whereNotIn('id', $plans->pluck('id'))
                ->whereIn('category', $categories)
                ->latest('updated_at')
                ->take(3)
                ->get();
        }

        return compact(
            'plans',
            'items',
            'events',
            'achievementConstellation',
            'similarPlans',
        );
    }

    private function activityLabel(string $action): string
    {
        return match ($action) {
            'task_created' => 'タスクを追加しました',
            'task_updated' => 'タスクを更新しました',
            'task_deleted' => 'タスクを削除しました',
            'plan_updated', 'plan_ai_updated' => '計画を更新しました',
            'artifact_created' => '成果物を追加しました',
            'artifact_updated' => '成果物を更新しました',
            'resource_created' => '資料を追加しました',
            'resource_updated', 'resource_ai_assigned' => '資料を更新しました',
            'member_role_changed' => '共同計画の権限を更新しました',
            'invite_regenerated' => '招待情報を更新しました',
            default => '共同計画を更新しました',
        };
    }

    private function activitySubject(PlanActivityLog $activity): ?string
    {
        $metadata = is_array($activity->metadata) ? $activity->metadata : [];

        return $metadata['task_title']
            ?? $metadata['artifact_title']
            ?? $metadata['resource_title']
            ?? null;
    }
}
