<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\Task;
use Illuminate\Support\Collection;

class RecommendationPersonalizationService
{
    private array $profiles = [];

    public function __construct(private readonly RequestBehaviorHistory $history) {}

    public function profile(?string $actorToken): array
    {
        if (! $actorToken) {
            return $this->emptyProfile();
        }

        if (isset($this->profiles[$actorToken])) {
            return $this->profiles[$actorToken];
        }

        $events = $this->history
            ->events($actorToken, (int) config('recommendations.personalization_days', 90))
            ->whereIn('event_type', [
                BehaviorEventType::RecommendationAccepted,
                BehaviorEventType::RecommendationRejected,
                BehaviorEventType::WorkStarted,
                BehaviorEventType::WorkCompleted,
            ])
            ->values();

        $taskScores = [];
        $planScores = [];
        $acceptedBudgetBuckets = ['short' => 0, 'medium' => 0, 'long' => 0];
        $completedDurations = collect();

        foreach ($events as $event) {
            $taskId = $event->task_id ? (int) $event->task_id : null;
            $planId = $event->plan_id ? (int) $event->plan_id : null;

            if ($event->event_type === BehaviorEventType::RecommendationAccepted) {
                if ($taskId) {
                    $taskScores[$taskId] = ($taskScores[$taskId] ?? 0) + 4.0;
                }
                if ($planId) {
                    $planScores[$planId] = ($planScores[$planId] ?? 0) + 1.5;
                }
                $minutes = data_get($event->metadata, 'recommended_minutes');
                if (is_numeric($minutes) && (int) $minutes > 0) {
                    $acceptedBudgetBuckets[$this->durationBucket((int) $minutes)]++;
                }
            } elseif ($event->event_type === BehaviorEventType::RecommendationRejected) {
                if ($taskId) {
                    $taskScores[$taskId] = ($taskScores[$taskId] ?? 0) - 3.5;
                }
                if ($planId) {
                    $planScores[$planId] = ($planScores[$planId] ?? 0) - 1.0;
                }
            } elseif ($event->event_type === BehaviorEventType::WorkCompleted) {
                $actual = data_get($event->metadata, 'actual_minutes');
                if (is_numeric($actual) && (int) $actual >= 5 && (int) $actual <= 240) {
                    $completedDurations->push((int) $actual);
                }
            }
        }

        $preferredSessionMinutes = $completedDurations->count() >= 3
            ? $this->median($completedDurations, 25)
            : null;

        $latencies = $events
            ->where('event_type', BehaviorEventType::WorkStarted)
            ->filter(fn ($event) => $event->task_id
                && in_array(data_get($event->metadata, 'source'), ['dashboard', 'navigation'], true)
                && is_numeric(data_get($event->metadata, 'start_latency_seconds')))
            ->groupBy('task_id')
            ->map(fn ($group) => $group
                ->map(fn ($event) => (int) data_get($event->metadata, 'start_latency_seconds'))
                ->filter(fn ($seconds) => $seconds >= 0)
                ->values());

        $allLatencies = $latencies->flatten();
        $globalMedian = $this->median($allLatencies, 180);
        $activationAdjustments = [];

        foreach ($latencies as $taskId => $values) {
            if ($values->count() < 2 || $globalMedian <= 0) {
                continue;
            }

            $ratio = $this->median($values, $globalMedian) / $globalMedian;
            $activationAdjustments[(int) $taskId] = match (true) {
                $ratio <= 0.65 => -1,
                $ratio >= 1.6 => 1,
                default => 0,
            };
        }

        return $this->profiles[$actorToken] = compact('taskScores', 'planScores', 'activationAdjustments', 'acceptedBudgetBuckets', 'preferredSessionMinutes');
    }

    public function effectiveActivationCost(Task $task, array $profile): int
    {
        $base = max(1, min(5, (int) ($task->activation_cost ?? 3)));
        $adjustment = (int) ($profile['activationAdjustments'][$task->id] ?? 0);

        return max(1, min(5, $base + $adjustment));
    }

    public function scoreAdjustment(Task $task, ?int $timeBudgetMinutes, array $profile): float
    {
        $score = (float) ($profile['taskScores'][$task->id] ?? 0)
            + (float) ($profile['planScores'][$task->plan_id] ?? 0);

        if ($timeBudgetMinutes !== null && $timeBudgetMinutes > 0) {
            $budgetBucket = $this->durationBucket($timeBudgetMinutes);
            $taskBucket = $this->durationBucket((int) ($task->remaining_minutes ?: $task->estimated_minutes));
            $bucketCount = (int) ($profile['acceptedBudgetBuckets'][$budgetBucket] ?? 0);

            if ($budgetBucket === $taskBucket && $bucketCount > 0) {
                $score += min(6, 1.5 * $bucketCount);
            }
        }

        return max(-14, min(14, $score));
    }

    public function recommendedMinutes(int $baseMinutes, array $profile): int
    {
        $preferred = $profile['preferredSessionMinutes'] ?? null;
        if (! is_numeric($preferred)) {
            return $baseMinutes;
        }

        // 急に個人履歴へ寄せすぎず、現在状態からの提案と半分ずつ混ぜる。
        return max(5, min(120, (int) round(($baseMinutes + (int) $preferred) / 2)));
    }

    public function hasMeaningfulPreference(Task $task, array $profile): bool
    {
        return abs((float) ($profile['taskScores'][$task->id] ?? 0)) >= 4
            || abs((float) ($profile['planScores'][$task->plan_id] ?? 0)) >= 3;
    }

    private function emptyProfile(): array
    {
        return [
            'taskScores' => [],
            'planScores' => [],
            'activationAdjustments' => [],
            'acceptedBudgetBuckets' => ['short' => 0, 'medium' => 0, 'long' => 0],
            'preferredSessionMinutes' => null,
        ];
    }

    private function durationBucket(int $minutes): string
    {
        return match (true) {
            $minutes <= 20 => 'short',
            $minutes <= 45 => 'medium',
            default => 'long',
        };
    }

    private function median(Collection $values, int $fallback): int
    {
        $values = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value)->sort()->values();
        $count = $values->count();

        if ($count === 0) {
            return $fallback;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
