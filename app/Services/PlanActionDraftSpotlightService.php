<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActionDraft;

/**
 * Read-only projection: never opens a modal or marks anything read on GET.
 * Only current, owner-prepared 3-step proposals are eligible; the once-only
 * acknowledgment lives on the Plan, not in ephemeral browser storage.
 */
final class PlanActionDraftSpotlightService
{
    public function __construct(private readonly PlanActionDraftStepService $stepService) {}

    public function readyForPlan(Plan $plan): ?PlanActionDraft
    {
        if ($plan->action_draft_spotlight_acknowledged_at !== null) {
            return null;
        }

        return PlanActionDraft::with('steps')->where('plan_id', $plan->id)
            ->where('status', PlanActionDraft::PROPOSED)
            ->whereHas('steps')
            ->latest('id')
            ->limit(30)
            ->get()
            ->first(fn (PlanActionDraft $draft) =>
                $this->stepService->isCurrent($plan, $draft, $draft->steps)
            );
    }
}
