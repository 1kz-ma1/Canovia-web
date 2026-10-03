<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Models\Task;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\ExecutionModeService;
use App\Services\NavigationFlowService;
use App\Services\PlanOwnershipService;
use App\Services\RecommendationService;
use App\Services\UserBehaviorService;
use App\Services\UserStateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NavigationController extends Controller
{
    private const SESSION_KEY = 'navigation.draft';

    public function index(
        Request $request,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        UserBehaviorService $behaviorService,
        UserStateService $stateService,
        RecommendationService $recommendationService,
        ExecutionModeService $executionModes,
        BehaviorEventLogger $logger,
    ) {
        $actorToken = $identity->resolve($request);
        $plans = $ownership->ownedPlans($request, [
            'tasks' => fn ($query) => $query
                ->with(['prerequisite', 'prerequisites'])
                ->orderBy('sort_order')
                ->orderBy('id'),
            'workLogs' => fn ($query) => $query->latest('worked_on')->latest('id'),
            'availabilityRules',
            'availabilityOverrides',
        ]);
        $plans = $plans->filter(fn ($plan) => $ownership->canEdit($request, $plan))->values();
        $availableExecutionModes = $executionModes->availableModes($plans);
        $requestedMode = trim((string) $request->query('mode', ''));
        if (! array_key_exists($requestedMode, $availableExecutionModes)) {
            $requestedMode = '';
        }

        $baseline = $behaviorService->baseline($actorToken);
        $state = $stateService->calculate($actorToken, $baseline, $plans);

        if ($request->boolean('all')) {
            $currentDraft = $request->session()->get(self::SESSION_KEY, []);
            $executionMode = is_array($currentDraft) ? ($currentDraft['execution_mode'] ?? null) : null;
            $request->session()->put(self::SESSION_KEY, [
                'step' => 'recommendation',
                'intent' => 'decide',
                'minutes' => 0,
                'selection_steps' => 0,
                'excluded_task_ids' => [],
                'execution_mode' => $requestedMode !== '' ? $requestedMode : $executionMode,
            ]);
        } elseif ($request->filled('plan_id')) {
            $contextPlan = $plans->firstWhere('id', (int) $request->integer('plan_id'));

            if ($contextPlan) {
                // DashboardでPlanを見てから「今日やること」へ移った行動自体を
                // 「このPlanを進めたい」という弱い入力として引き継ぐ。
                $request->session()->put(self::SESSION_KEY, [
                    'step' => 'recommendation',
                    'intent' => 'decide',
                    'minutes' => 0,
                    'scope_plan_id' => $contextPlan->id,
                    'execution_mode' => $executionModes->modeForPlan($contextPlan),
                    'selection_steps' => 0,
                    'excluded_task_ids' => [],
                ]);
            }
        }

        $draft = $request->session()->get(self::SESSION_KEY);

        if (! is_array($draft)) {
            $draft = [
                'step' => 'recommendation',
                'intent' => 'decide',
                'minutes' => 0,
                'selection_steps' => 0,
                'excluded_task_ids' => [],
            ];
        }

        if ($requestedMode !== '' && ($draft['execution_mode'] ?? null) !== $requestedMode) {
            $draft = [
                'step' => 'recommendation',
                'intent' => 'decide',
                'minutes' => 0,
                'execution_mode' => $requestedMode,
                'selection_steps' => 0,
                'excluded_task_ids' => [],
            ];
        }

        $selectedExecutionMode = $draft['execution_mode'] ?? null;
        if (! is_string($selectedExecutionMode) || ! array_key_exists($selectedExecutionMode, $availableExecutionModes)) {
            $selectedExecutionMode = count($availableExecutionModes) === 1
                ? array_key_first($availableExecutionModes)
                : null;
        }

        if ($selectedExecutionMode !== null) {
            $draft['execution_mode'] = $selectedExecutionMode;
        } else {
            unset($draft['execution_mode'], $draft['scope_plan_id']);
        }

        // Real-device refinement: candidate swiping is now the lightweight
        // way to change what to execute. Retire the old multi-step condition
        // flow from the primary Execution surface, including stale sessions
        // that may still contain intent/time steps.
        if (($draft['step'] ?? 'recommendation') !== 'recommendation') {
            $draft['step'] = 'recommendation';
            $draft['intent'] = 'decide';
            $draft['minutes'] = 0;
            $draft['selection_steps'] = 0;
            $draft['excluded_task_ids'] = [];
            unset($draft['preferred_plan_id'], $draft['started_at']);
        }

        $request->session()->put(self::SESSION_KEY, $draft);

        $modePlans = $executionModes->plansForMode($plans, $selectedExecutionMode);
        $scopePlan = ! empty($draft['scope_plan_id'])
            ? $modePlans->firstWhere('id', (int) $draft['scope_plan_id'])
            : null;

        if (! empty($draft['scope_plan_id']) && ! $scopePlan) {
            unset($draft['scope_plan_id']);
            $request->session()->put(self::SESSION_KEY, $draft);
        }
        $recommendation = null;
        $recommendations = collect();

        if (($draft['step'] ?? null) === 'recommendation' && $selectedExecutionMode !== null) {
            $recommendationPlans = $scopePlan && ($draft['intent'] ?? null) !== 'preferred'
                ? collect([$scopePlan])
                : $modePlans;
            $candidateExclusions = collect($draft['excluded_task_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $recommendation = $recommendationService->recommend(
                $recommendationPlans,
                $state,
                timeBudgetMinutes: ! empty($draft['minutes']) ? (int) $draft['minutes'] : null,
                excludedTaskIds: $candidateExclusions,
                intent: $draft['intent'] ?? null,
                actorToken: $actorToken,
                preferredPlanId: $draft['preferred_plan_id'] ?? null,
            );

            if ($recommendation) {
                $recommendations->push($recommendation);
                $candidateExclusions[] = (int) $recommendation->task->id;

                // The rail is Plan-to-Plan comparison only. Never fill it with
                // another Task from the primary Plan just to reach a fixed card count.
                // Candidates must also lead to the same concrete handoff. In Study
                // mode this prevents Recall/Resource/admin Tasks from appearing beside
                // an AI-question-practice recommendation.
                $primaryCompatibilityKey = (string) (
                    $executionModes
                        ->actionFor($recommendation->plan, $recommendation->task)['compatibility_key']
                    ?? 'timer'
                );

                $otherPlanCandidates = $modePlans
                    ->reject(fn ($candidatePlan) => (int) $candidatePlan->id === (int) $recommendation->plan->id)
                    ->map(function ($candidatePlan) use (
                        $recommendationService,
                        $executionModes,
                        $primaryCompatibilityKey,
                        $state,
                        $draft,
                        $actorToken,
                        &$candidateExclusions,
                    ) {
                        $compatibleTaskIds = $candidatePlan->tasks
                            ->filter(function (Task $candidateTask) use (
                                $candidatePlan,
                                $executionModes,
                                $primaryCompatibilityKey,
                            ) {
                                $action = $executionModes->actionFor($candidatePlan, $candidateTask);

                                return (string) ($action['compatibility_key'] ?? 'timer')
                                    === $primaryCompatibilityKey;
                            })
                            ->pluck('id')
                            ->map(fn ($id) => (int) $id)
                            ->values()
                            ->all();

                        if ($compatibleTaskIds === []) {
                            return null;
                        }

                        $candidate = $recommendationService->recommend(
                            collect([$candidatePlan]),
                            $state,
                            timeBudgetMinutes: ! empty($draft['minutes']) ? (int) $draft['minutes'] : null,
                            excludedTaskIds: $candidateExclusions,
                            intent: $draft['intent'] ?? null,
                            actorToken: $actorToken,
                            candidateTaskIds: $compatibleTaskIds,
                        );

                        if ($candidate) {
                            $candidateExclusions[] = (int) $candidate->task->id;
                        }

                        return $candidate;
                    })
                    ->filter()
                    ->sortByDesc(fn ($candidate) => $candidate->priorityScore)
                    ->take(3)
                    ->values();

                $recommendations = $recommendations
                    ->concat($otherPlanCandidates)
                    ->values();

                $logger->recordOnce(
                    $actorToken,
                    BehaviorEventType::RecommendationShown,
                    $request,
                    $recommendation->plan,
                    $recommendation->task,
                    ['source' => 'navigation', 'priority_score' => $recommendation->priorityScore],
                    withinMinutes: 2,
                );
            }
        }

        $recommendationAction = $recommendation
            ? $executionModes->actionFor($recommendation->plan, $recommendation->task)
            : null;
        $recommendationActions = $recommendations
            ->mapWithKeys(fn ($candidate) => [
                (int) $candidate->task->id => $executionModes->actionFor(
                    $candidate->plan,
                    $candidate->task,
                ),
            ]);

        return view('navigation.index', [
            'plans' => $plans,
            'modePlans' => $modePlans,
            'availableExecutionModes' => $availableExecutionModes,
            'selectedExecutionMode' => $selectedExecutionMode,
            'selectedExecutionModeDefinition' => $selectedExecutionMode !== null
                ? ($availableExecutionModes[$selectedExecutionMode] ?? null)
                : null,
            'state' => $state,
            'draft' => $draft,
            'recommendation' => $recommendation,
            'recommendations' => $recommendations,
            'recommendationAction' => $recommendationAction,
            'recommendationActions' => $recommendationActions,
            'scopePlan' => $scopePlan,
        ]);
    }

    public function chooseIntent(
        Request $request,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        UserBehaviorService $behaviorService,
        UserStateService $stateService,
        NavigationFlowService $flowService,
        BehaviorEventLogger $logger,
    ) {
        $actorToken = $identity->resolve($request);
        $plans = $ownership->ownedPlans($request, ['tasks', 'workLogs'])
            ->filter(fn ($plan) => $ownership->canEdit($request, $plan))
            ->values();
        $state = $stateService->calculate($actorToken, $behaviorService->baseline($actorToken), $plans);
        $validated = $request->validate([
            'intent' => ['required', Rule::in(array_keys($flowService->intentOptions($state)))],
        ]);
        $existingDraft = $request->session()->get(self::SESSION_KEY, []);
        $scopePlanId = is_array($existingDraft) ? ($existingDraft['scope_plan_id'] ?? null) : null;
        $executionMode = is_array($existingDraft) ? ($existingDraft['execution_mode'] ?? null) : null;

        $nextDraft = [
            'step' => 'time',
            'intent' => $validated['intent'],
            'started_at' => now()->toIso8601String(),
            'selection_steps' => 1,
            'excluded_task_ids' => [],
        ];

        if ($scopePlanId && $validated['intent'] !== 'preferred') {
            $nextDraft['scope_plan_id'] = (int) $scopePlanId;
        }
        if (is_string($executionMode) && $executionMode !== '') {
            $nextDraft['execution_mode'] = $executionMode;
        }

        $request->session()->put(self::SESSION_KEY, $nextDraft);
        $logger->record($actorToken, BehaviorEventType::NavigationStarted, $request, metadata: [
            'intent' => $validated['intent'],
        ]);

        return redirect()->route('navigation.index');
    }

    public function chooseTime(
        Request $request,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        ExecutionModeService $executionModes,
    )
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! is_array($draft) || ($draft['step'] ?? null) !== 'time') {
            return redirect()->route('navigation.index');
        }

        $validated = $request->validate([
            'minutes' => ['required', 'integer', Rule::in([0, 15, 30, 60])],
            'preferred_plan_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (($draft['intent'] ?? null) === 'preferred') {
            $preferredPlans = $ownership->ownedPlans($request)
                ->filter(fn ($candidate) => $ownership->canEdit($request, $candidate))
                ->values();

            if (is_string($draft['execution_mode'] ?? null)) {
                $preferredPlans = $executionModes->plansForMode(
                    $preferredPlans,
                    $draft['execution_mode'],
                );
            }

            $plan = $preferredPlans
                ->firstWhere('id', (int) ($validated['preferred_plan_id'] ?? 0));

            if (! $plan) {
                throw ValidationException::withMessages(['preferred_plan_id' => '進めたいPlanを選択してください。']);
            }

            $draft['preferred_plan_id'] = $plan->id;
            unset($draft['scope_plan_id']);
        }

        $draft['minutes'] = (int) $validated['minutes'];
        $draft['step'] = 'recommendation';
        $draft['selection_steps'] = (int) ($draft['selection_steps'] ?? 1) + 1;
        $request->session()->put(self::SESSION_KEY, $draft);

        $logger->record($identity->resolve($request), BehaviorEventType::NavigationCompleted, $request, metadata: [
            'intent' => $draft['intent'],
            'minutes' => $draft['minutes'],
            'selection_steps' => $draft['selection_steps'],
            'duration_seconds' => isset($draft['started_at'])
                ? max(0, (int) Carbon::parse($draft['started_at'])->diffInSeconds(now()))
                : null,
        ]);

        return redirect()->route('navigation.index');
    }

    public function alternative(
        Request $request,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        PlanOwnershipService $ownership,
    ) {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! is_array($draft) || ($draft['step'] ?? null) !== 'recommendation') {
            return redirect()->route('navigation.index');
        }

        $validated = $request->validate(['task_id' => ['required', 'integer', 'min:1']]);
        $task = Task::with('plan')->findOrFail($validated['task_id']);
        $ownership->authorizeTask($request, $task);
        $draft['excluded_task_ids'] = collect($draft['excluded_task_ids'] ?? [])
            ->push($task->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $draft['selection_steps'] = (int) ($draft['selection_steps'] ?? 2) + 1;
        $request->session()->put(self::SESSION_KEY, $draft);
        $actorToken = $identity->resolve($request);
        $logger->record($actorToken, BehaviorEventType::RecommendationRejected, $request, $task->plan, $task, ['source' => 'navigation']);
        $logger->record($actorToken, BehaviorEventType::AlternativeRequested, $request, $task->plan, $task, [
            'source' => 'navigation',
            'excluded_count' => count($draft['excluded_task_ids']),
        ]);

        return redirect()->route('navigation.index');
    }

    public function reset(Request $request)
    {
        $mode = trim((string) $request->input('mode', ''));
        $request->session()->forget(self::SESSION_KEY);

        return $mode !== ''
            ? redirect()->route('navigation.index', ['mode' => $mode])
            : redirect()->route('navigation.index');
    }
}
