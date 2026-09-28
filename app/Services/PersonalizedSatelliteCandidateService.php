<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Enums\MapLevel;
use App\Models\Plan;
use App\Models\Task;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PersonalizedSatelliteCandidateService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly RequestBehaviorHistory $history,
        private readonly PlanPriorityService $priorities,
        private readonly MapHierarchyContextService $hierarchy,
    ) {}

    /**
     * Enumerate existing Canovia contexts that may be promoted as L0 satellites.
     *
     * This service does not rank, cap, position, size, glow, or otherwise decide
     * visual attention. It only exposes existing contexts plus deterministic
     * behavioral signals used by the promotion layer.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidates(Request $request): Collection
    {
        $plans = $this->core->plans($request, ['tasks', 'work_logs', 'availability'])
            ->filter(fn (Plan $plan) => $this->hasActiveTask($plan))
            ->values();

        if ($plans->isEmpty()) {
            return collect();
        }

        $actorToken = $this->core->actorToken($request);
        $events = $this->history->events($actorToken, 30);
        $sessions = $this->history->completedSessions($actorToken, 30);
        $candidates = collect();

        foreach ($plans as $plan) {
            // L0 already has a stable Collaboration intent. A Shared Plan here
            // duplicates that entry point instead of shortening a unique route.
            if ((bool) $plan->is_collaborative) {
                continue;
            }
            $planEvents = $events
                ->filter(fn ($event) => (int) ($event->plan_id ?? 0) === (int) $plan->id)
                ->values();
            $planSessions = $sessions
                ->filter(fn ($session) => (int) ($session->plan_id ?? 0) === (int) $plan->id)
                ->values();

            $signals = $this->planSignals($plan, $planEvents, $planSessions);
            $domainKey = $this->hierarchy->domainKey($plan->category);
            $intent = 'execution';
            $executionUrl = route('map.index', [
                'level' => MapLevel::Execution->value,
                'intent' => $intent,
                'domain' => $domainKey,
                'plan' => $plan->id,
            ]);

            $candidates->push([
                'id' => 'satellite:plan:'.$plan->id,
                'kind' => 'plan',
                'node_type' => 'satellite_plan',
                'entity_id' => (int) $plan->id,
                'plan_id' => (int) $plan->id,
                'task_id' => null,
                'eyebrow' => 'PLAN SATELLITE',
                'label' => (string) $plan->title,
                'subtitle' => '継続中のPlanへのショートカット',
                'available_action' => $executionUrl,
                'navigation_kind' => 'satellite',
                'anchor_node_id' => 'intent:plan',
                'signals' => $signals,
                'classic_surface' => [
                    'kind' => 'Personalized Satellite',
                    'title' => (string) $plan->title,
                    'summary' => '既存PlanをL0へ昇格したショートカットです。Plan自体はSatellite判定と無関係に存在し続けます。',
                    'actions' => [
                        [
                            'label' => 'Executionへ移動',
                            'url' => $executionUrl,
                            'primary' => true,
                            'navigation_kind' => 'satellite',
                        ],
                        [
                            'label' => 'Plan詳細を開く',
                            'url' => route('plans.show', $plan),
                            'primary' => false,
                        ],
                    ],
                    'meta' => array_values(array_filter([
                        $plan->category ?: null,
                        'Personal Plan',
                    ])),
                ],
            ]);

        }

        return $candidates->values();
    }

    /**
     * @param Collection<int,mixed> $events
     * @param Collection<int,mixed> $sessions
     * @return array{importance:float,usage_frequency:float,recency:float,continuity:float}
     */
    private function planSignals(Plan $plan, Collection $events, Collection $sessions): array
    {
        $priority = (int) data_get($this->priorities->evaluate($plan), 'priority', 3);
        $importance = (6 - max(1, min(5, $priority))) / 5;
        if ((bool) $plan->is_collaborative) {
            $importance = min(1, $importance + 0.08);
        }

        $usageWeight = $events->sum(function ($event) {
            return match ($event->event_type) {
                BehaviorEventType::WorkStarted,
                BehaviorEventType::WorkCompleted => 3,
                BehaviorEventType::TaskViewed,
                BehaviorEventType::PlanTabViewed => 1,
                BehaviorEventType::NavigationCompleted => 1,
                default => 0,
            };
        }) + ($sessions->count() * 3);

        $latest = collect([
            $events->max(fn ($event) => $event->occurred_at?->timestamp ?? 0),
            $sessions->max(fn ($session) => max(
                $session->ended_at?->timestamp ?? 0,
                $session->started_at?->timestamp ?? 0,
            )),
        ])->max();

        $activeDays = $events
            ->filter(fn ($event) => in_array($event->event_type, [
                BehaviorEventType::WorkStarted,
                BehaviorEventType::WorkCompleted,
            ], true))
            ->map(fn ($event) => $event->occurred_at?->toDateString())
            ->merge($sessions->map(fn ($session) => $session->started_at?->toDateString()))
            ->filter()
            ->unique()
            ->count();

        $doing = $plan->tasks->contains(fn (Task $task) => $task->status === 'doing'
            && (int) $task->progress_percent < 100);
        $started = $plan->tasks->contains(fn (Task $task) => (int) $task->progress_percent > 0
            && ! in_array($task->status, ['done', 'cancelled'], true));

        $continuityBase = $doing ? 0.45 : ($started ? 0.20 : 0.0);

        return [
            'importance' => round(max(0, min(1, $importance)), 4),
            'usage_frequency' => round(min(1, $usageWeight / 12), 4),
            'recency' => round($this->recencyScore(
                $latest > 0 ? Carbon::createFromTimestamp($latest) : $plan->updated_at,
                $latest > 0 ? 1.0 : 0.25,
            ), 4),
            'continuity' => round(min(1, $continuityBase + min(0.55, $activeDays * 0.11)), 4),
        ];
    }

    private function hasActiveTask(Plan $plan): bool
    {
        return $plan->tasks->contains(fn (Task $task) => ! in_array($task->status, ['done', 'cancelled'], true)
            && (int) $task->progress_percent < 100);
    }

    private function selectTask(Plan $plan): ?Task
    {
        return $plan->tasks
            ->filter(fn (Task $task) => ! in_array($task->status, ['done', 'cancelled'], true)
                && (int) $task->progress_percent < 100)
            ->sort(function (Task $left, Task $right) {
                $status = ($left->status === 'doing' ? 0 : 1)
                    <=> ($right->status === 'doing' ? 0 : 1);
                if ($status !== 0) {
                    return $status;
                }

                $priority = max(1, min(5, (int) $left->priority))
                    <=> max(1, min(5, (int) $right->priority));
                if ($priority !== 0) {
                    return $priority;
                }

                $sort = (int) ($left->sort_order ?? PHP_INT_MAX)
                    <=> (int) ($right->sort_order ?? PHP_INT_MAX);

                return $sort !== 0 ? $sort : (int) $left->id <=> (int) $right->id;
            })
            ->first();
    }

    private function recencyScore(?CarbonInterface $time, float $cap = 1.0): float
    {
        if (! $time) {
            return 0.0;
        }

        $days = max(0, (int) $time->diffInDays(now()));

        $score = match (true) {
            $days <= 1 => 1.0,
            $days <= 3 => 0.85,
            $days <= 7 => 0.65,
            $days <= 14 => 0.45,
            $days <= 30 => 0.25,
            default => 0.05,
        };

        return min($cap, $score);
    }
}
