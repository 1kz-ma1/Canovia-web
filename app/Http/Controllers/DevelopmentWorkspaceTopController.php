<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\DevelopmentCreativePlanAccessService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\SpecializedWorkspaceTopService;
use Illuminate\Http\Request;

final class DevelopmentWorkspaceTopController extends Controller
{
    public function __invoke(
        Request $request,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentCreativePlanAccessService $creativeAccess,
        PlanPriorityService $priorities,
        SpecializedWorkspaceTopService $tops,
        GitHubIntegrationReadinessService $githubReadiness,
    ) {
        $accessiblePlans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
            'artifacts',
        ]);
        $partition = $creativeAccess->partition($request, $accessiblePlans, $profiles);
        $plans = $partition['plans']->sort(
            fn (Plan $left, Plan $right) =>
                $this->comparePlans($left, $right, $priorities),
        )->values();

        $actorReadiness = $githubReadiness->forActor(
            $request->user(),
        );

        $summaries = $tops->summarize($plans)->map(
            function (array $summary) use (
                $ownership,
                $request,
                $githubReadiness,
                $actorReadiness,
            ) {
                /** @var Plan $plan */
                $plan = $summary['plan'];

                $repository = $plan->artifacts
                    ->filter(
                        fn ($artifact) =>
                            (string) $artifact->provider === 'github'
                            && (string) $artifact->artifact_type
                                === 'repository',
                    )
                    ->sortByDesc(
                        fn ($artifact) =>
                            $artifact->updated_at?->timestamp ?? 0,
                    )
                    ->first();

                $connection = null;

                if ($repository) {
                    $metadata = is_array($repository->metadata)
                        ? $repository->metadata
                        : [];

                    $connection = $githubReadiness->connectionStatus(
                        $actorReadiness,
                        is_array(data_get(
                            $metadata,
                            'github_app_connection',
                        ))
                            ? data_get(
                                $metadata,
                                'github_app_connection',
                            )
                            : [],
                    );
                }

                return [
                    ...$summary,
                    'can_edit' => $ownership->canEdit(
                        $request,
                        $plan,
                    ),
                    'github_repository' => $repository,
                    'github_connection' => $connection,
                ];
            },
        )->values();

        return view('workspace.development.top', [
            'developmentPlans' => $plans,
            'developmentCreativeCandidates' => $partition['candidates'],
            'developmentPlanSummaries' => $summaries,
            'developmentGithubIntegrationStatus' =>
                $actorReadiness,
        ]);
    }

    private function comparePlans(
        Plan $left,
        Plan $right,
        PlanPriorityService $priorities,
    ): int {
        $priority = (int) data_get(
            $priorities->evaluate($left),
            'priority',
            5,
        ) <=> (int) data_get(
            $priorities->evaluate($right),
            'priority',
            5,
        );

        if ($priority !== 0) {
            return $priority;
        }

        $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
            <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

        if ($deadline !== 0) {
            return $deadline;
        }

        return ((int) $left->id) <=> ((int) $right->id);
    }
}
