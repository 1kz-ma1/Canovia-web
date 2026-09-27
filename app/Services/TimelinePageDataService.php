<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Request;

final class TimelinePageDataService
{
    public function __construct(private readonly CoreContextService $core) {}

    public function build(Request $request): array
    {
        $plans = $this->core->plans($request, ['work_logs']);

        $items = $plans
            ->flatMap(fn ($plan) => $plan->workLogs->map(fn ($log) => ['plan' => $plan, 'log' => $log]))
            ->sortByDesc(fn (array $item) => sprintf(
                '%s-%010d',
                $item['log']->worked_on?->format('Y-m-d') ?? '0000-00-00',
                $item['log']->id,
            ))
            ->take(60)
            ->groupBy(fn (array $item) => $item['log']->worked_on?->format('Y-m-d') ?? '日付不明');

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

        return compact('plans', 'items', 'similarPlans');
    }
}
