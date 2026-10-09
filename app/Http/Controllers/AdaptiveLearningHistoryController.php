<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Task;
use App\Services\AdaptiveLearningHistoryService;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;

final class AdaptiveLearningHistoryController extends Controller
{
    public function index(
        Request $request, Plan $plan, Task $task,
        PlanOwnershipService $ownership, PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity, AdaptiveLearningHistoryService $history,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        return view('learning.history', [
            'plan' => $plan,
            'task' => $task,
            'history' => $history->forPlanTask($request, $plan, $task, $identity->resolve($request)),
        ]);
    }
}
