<?php

namespace App\Services;

use App\Models\Plan;
use Carbon\Carbon;

final class AchievementProjectionService
{
    public function __construct(
        private readonly PlanProgressService $progressService,
    ) {}

    public function isCompleted(Plan $plan): bool
    {
        $progress = $this->progressService->calculate($plan);

        return ($progress['weighted_progress_percent'] ?? 0) >= 100
            || (
                $plan->tasks->where('status', 'done')->isNotEmpty()
                && $plan->tasks->every(
                    fn ($task) => in_array($task->status, ['done', 'cancelled'], true),
                )
            );
    }

    public function completedAt(Plan $plan): ?Carbon
    {
        $candidates = collect([
            $plan->tasks->max('updated_at'),
            $plan->workLogs->max('created_at'),
            $plan->adjustments->max('applied_at'),
            $plan->adjustments->max('created_at'),
        ])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value));

        return $candidates
            ->sortByDesc(fn (Carbon $date) => $date->timestamp)
            ->first()
            ?? $plan->deadline?->copy();
    }

    /**
     * @return array<string,mixed>
     */
    public function project(Plan $plan, int $index = 0): array
    {
        $completedAt = $this->completedAt($plan);
        $position = $this->constellationPosition($index);

        return [
            'plan' => $plan,
            'plan_id' => (int) $plan->id,
            'progress' => $this->progressService->calculate($plan),
            'completed_at' => $completedAt,
            'actual_minutes' => (int) $plan->workLogs->sum('actual_minutes'),
            'completed_tasks' => $plan->tasks->where('status', 'done')->count(),
            'adjustment_count' => $plan->adjustments->count(),
            'constellation' => [
                'x' => $position['x'],
                'y' => $position['y'],
                'ring' => $position['ring'],
            ],
        ];
    }

    /**
     * Stable positions for the compact Achievement Constellation.
     *
     * @return array{x:float,y:float,ring:int}
     */
    private function constellationPosition(int $index): array
    {
        $slots = [
            [18, 34], [36, 18], [58, 20], [80, 34],
            [70, 62], [48, 76], [24, 66], [50, 46],
        ];

        if (isset($slots[$index])) {
            return [
                'x' => (float) $slots[$index][0],
                'y' => (float) $slots[$index][1],
                'ring' => $index < 4 ? 1 : 2,
            ];
        }

        $extra = $index - count($slots);
        $angle = deg2rad(($extra * 137.508) - 90);

        return [
            'x' => round(50 + (cos($angle) * 38), 2),
            'y' => round(50 + (sin($angle) * 34), 2),
            'ring' => 3,
        ];
    }
}
