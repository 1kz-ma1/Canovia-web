<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\Task;
use App\Services\FeatureAccessService;
use App\Services\PlanOwnershipService;
use App\Services\PlanCategoryProfileService;
use App\Services\StudyActivityPolicyService;
use Illuminate\Http\Request;

class StudyActivityController extends Controller
{
    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyActivityPolicyService $activities,
        FeatureAccessService $featureAccess,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($this->categoryProfiles->forPlan($plan)->key === 'study', 404);

        $plan->loadMissing('resources');
        $task->loadMissing('resources');

        return view('study_activity.show', [
            'plan' => $plan,
            'task' => $task,
            'activity' => $activities->forPlanTask($plan, $task),
            'canUseAiPractice' => $featureAccess->canUse(
                $request->user(),
                FeatureKey::AiPractice,
                ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
            ),
            'resources' => $task->resources->isNotEmpty()
                ? $task->resources
                : $plan->resources,
        ]);
    }
}
