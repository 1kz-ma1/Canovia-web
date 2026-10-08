<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMode;
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
use App\Services\WorkspaceModeRegistry;
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
        WorkspaceModeRegistry $workspaceModes,
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
        $careerPlan = $this->topPlan(
            $plans,
            'career',
            $profiles,
            $priorities,
        );
        $careerAvailable = in_array(
            WorkspaceMode::Career->value,
            $workspaceModes->publicKeys(),
            true,
        );
        $careerSpecializedPlan = $careerAvailable
            ? $careerPlan
            : null;

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
            $careerSpecializedPlan instanceof Plan
                ? $stateChanges->latestForPlan(
                    $careerSpecializedPlan,
                    IntelligenceDomain::Career,
                )
                : null,
        ])->filter()
            ->sortByDesc(
                fn (array $change) =>
                    $change['occurred_at']?->timestamp ?? 0,
            )
            ->take(2)
            ->values();

        $firstUseModeChoices = collect();
        if (
            ! $studyPlan instanceof Plan
            && ! $developmentPlan instanceof Plan
            && ! $careerPlan instanceof Plan
        ) {
            $firstUseModeChoices = $workspaceModes->available()
                ->reject(fn ($definition) =>
                    $definition->mode === WorkspaceMode::Overview
                )
                ->filter(fn ($definition) =>
                    $definition->onboardingSteps !== []
                )
                ->map(fn ($definition) => [
                    'key' => $definition->mode->value,
                    'label' => $definition->label,
                    'description' => $definition->description,
                    'accent_tone' => $definition->accentTone,
                    'url' => route('workspace_modes.enter', [
                        'workspaceMode' => $definition->mode->value,
                    ]),
                ])
                ->values();
        }

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
            'careerSummary' => $careerAvailable
                ? $this->modeSummary(
                    'career',
                    $careerSpecializedPlan,
                    $presentations,
                )
                : [],
            'pendingInboxCount' => (clone $pendingInbox)->count(),
            'pendingInboxItems' => $pendingInbox->take(4)->get(),
            'importantSignals' => collect(
                data_get($homeData, 'actionHome.signals', []),
            )->take(4)->values(),
            'intelligenceChanges' => $intelligenceChanges,
            'firstUseModeChoices' => $firstUseModeChoices,
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
        $workspaceUrl = match ($mode) {
            'study' => route(
                'workspace.study.index',
                $plan ? ['plan_id' => $plan->id] : [],
            ),
            'development' => route(
                'workspace.development.index',
                $plan ? ['plan_id' => $plan->id] : [],
            ),
            'career' => route(
                'workspace.career.index',
                $plan ? ['plan_id' => $plan->id] : [],
            ),
            default => route('workspace.overview.index'),
        };

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

        $setupNeeded = match ($mode) {
            'study' => (int) data_get(
                $presentation->state->metrics,
                'confirmed_scope_count',
                0,
            ) <= 0,
            'development' => ! is_array(data_get(
                $presentation->state->facts,
                'focus_task_state',
            )),
            'career' => ! (bool) data_get(
                $presentation->state->facts,
                'has_career_signal',
                false,
            ),
            default => false,
        };

        return [
            'mode' => $mode,
            'plan' => $plan,
            'presentation' => $presentation,
            'setup_needed' => $setupNeeded,
            'official_exam_reference' => $mode === 'study'
                ? data_get($presentation->state->facts, 'official_exam_reference')
                : null,
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
