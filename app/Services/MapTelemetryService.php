<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Enums\MapSurfaceRole;
use App\Models\BehaviorEvent;
use Illuminate\Support\Collection;

final class MapTelemetryService
{
    /**
     * @return array<string,mixed>
     */
    public function summary(int $days): array
    {
        $days = max(1, min(90, $days));
        $since = now()->subDays($days);

        $types = [
            BehaviorEventType::MapViewed,
            BehaviorEventType::MapNodeFocused,
            BehaviorEventType::MapBackUsed,
            BehaviorEventType::MapClassicActionOpened,
            BehaviorEventType::MapCompanionOpened,
            BehaviorEventType::MapClassicHomeOpened,
            BehaviorEventType::MapReprojected,
            BehaviorEventType::MapExecutionStarted,
        ];

        $events = BehaviorEvent::query()
            ->whereIn('event_type', array_map(fn ($type) => $type->value, $types))
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get(['event_type', 'occurred_at', 'metadata']);

        $flows = $events
            ->filter(fn (BehaviorEvent $event) => filled(data_get($event->metadata, 'flow_id')))
            ->groupBy(fn (BehaviorEvent $event) => (string) data_get($event->metadata, 'flow_id'));

        $viewFlows = $this->flowIdsWith($flows, BehaviorEventType::MapViewed);
        $focusFlows = $this->flowIdsWith($flows, BehaviorEventType::MapNodeFocused);
        $actionFlows = $this->flowIdsWith($flows, BehaviorEventType::MapClassicActionOpened);
        $companionFlows = $this->flowIdsWith($flows, BehaviorEventType::MapCompanionOpened);
        $fallbackFlows = $this->flowIdsWith($flows, BehaviorEventType::MapClassicHomeOpened);
        $executionFlows = $this->flowIdsWith($flows, BehaviorEventType::MapExecutionStarted);
        $reprojectedFlows = $this->flowIdsWith($flows, BehaviorEventType::MapReprojected);

        $primaryFocusMs = $events
            ->where('event_type', BehaviorEventType::MapNodeFocused)
            ->filter(fn (BehaviorEvent $event) => (bool) data_get($event->metadata, 'is_primary', false))
            ->pluck('metadata')
            ->map(fn ($metadata) => (int) data_get($metadata, 'elapsed_ms', 0))
            ->filter(fn ($value) => $value >= 0);

        $executionEvents = $events->where('event_type', BehaviorEventType::MapExecutionStarted);
        $executionMs = $executionEvents
            ->pluck('metadata')
            ->map(fn ($metadata) => (int) data_get($metadata, 'elapsed_ms', 0))
            ->filter(fn ($value) => $value >= 0);
        $executionSteps = $executionEvents
            ->pluck('metadata')
            ->map(fn ($metadata) => (int) data_get($metadata, 'step_count', 0))
            ->filter(fn ($value) => $value >= 0);

        $homeStarts = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::WorkStarted->value)
            ->where('occurred_at', '>=', $since)
            ->get(['metadata'])
            ->filter(fn (BehaviorEvent $event) => data_get($event->metadata, 'source') === 'dashboard');

        $homeLatencySeconds = $homeStarts
            ->pluck('metadata')
            ->map(fn ($metadata) => data_get($metadata, 'start_latency_seconds'))
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value);

        $views = $viewFlows->count();
        $focused = $focusFlows->count();

        return [
            'days' => $days,
            'views' => $views,
            'focus_rate' => $this->rate($focused, $views),
            'classic_action_rate' => $this->rate($actionFlows->count(), $focused),
            'companion_rate' => $this->rate($companionFlows->count(), $focused),
            'home_fallback_rate' => $this->rate($fallbackFlows->count(), $views),
            'execution_rate' => $this->rate($executionFlows->count(), $views),
            'reproject_rate' => $this->rate($reprojectedFlows->count(), $views),
            'back_per_flow' => $views > 0
                ? round($events->where('event_type', BehaviorEventType::MapBackUsed)->count() / $views, 2)
                : 0.0,
            'median_primary_focus_ms' => $this->median($primaryFocusMs),
            'median_execution_ms' => $this->median($executionMs),
            'median_execution_steps' => $this->median($executionSteps),
            'execution_count' => $executionFlows->count(),
            'companion_count' => $companionFlows->count(),
            'home_start_count' => $homeStarts->count(),
            'home_median_start_latency_ms' => ($median = $this->median($homeLatencySeconds)) !== null
                ? $median * 1000
                : null,
            'surface_roles' => $this->surfaceRoleSummary($flows),
        ];
    }

    /**
     * @param Collection<string,Collection<int,BehaviorEvent>> $flows
     * @return array<string,array{views:int,focus_rate:float,classic_action_rate:float,back_per_flow:float}>
     */
    private function surfaceRoleSummary(Collection $flows): array
    {
        $roles = [...MapSurfaceRole::telemetryValues(), 'unknown'];

        return collect($roles)
            ->mapWithKeys(function (string $role) use ($flows) {
                $roleFlows = $flows->filter(function (Collection $events) use ($role) {
                    return $events->contains(function (BehaviorEvent $event) use ($role) {
                        if ($event->event_type !== BehaviorEventType::MapViewed) {
                            return false;
                        }

                        $eventRole = (string) data_get($event->metadata, 'surface_role', 'unknown');
                        $eventRole = $eventRole !== '' ? $eventRole : 'unknown';

                        return $eventRole === $role;
                    });
                });

                $views = $roleFlows->count();
                $focused = $this->flowIdsWith($roleFlows, BehaviorEventType::MapNodeFocused)->count();
                $actions = $this->flowIdsWith($roleFlows, BehaviorEventType::MapClassicActionOpened)->count();
                $backCount = $roleFlows->sum(
                    fn (Collection $events) => $events
                        ->where('event_type', BehaviorEventType::MapBackUsed)
                        ->count(),
                );

                return [$role => [
                    'views' => $views,
                    'focus_rate' => $this->rate($focused, $views),
                    'classic_action_rate' => $this->rate($actions, $focused),
                    'back_per_flow' => $views > 0 ? round($backCount / $views, 2) : 0.0,
                ]];
            })
            ->all();
    }

    /**
     * @param Collection<string,Collection<int,BehaviorEvent>> $flows
     * @return Collection<int,string>
     */
    private function flowIdsWith(Collection $flows, BehaviorEventType $type): Collection
    {
        return $flows
            ->filter(fn (Collection $events) => $events->contains(
                fn (BehaviorEvent $event) => $event->event_type === $type,
            ))
            ->keys()
            ->values();
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }

    private function median(Collection $values): ?int
    {
        $sorted = $values
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values();

        $count = $sorted->count();
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (int) $sorted[$middle];
        }

        return (int) round(((int) $sorted[$middle - 1] + (int) $sorted[$middle]) / 2);
    }
}
