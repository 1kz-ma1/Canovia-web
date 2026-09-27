<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\WorkSession;
use Illuminate\Support\Collection;

final class RequestBehaviorHistory
{
    /** @var array<string,array{days:int,items:Collection}> */
    private array $events = [];

    /** @var array<string,array{days:int,items:Collection}> */
    private array $completedSessions = [];

    public function events(string $actorToken, int $days): Collection
    {
        $days = max(1, $days);
        $loadedDays = max(
            $days,
            60,
            (int) config('recommendations.baseline_days', 28),
            (int) config('recommendations.state_window_days', 14),
            (int) config('recommendations.personalization_days', 90),
        );

        if (! isset($this->events[$actorToken]) || $this->events[$actorToken]['days'] < $loadedDays) {
            $this->events[$actorToken] = [
                'days' => $loadedDays,
                'items' => BehaviorEvent::query()
                    ->select(['id', 'event_type', 'plan_id', 'task_id', 'occurred_at', 'metadata'])
                    ->where('actor_token', $actorToken)
                    ->whereIn('event_type', [
                        BehaviorEventType::WorkStarted->value,
                        BehaviorEventType::NavigationCompleted->value,
                        BehaviorEventType::PlanTabViewed->value,
                        BehaviorEventType::TaskViewed->value,
                        BehaviorEventType::AlternativeRequested->value,
                        BehaviorEventType::RecommendationRejected->value,
                        BehaviorEventType::DashboardIdle->value,
                        BehaviorEventType::NavigationStarted->value,
                        BehaviorEventType::RecommendationShown->value,
                        BehaviorEventType::RecommendationAccepted->value,
                        BehaviorEventType::WorkCompleted->value,
                    ])
                    ->where('occurred_at', '>=', now()->subDays($loadedDays))
                    ->orderBy('occurred_at')
                    ->get()
                    ->toBase(),
            ];
        }

        $since = now()->subDays($days);

        return $this->events[$actorToken]['items']
            ->filter(fn ($event) => $event->occurred_at?->gte($since))
            ->values();
    }

    public function completedSessions(string $actorToken, int $days): Collection
    {
        $days = max(1, $days);
        $loadedDays = max(
            $days,
            7,
            (int) config('recommendations.baseline_days', 28),
            (int) config('recommendations.state_window_days', 14),
        );

        if (! isset($this->completedSessions[$actorToken])
            || $this->completedSessions[$actorToken]['days'] < $loadedDays) {
            $this->completedSessions[$actorToken] = [
                'days' => $loadedDays,
                'items' => WorkSession::query()
                    ->select([
                        'id',
                        'plan_id',
                        'task_id',
                        'status',
                        'started_at',
                        'ended_at',
                        'actual_seconds',
                        'paused_seconds',
                    ])
                    ->where('actor_token', $actorToken)
                    ->where('started_at', '>=', now()->subDays($loadedDays))
                    ->whereIn('status', ['completed', 'interrupted'])
                    ->orderBy('started_at')
                    ->get()
                    ->toBase(),
            ];
        }

        $since = now()->subDays($days);

        return $this->completedSessions[$actorToken]['items']
            ->filter(fn ($session) => $session->started_at?->gte($since))
            ->values();
    }
}
