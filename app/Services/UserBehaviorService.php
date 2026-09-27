<?php

namespace App\Services;

use App\Data\UserBehaviorBaselineData;
use App\Enums\BehaviorEventType;
use Illuminate\Support\Collection;

class UserBehaviorService
{
    public function __construct(private readonly RequestBehaviorHistory $history) {}

    public function baseline(string $actorToken, ?int $days = null): UserBehaviorBaselineData
    {
        $days ??= (int) config('recommendations.baseline_days', 28);
        $minFocusSeconds = (int) config('recommendations.min_focus_session_seconds', 120);

        $events = $this->history->events($actorToken, $days);
        $sessions = $this->history->completedSessions($actorToken, $days);

        $qualifiedSessions = $sessions
            ->filter(fn ($session) => (int) $session->actual_seconds >= $minFocusSeconds);

        $startLatencies = $events
            ->where('event_type', BehaviorEventType::WorkStarted)
            ->pluck('metadata')
            ->map(fn ($metadata) => data_get($metadata, 'start_latency_seconds'))
            ->filter(fn ($value) => is_numeric($value) && $value >= 0)
            ->map(fn ($value) => (int) $value);

        $workMinutes = $qualifiedSessions
            ->pluck('actual_seconds')
            ->map(fn ($seconds) => max(1, (int) round($seconds / 60)));

        $activeHours = $events
            ->where('event_type', BehaviorEventType::WorkStarted)
            ->map(fn ($event) => (int) $event->occurred_at->format('G'));

        $selectionSteps = $events
            ->where('event_type', BehaviorEventType::NavigationCompleted)
            ->pluck('metadata')
            ->map(fn ($metadata) => data_get($metadata, 'selection_steps'))
            ->filter(fn ($value) => is_numeric($value) && $value > 0)
            ->map(fn ($value) => (int) $value);

        $workEvents = $events->where('event_type', BehaviorEventType::WorkStarted);
        $activeDays = $workEvents->map(fn ($event) => $event->occurred_at->toDateString())->unique()->count();
        $finishedSessions = $qualifiedSessions->count();
        $interruptedSessions = $qualifiedSessions->where('status', 'interrupted')->count();
        $sampleCount = max($workEvents->count(), $finishedSessions, $selectionSteps->count());

        return new UserBehaviorBaselineData(
            startLatencySeconds: (int) round($this->median($startLatencies, 180)),
            medianWorkMinutes: (int) round($this->median($workMinutes, 25)),
            usualActiveHour: (int) round($this->median($activeHours, (int) now()->format('G'))),
            averageSelectionSteps: round($selectionSteps->avg() ?? 3, 1),
            workFrequency: round($activeDays / max($days, 1), 2),
            interruptionRate: $finishedSessions > 0 ? round($interruptedSessions / $finishedSessions, 2) : 0.25,
            sampleCount: $sampleCount,
            confidence: round(min(1, $sampleCount / 20), 2),
        );
    }

    private function median(Collection $values, int|float $fallback): int|float
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();

        if ($count === 0) {
            return $fallback;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
