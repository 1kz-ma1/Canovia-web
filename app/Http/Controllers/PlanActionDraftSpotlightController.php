<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanActionDraftSpotlightService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;

final class PlanActionDraftSpotlightController extends Controller
{
    public function acknowledge(
        Request $request, Plan $plan, PlanOwnershipService $ownership,
        PlanActionDraftSpotlightService $spotlight,
    ) {
        $ownership->authorizePlan($request, $plan);
        $validated = $request->validate(['action' => ['required', 'in:open,dismiss']]);

        // When a competing request has already acknowledged the plan, both
        // actions remain safe no-ops. A POST cannot create a new Draft.
        if ($spotlight->readyForPlan($plan) !== null) {
            Plan::whereKey($plan->id)
                ->whereNull('action_draft_spotlight_acknowledged_at')
                ->update(['action_draft_spotlight_acknowledged_at' => now()]);
        }

        return redirect()->route(
            $validated['action'] === 'open'
                ? 'plans.action_drafts.index' : 'plans.show',
            $plan,
        );
    }
}
