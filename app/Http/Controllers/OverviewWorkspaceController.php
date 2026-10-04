<?php

namespace App\Http\Controllers;

use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligenceStateChangeFeedbackService;
use App\Intelligence\Presentation\PlanIntelligencePresentation;
use App\Intelligence\Presentation\PlanIntelligencePresentationService;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Services\BehaviorIdentityService;
use App\Services\HomePageDataService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class OverviewWorkspaceController extends Controller
{
    public function __invoke(
        Request $request,
        HomePageDataService $home,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
        PlanIntelligencePresentationService $presentations,
        BehaviorIdentityService $identity,
        IntelligenceStateChangeFeedbackService $stateChanges,
    ) {
        $homeData = $home->build($request, prefetch: true);
        $plans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $studyPlan = $this->topPlan(
            $plans,
            'study',
            $profiles,
            $priorities,
        );
        $developmentPlan = $this->topPlan(
            $plans,
            'development',
            $profiles,
            $priorities,
        );

        $actorToken = $identity->resolve($request);
        $userId = $request->user()?->id;
        $pendingInbox = $this->ownInboxItems($userId, $actorToken)
            ->with('plan')
            ->whereIn('status', ['new', 'review'])
            ->latest('id');

        $intelligenceChanges = collect([
            $studyPlan instanceof Plan
                ? $stateChanges->latestForPlan(
                    $studyPlan,
                    IntelligenceDomain::Study,
                )
                : null,
            $developmentPlan instanceof Plan
                ? $stateChanges->latestForPlan(
                    $developmentPlan,
                    IntelligenceDomain::Development,
                )
                : null,
        ])->filter()
            ->sortByDesc(
                fn (array $change) =>
                    $change['occurred_at']?->timestamp ?? 0,
            )
            ->take(2)
            ->values();

        $primaryIntelligence = $homeData['intelligencePresentation'] ?? null;
        $primaryGuidance = collect(
            data_get($homeData, 'dashboard.guidance_deck', []),
        )->first();

        return view('workspace.overview.index', [
            'primaryIntelligence' => $primaryIntelligence,
            'primaryGuidance' => $primaryGuidance,
            'canEditPrimaryIntelligence' => $primaryIntelligence instanceof PlanIntelligencePresentation
                ? $ownership->canEdit($request, $primaryIntelligence->plan)
                : false,
            'studySummary' => $this->modeSummary(
                'study',
                $studyPlan,
                $presentations,
            ),
            'developmentSummary' => $this->modeSummary(
                'development',
                $developmentPlan,
                $presentations,
            ),
            'pendingInboxCount' => (clone $pendingInbox)->count(),
            'pendingInboxItems' => $pendingInbox->take(4)->get(),
            'importantSignals' => collect(
                data_get($homeData, 'actionHome.signals', []),
            )->take(4)->values(),
            'intelligenceChanges' => $intelligenceChanges,
        ]);
    }

    /**
     * @param Collection<int,Plan> $plans
     */
    private function topPlan(
        Collection $plans,
        string $profileKey,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
    ): ?Plan {
        $plan = $plans
            ->filter(
                fn (Plan $candidate) =>
                    $profiles->forPlan($candidate)->key === $profileKey,
            )
            ->sort(
                fn (Plan $left, Plan $right) =>
                    $this->comparePlans($left, $right, $priorities),
            )
            ->first();

        return $plan instanceof Plan ? $plan : null;
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

    /**
     * @return array<string,mixed>
     */
    private function modeSummary(
        string $mode,
        ?Plan $plan,
        PlanIntelligencePresentationService $presentations,
    ): array {
        $workspaceUrl = $mode === 'study'
            ? route('workspace.study.index', $plan ? ['plan_id' => $plan->id] : [])
            : route('workspace.development.index', $plan ? ['plan_id' => $plan->id] : []);

        if (! $plan instanceof Plan) {
            return [
                'mode' => $mode,
                'plan' => null,
                'presentation' => null,
                'setup_needed' => true,
                'empty' => true,
                'workspace_url' => $workspaceUrl,
            ];
        }

        $presentation = $presentations->forPlan($plan);

        if (! $presentation instanceof PlanIntelligencePresentation) {
            return [
                'mode' => $mode,
                'plan' => $plan,
                'presentation' => null,
                'setup_needed' => true,
                'empty' => false,
                'workspace_url' => $workspaceUrl,
            ];
        }

        $setupNeeded = $mode === 'study'
            ? (int) data_get(
                $presentation->state->metrics,
                'confirmed_scope_count',
                0,
            ) <= 0
            : ! is_array(data_get(
                $presentation->state->facts,
                'focus_task_state',
            ));

        return [
            'mode' => $mode,
            'plan' => $plan,
            'presentation' => $presentation,
            'setup_needed' => $setupNeeded,
            'empty' => false,
            'workspace_url' => $workspaceUrl,
        ];
    }

    private function ownInboxItems(
        ?int $userId,
        string $actorToken,
    ): Builder {
        return InboxItem::query()->where(
            function (Builder $query) use ($userId, $actorToken) {
                if ($userId) {
                    $query->where('user_id', $userId)
                        ->orWhere('actor_token', $actorToken);

                    return;
                }

                $query
                    ->whereNull('user_id')
                    ->where('actor_token', $actorToken);
            },
        );
    }
}
