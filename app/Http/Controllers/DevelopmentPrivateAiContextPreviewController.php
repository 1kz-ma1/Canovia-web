<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\DevelopmentPrivateAiContextPreviewService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Private, first-party session preview. NEVER a delegated OAuth or MCP API.
 */
final class DevelopmentPrivateAiContextPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentPrivateAiContextPreviewService $preview,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $plan->user_id !== null
            && (int) $plan->user_id === (int) $user->id, 404);

        // A team/shared Plan may contain other members' work; do not export it
        // through a personal handoff until delegated team consent exists.
        abort_if((bool) $plan->is_collaborative, 404);
        $ownership->authorizePlan($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);

        $validated = $request->validate([
            'scope' => ['sometimes', 'in:overview,tasks'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:8'],
        ]);

        return response()->json($preview->preview(
            $plan,
            (string) ($validated['scope'] ?? 'overview'),
            (int) ($validated['limit'] ?? 5),
        ))->header('Cache-Control', 'private, no-store');
    }
}
