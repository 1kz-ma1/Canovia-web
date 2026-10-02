<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanDashboardWorkspaceService;
use Illuminate\Http\Request;

final class PlanDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PlanDashboardWorkspaceService $workspaceService,
    ) {
        $workspace = $workspaceService->build($request, $plan);

        if (! ($workspace['can_view'] ?? false)) {
            abort(404);
        }

        return view('plans.dashboard', [
            'workspace' => $workspace,
        ]);
    }
}
