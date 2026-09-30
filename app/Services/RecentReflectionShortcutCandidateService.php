<?php

namespace App\Services;

use App\Enums\MapLevel;
use App\Models\Plan;
use App\Models\TaskEvidence;
use App\Models\WorkLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class RecentReflectionShortcutCandidateService
{
    private const WINDOW_DAYS = 14;

    private const MIN_RECORDS = 2;

    public function __construct(
        private readonly PlanPriorityService $priorities,
    ) {}

    /**
     * Promote the existing "最近の実績" Reflection Lens only when canonical
     * records provide enough deterministic signal. This never invents a new
     * Reflection entity or mutates Plan / Task state.
     *
     * @param Collection<int,Plan> $plans
     * @return array<string,mixed>|null
     */
    public function candidate(Collection $plans): ?array
    {
        if ($plans->isEmpty()) {
            return null;
        }

        $cutoff = now()->copy()->subDays(self::WINDOW_DAYS)->startOfDay()->timestamp;
        $records = $this->records($plans)
            ->filter(fn (array $record) => (int) ($record['timestamp'] ?? 0) >= $cutoff)
            ->sortByDesc('timestamp')
            ->values();

        if ($records->count() < self::MIN_RECORDS) {
            return null;
        }

        $planIndex = $plans->keyBy(fn (Plan $plan) => (int) $plan->id);
        $representedPlans = $records
            ->pluck('plan_id')
            ->unique()
            ->map(fn ($planId) => $planIndex->get((int) $planId))
            ->filter(fn ($plan) => $plan instanceof Plan)
            ->values();

        $importance = (float) ($representedPlans
            ->map(fn (Plan $plan) => $this->planImportance($plan))
            ->max() ?? 0.0);

        $latestTimestamp = (int) ($records->max('timestamp') ?? 0);
        $activeDays = $records
            ->map(fn (array $record) => Carbon::createFromTimestamp(
                (int) $record['timestamp']
            )->toDateString())
            ->unique()
            ->count();

        $signals = [
            'importance' => round(max(0, min(1, $importance)), 4),
            'usage_frequency' => round(min(1, $records->count() / 6), 4),
            'recency' => round($this->recencyScore(
                $latestTimestamp > 0 ? Carbon::createFromTimestamp($latestTimestamp) : null,
            ), 4),
            'continuity' => round(min(1, $activeDays / 4), 4),
        ];

        $url = route('map.index', [
            'level' => MapLevel::Plan->value,
            'intent' => 'reflection',
            'reflection_context' => 'recent',
        ]);

        return [
            'id' => 'satellite:reflection:recent',
            'kind' => 'reflection',
            'node_type' => 'satellite_reflection',
            'entity_id' => null,
            'plan_id' => null,
            'task_id' => null,
            'eyebrow' => 'REFLECTION',
            'label' => '最近の実績',
            'subtitle' => '直近の積み上げを振り返る',
            'available_action' => $url,
            'navigation_kind' => 'satellite',
            'anchor_node_id' => 'intent:reflection',
            'signals' => $signals,
            'signal_reason_labels' => [
                'importance' => '重要なPlanの実績がある',
                'usage_frequency' => '実績がまとまっている',
                'recency' => '最近実績が増えた',
                'continuity' => '継続して積み上がっている',
            ],
            'explanation_suffix' => 'ため、最近の実績を振り返る近道として表示しています。',
            'classic_surface' => [
                'kind' => 'Reflection Shortcut',
                'title' => '最近の実績',
                'summary' => 'WorkLogとEvidenceから、直近に積み上がった事実を振り返るためのショートカットです。',
                'actions' => [
                    [
                        'label' => '最近の実績を見る',
                        'url' => $url,
                        'primary' => true,
                        'navigation_kind' => 'satellite',
                    ],
                    [
                        'label' => 'Timelineを開く',
                        'url' => route('timeline.index'),
                        'primary' => false,
                    ],
                ],
                'meta' => [
                    $records->count().' recent records',
                    $activeDays.' active days',
                ],
            ],
        ];
    }

    /**
     * @param Collection<int,Plan> $plans
     * @return Collection<int,array{plan_id:int,timestamp:int}>
     */
    private function records(Collection $plans): Collection
    {
        return $plans->flatMap(function (Plan $plan) {
            $workLogs = collect($plan->workLogs ?? [])
                ->map(function (WorkLog $log) use ($plan) {
                    $time = $log->created_at
                        ?? $log->worked_on?->copy()->startOfDay();

                    return [
                        'plan_id' => (int) $plan->id,
                        'timestamp' => $time?->timestamp ?? 0,
                    ];
                });

            $evidences = collect($plan->taskEvidences ?? [])
                ->map(function (TaskEvidence $evidence) use ($plan) {
                    $time = $evidence->occurred_at ?? $evidence->created_at;

                    return [
                        'plan_id' => (int) $plan->id,
                        'timestamp' => $time?->timestamp ?? 0,
                    ];
                });

            return $workLogs->concat($evidences);
        })->filter(fn (array $record) => (int) ($record['timestamp'] ?? 0) > 0);
    }

    private function planImportance(Plan $plan): float
    {
        $priority = (int) data_get($this->priorities->evaluate($plan), 'priority', 3);

        return (6 - max(1, min(5, $priority))) / 5;
    }

    private function recencyScore(?CarbonInterface $time): float
    {
        if (! $time) {
            return 0.0;
        }

        $days = max(0, (int) $time->diffInDays(now()));

        return match (true) {
            $days <= 1 => 1.0,
            $days <= 3 => 0.85,
            $days <= 7 => 0.65,
            $days <= 14 => 0.45,
            default => 0.0,
        };
    }
}
