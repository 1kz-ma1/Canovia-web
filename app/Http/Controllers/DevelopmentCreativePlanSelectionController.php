<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\DevelopmentCreativePlanAccessService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Explicit, reversible opt-in for old "制作活動" Plans that the user chooses
 * to work on as a development project. No persistent Plan data is changed.
 */
final class DevelopmentCreativePlanSelectionController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentCreativePlanAccessService $creativeAccess,
    ): RedirectResponse {
        $ownership->authorizeView($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'creative', 404);

        $creativeAccess->optIn($request, $plan);

        return redirect()
            ->route('workspace.development.index', ['plan_id' => $plan->id])
            ->with('status', '既存の制作Planを開発Workspaceで開きました。元のカテゴリと共同計画の設定は変更していません。');
    }

    public function destroy(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        DevelopmentCreativePlanAccessService $creativeAccess,
    ): RedirectResponse {
        $ownership->authorizeView($request, $plan);
        $creativeAccess->optOut($request, $plan);

        return redirect()
            ->route('workspace.development.top')
            ->with('status', '制作Planの開発Workspaceでの表示を解除しました。元のPlanはそのまま残っています。');
    }
}
