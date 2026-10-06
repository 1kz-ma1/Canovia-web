<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Intelligence\Study\StudyActivityOutcomeObservationService;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Intelligence\Study\StudyMethodRecommendationService;
use App\Intelligence\Study\StudyWorkspaceRecommendationService;
use App\Intelligence\Study\StudyWorkspaceStateResolver;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
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
        StudyAdaptiveActionService $studyActions,
        StudyLearningTypeRouter $learningTypes,
        StudyWorkspaceStateResolver $stateResolver,
        StudyWorkspaceRecommendationService $practiceRecommendations,
        StudyMethodRecommendationService $methodRecommendations,
        StudyActivityOutcomeObservationService $activityOutcomes,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($this->categoryProfiles->forPlan($plan)->key === 'study', 404);

        $plan->loadMissing('resources');
        $task->loadMissing('resources');

        $adaptive = $studyActions->evaluate($plan);
        $learningType = $learningTypes->route($plan);
        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);
        $resolvedState = $stateResolver->resolve(
            $plan,
            $adaptive,
            $request->user()?->id,
            $actorToken,
        );
        $practiceRecommendation = $practiceRecommendations->recommend(
            $plan,
            $task,
            $request->user()?->id,
            $actorToken,
        );
        $methodRecommendation = $methodRecommendations->recommend(
            $plan,
            $task,
            $learningType,
            $resolvedState,
            $practiceRecommendation,
            $request->user()?->id,
            $actorToken,
            (string) data_get(
                $adaptive->primaryAction()?->metadata,
                'route_kind',
                '',
            ),
        );

        return view('study_activity.show', [
            'plan' => $plan,
            'task' => $task,
            'activity' => $activities->forPlanTask($plan, $task),
            'methodRecommendation' => $methodRecommendation,
            'activityOutcomes' => $activityOutcomes->project(
                $plan,
                $task,
                $request->user()?->id,
                $actorToken,
            ),
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
