<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceStateSnapshot;
use App\Models\Plan;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;

final class IntelligencePresentationHistoryService
{
    public function __construct(
        private readonly PlanOwnershipService $ownership,
    ) {}

    /**
     * @return array{
     *   actions:\Illuminate\Support\Collection,
     *   decisions:\Illuminate\Support\Collection,
     *   states:\Illuminate\Support\Collection
     * }
     */
    public function forPlan(
        Request $request,
        Plan $plan,
        IntelligenceDomain $domain,
        int $limit = 6,
    ): array {
        $this->ownership->authorizeView($request, $plan);

        $limit = max(1, min(12, $limit));

        return [
            'actions' => IntelligenceActionProjection::query()
                ->where('plan_id', $plan->id)
                ->where('domain', $domain->value)
                ->latest('updated_at')
                ->latest('id')
                ->take($limit)
                ->get(),
            'decisions' => IntelligenceDecisionTrace::query()
                ->where('plan_id', $plan->id)
                ->where('domain', $domain->value)
                ->latest('created_at')
                ->latest('id')
                ->take($limit)
                ->get(),
            'states' => IntelligenceStateSnapshot::query()
                ->where('plan_id', $plan->id)
                ->where('domain', $domain->value)
                ->latest('captured_at')
                ->latest('id')
                ->take(min(4, $limit))
                ->get(),
        ];
    }
}
