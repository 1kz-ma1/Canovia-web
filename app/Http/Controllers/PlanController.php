<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use App\Models\Plan;
use App\Models\GoalContext;
use App\Enums\WorkspaceMode;
use App\Enums\BehaviorEventType;
use App\Services\BehaviorIdentityService;
use App\Services\BehaviorEventLogger;
use App\Services\ContinuityService;
use App\Services\ExecutionActionPolicyService;
use App\Services\PlanOwnershipService;
use App\Services\PlanCollaborationService;
use App\Services\PlanActivityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanProgressService;
use App\Services\PlanPriorityService;
use App\Services\PlanLifecyclePersonalizationAdapter;
use App\Services\PlanTimelineService;
use App\Services\PlanToolService;
use App\Services\RecommendationService;
use App\Services\RoadmapService;
use App\Services\UserBehaviorService;
use App\Services\UserStateService;
use App\Services\GoalContextService;
use App\Services\GoalContextAccessService;
use App\Services\WorkspaceModeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PlanController extends Controller
{
    public function create(
        Request $request,
        WorkspaceModeRegistry $workspaceModes,
    ) {
        $prefill = $request->session()->pull('plan_create_prefill', []);
        $workspaceModeContext = $this->workspaceModeCreateContext(
            $request->query('workspace_mode'),
            $workspaceModes,
        );

        if ($request->boolean('collaborative') && $request->user()) {
            $prefill['is_collaborative'] = true;
        }

        if (
            is_array($workspaceModeContext)
            && empty($prefill['category'])
        ) {
            $prefill['category'] = $workspaceModeContext[
                'suggested_plan_category'
            ];
        }

        return view(
            'plans.create',
            compact('prefill', 'workspaceModeContext'),
        );
    }

    public function store(
        Request $request,
        PlanCollaborationService $collaboration,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
        GoalContextService $goalContexts,
        GoalContextAccessService $goalContextAccess,
        PlanCategoryProfileService $categoryProfiles,
        WorkspaceModeRegistry $workspaceModes,
        PlanLifecyclePersonalizationAdapter $planLifecycle,
    ) {
        $workspaceModeKeys = $workspaceModes->available()
            ->filter(fn ($definition) =>
                $definition->mode !== WorkspaceMode::Overview
                && $definition->onboardingSteps !== []
            )
            ->map(fn ($definition) => $definition->mode->value)
            ->values()
            ->all();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'priority_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'visual_icon' => ['nullable', 'string', 'max:16'],
            'accent_key' => ['nullable', Rule::in(Plan::ACCENT_KEYS)],
            'roadmap_world' => ['nullable', Rule::in(Plan::ROADMAP_WORLDS)],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'is_public' => ['nullable'],
            'is_collaborative' => ['nullable'],
            'create_request_id' => ['nullable', 'uuid'],
            'goal_context_id' => ['nullable', 'integer'],
            'workspace_mode' => [
                'nullable',
                'string',
                Rule::in($workspaceModeKeys),
            ],
            'personalization_seed_key' => [
                'nullable',
                'string',
                'max:80',
            ],
            'personalization_seed_domain' => [
                'nullable',
                Rule::in(['study', 'development']),
            ],
        ]);

        $goalContext = null;
        if (! empty($validated['goal_context_id'])) {
            $goalContext = GoalContext::query()->findOrFail((int) $validated['goal_context_id']);
            $goalContext = $goalContextAccess->authorize($request, $goalContext, $identity);

            if ($goalContext->plan_id !== null) {
                $existingPlan = Plan::query()->find((int) $goalContext->plan_id);
                if ($existingPlan) {
                    $workspaceRedirect = $this->workspaceModeRedirect(
                        $existingPlan,
                        $validated['workspace_mode'] ?? null,
                        $categoryProfiles,
                        $workspaceModes,
                        alreadyExists: true,
                    );

                    if ($workspaceRedirect instanceof RedirectResponse) {
                        return $workspaceRedirect;
                    }

                    return redirect()
                        ->route('plans.show', $existingPlan)
                        ->with('status', 'この目標はすでにPlanへ接続済みです。現在の状況から続けられます。');
                }
            }

            // Goal Context is the source of truth for a discovery-created Plan.
            // Never trust a client-side hidden title to rewrite the desired state.
            $validated['title'] = trim((string) $goalContext->desired_state);
        }

        $startDate = $validated['start_date'] ?? now()->toDateString();
        $deadline = $validated['deadline'] ?? null;

        if ($deadline && Carbon::parse($deadline)->lt(Carbon::parse($startDate))) {
            return back()->withErrors(['deadline' => '期限は開始日以降にしてください。'])->withInput();
        }

        if ($request->boolean('is_collaborative') && ! $request->user()) {
            return back()->withErrors(['is_collaborative' => '共同計画を作るにはログインが必要です。'])->withInput();
        }

        $ownerToken = Str::random(64);
        $createRequestId = $validated['create_request_id'] ?? (string) Str::uuid();

        // The request ID is persisted on the Plan itself. If Safari/PWA resends
        // the same form because the redirect was not rendered, createOrFirst()
        // converges every retry onto the original Plan instead of duplicating it.
        $acceptedPersonalizationSeed = $request->session()->get(
            'canovia.personalization.accepted_seed',
        );
        $acceptedPersonalizationSeed = is_array(
            $acceptedPersonalizationSeed,
        )
            ? $acceptedPersonalizationSeed
            : null;

        $plan = Plan::query()->createOrFirst(
            ['creation_request_id' => $createRequestId],
            [
                'user_id' => $request->user()?->id,
                'owner_token' => $ownerToken,
                'public_slug' => Str::uuid()->toString(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'] ?? null,
                'priority' => $validated['priority'] ?? 3,
                'priority_mode' => $validated['priority_mode'] ?? 'auto',
                'visual_icon' => $validated['visual_icon'] ?? null,
                'accent_key' => $validated['accent_key'] ?? 'sky',
                'roadmap_world' => $validated['roadmap_world'] ?? 'default',
                'start_date' => $startDate,
                'deadline' => $deadline,
                'is_public' => $request->boolean('is_public'),
                'is_collaborative' => false,
            ]
        );

        $request->session()->forget(
            'canovia.personalization.accepted_seed',
        );

        if (! $plan->wasRecentlyCreated) {
            if ($plan->user_id !== null && (int) $plan->user_id !== (int) ($request->user()?->id ?? 0)) {
                abort(409, 'この作成リクエストは別のアカウントで使用済みです。');
            }

            if (! $request->user() && is_string($plan->owner_token) && $plan->owner_token !== '') {
                cookie()->queue('pace_keeper_owner_token_' . $plan->id, $plan->owner_token, 60 * 24 * 365, '/', null, app()->environment('production') || $request->isSecure(), true, false, 'lax');
            }

            if ($goalContext) {
                $goalContexts->attachPlan($goalContext, $plan);
            } else {
                $goalContexts->ensureForPlan(
                    $plan,
                    $request->user()?->id,
                    $request->user() ? null : $identity->resolve($request),
                );
            }

            $workspaceRedirect = $this->workspaceModeRedirect(
                $plan,
                $validated['workspace_mode'] ?? null,
                $categoryProfiles,
                $workspaceModes,
                alreadyExists: true,
            );

            if ($workspaceRedirect instanceof RedirectResponse) {
                return $workspaceRedirect;
            }

            return redirect()->route('plans.show', $plan)
                ->with('status', 'この計画はすでに作成済みです。重複を作らず、今の状態から続けられます。');
        }

        if (
            $plan->wasRecentlyCreated
            && is_array($acceptedPersonalizationSeed)
            && filled($acceptedPersonalizationSeed['key'] ?? null)
        ) {
            $events->recordSafely(
                $identity->resolve($request),
                BehaviorEventType::PlanCreatedFromSeed,
                $request,
                $plan,
                metadata: [
                    'seed_key' => (string) $acceptedPersonalizationSeed['key'],
                    'domain' => (string) (
                        $acceptedPersonalizationSeed['domain']
                        ?? 'unknown'
                    ),
                ],
            );
        }

        if ($request->boolean('is_collaborative') && $request->user()) {
            if (! $collaboration->canOwnCollaborativePlan($request->user())) {
                $plan->delete();
                abort(403, '共同計画の作成権限がありません。');
            }
            $collaboration->enable($plan);
        }

        if (! $request->user()) {
            cookie()->queue('pace_keeper_owner_token_' . $plan->id, $ownerToken, 60 * 24 * 365, '/', null, app()->environment('production') || $request->isSecure(), true, false, 'lax');
        }

        if ($goalContext) {
            $goalContexts->attachPlan($goalContext, $plan);
        } else {
            $goalContexts->ensureForPlan(
                $plan,
                $request->user()?->id,
                $request->user() ? null : $identity->resolve($request),
            );
        }

        $planLifecycle->observeCreated(
            $request,
            $plan,
        );

        $workspaceRedirect = $this->workspaceModeRedirect(
            $plan,
            $validated['workspace_mode'] ?? null,
            $categoryProfiles,
            $workspaceModes,
        );

        if ($workspaceRedirect instanceof RedirectResponse) {
            return $workspaceRedirect;
        }

        return redirect()->route('plans.show', $plan)
            ->with('status', '目標を保存しました。まず今できることから始めましょう。タスクやロードマップは必要になった時点で整理できます。');
    }

    /**
     * @return array<string,string>|null
     */
    private function workspaceModeCreateContext(
        mixed $rawMode,
        WorkspaceModeRegistry $workspaceModes,
    ): ?array {
        $key = trim((string) $rawMode);
        if ($key === '') {
            return null;
        }

        $mode = WorkspaceMode::tryFrom($key);
        abort_unless(
            $mode instanceof WorkspaceMode
            && $mode !== WorkspaceMode::Overview,
            404,
        );

        $definition = $workspaceModes->definition($mode);
        abort_if(
            $definition->suggestedPlanCategory === null
            || $definition->onboardingSteps === [],
            404,
        );

        return [
            'key' => $mode->value,
            'label' => $definition->label,
            'description' => $definition->description,
            'suggested_plan_category' =>
                $definition->suggestedPlanCategory,
            'return_url' => match ($mode) {
                WorkspaceMode::Study => route('workspace.study.index'),
                WorkspaceMode::Development =>
                    route('workspace.development.index'),
                WorkspaceMode::Career =>
                    route('workspace.career.index'),
                WorkspaceMode::Overview =>
                    route('workspace.overview.index'),
            },
        ];
    }

    private function workspaceModeRedirect(
        Plan $plan,
        mixed $rawMode,
        PlanCategoryProfileService $categoryProfiles,
        WorkspaceModeRegistry $workspaceModes,
        bool $alreadyExists = false,
    ): ?RedirectResponse {
        $profile = $categoryProfiles->forPlan($plan);
        $explicitMode = trim((string) $rawMode);
        // A generic Plan form must not funnel every new Plan through AI Task
        // generation. When the chosen category maps to an available Workspace,
        // continue there without making the user pick that mode again.
        // A mismatching explicit Workspace hint is never silently replaced.
        if ($explicitMode === '') {
            $definition = $workspaceModes->forProfile($profile->key);
            if ($definition === null) {
                return null;
            }

            $mode = $definition->mode;
        } else {
            $mode = WorkspaceMode::tryFrom($explicitMode);
            if (! $mode instanceof WorkspaceMode || $mode === WorkspaceMode::Overview) {
                return null;
            }

            $definition = $workspaceModes->definition($mode);
            if (! $definition->supportsProfile($profile->key)) {
                return null;
            }
        }

        $message = $alreadyExists
            ? 'このPlanはすでに作成済みです。元のWorkspaceで続きから開きました。'
            : match ($mode) {
                WorkspaceMode::Study =>
                    '学習Planを作成しました。Study Workspaceで現在地から次のActionを決めます。',
                WorkspaceMode::Development =>
                    '開発Planを作成しました。Developer Homeで次のActionから始めます。',
                WorkspaceMode::Career =>
                    'Career Planを作成しました。次は現実のCareer情報を1件追加します。',
                WorkspaceMode::Overview =>
                    'Planを作成しました。',
            };

        $route = match ($mode) {
            WorkspaceMode::Study => 'workspace.study.index',
            WorkspaceMode::Development => 'workspace.development.index',
            WorkspaceMode::Career => 'workspace.career.index',
            WorkspaceMode::Overview => 'workspace.overview.index',
        };

        return redirect()
            ->route($route, ['plan_id' => $plan->id])
            ->with('status', $message);
    }

    public function show(
        Request $request,
        Plan $plan,
        PlanProgressService $progressService,
        PlanTimelineService $timelineService,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        UserBehaviorService $behaviorService,
        UserStateService $stateService,
        RecommendationService $recommendationService,
        RoadmapService $roadmapService,
        ContinuityService $continuityService,
        PlanToolService $toolService,
        ExecutionActionPolicyService $executionActions,
        PlanPriorityService $priorityService,
        PlanCategoryProfileService $categoryProfiles,
    ) {
        $canView = $ownership->canView($request, $plan);
        $canEdit = $ownership->canEdit($request, $plan);
        $canManage = $ownership->owns($request, $plan);
        $collaborationRole = $ownership->role($request, $plan);

        // The numeric Plan detail route contains private operational context
        // (work logs, adjustments, recommendation state). Public sharing uses
        // the dedicated random-slug route instead, so non-owners never receive
        // the full Plan detail even when is_public is enabled.
        if (! $canView) {
            abort(404);
        }

        $plan->load([
            'tasks' => fn ($query) => $query->with(['prerequisite', 'resources', 'artifacts.assignedUser:id,name'])->orderBy('sort_order')->orderBy('id'),
            'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id'),
            'adjustments' => fn ($query) => $query->latest('applied_at')->limit(10),
            'availabilityRules',
            'availabilityOverrides',
            'resources' => fn ($query) => $query->with('tasks:id,title')->latest('id'),
            'artifacts' => fn ($query) => $query->with(['assignedUser:id,name', 'tasks:id,title'])->latest('updated_at')->latest('id'),
        ]);

        $progress = $progressService->calculate($plan);
        $priorityEvaluation = $priorityService->evaluate($plan);
        $timeline = $timelineService->build($plan);
        $recommendation = null;
        $continuity = null;

        if ($canEdit) {
            $actorToken = $identity->resolve($request);
            $plans = collect([$plan]);
            $baseline = $behaviorService->baseline($actorToken);
            $state = $stateService->calculate($actorToken, $baseline, $plans);
            $recommendation = $recommendationService->recommend(
                $plans,
                $state,
                actorToken: $actorToken,
                preferredPlanId: $plan->id,
            );
            $continuity = $continuityService->forPlan($plan, $actorToken);
        }

        $roadmap = $roadmapService->build(
            $plan,
            $recommendation?->task?->id,
            $continuity['task_id'] ?? null,
        );

        $recentActivities = $plan->is_collaborative
            ? $plan->activityLogs()->with('user')->limit(8)->get()
            : collect();

        $taskTools = $plan->tasks
            ->mapWithKeys(fn ($task) => [(int) $task->id => $toolService->forTask($plan, $task, $canEdit, $request->user())])
            ->all();
        $toolFocusTask = $recommendation?->task
            ?? $plan->tasks->first(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true));
        $planTools = $toolFocusTask
            ? ($taskTools[(int) $toolFocusTask->id] ?? [])
            : [];
        $primaryPlanAction = $executionActions->primary($planTools);
        $planCategoryProfile = $categoryProfiles->forPlan($plan);
        $studyToolCategoryMismatch = $planCategoryProfile->key !== 'study'
            && $toolService->looksLikeStudyPlan($plan);

        return view('plans.show', compact(
            'plan',
            'progress',
            'timeline',
            'canEdit',
            'canManage',
            'collaborationRole',
            'recommendation',
            'continuity',
            'roadmap',
            'recentActivities',
            'taskTools',
            'toolFocusTask',
            'planTools',
            'primaryPlanAction',
            'studyToolCategoryMismatch',
            'priorityEvaluation',
            'planCategoryProfile',
        ));
    }

    public function edit(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanPriorityService $priorityService,
    ) {
        $ownership->authorizePlan($request, $plan);
        $priorityEvaluation = $priorityService->evaluate($plan);

        return view('plans.edit', compact('plan', 'priorityEvaluation'));
    }

    public function update(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        GoalContextService $goalContexts,
    ) {
        $ownership->authorizePlan($request, $plan);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'priority_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'visual_icon' => ['nullable', 'string', 'max:16'],
            'accent_key' => ['nullable', Rule::in(Plan::ACCENT_KEYS)],
            'roadmap_world' => ['nullable', Rule::in(Plan::ROADMAP_WORLDS)],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'is_public' => ['nullable'],
            'is_collaborative' => ['nullable'],
        ]);

        $startDate = $validated['start_date'] ?? $plan->start_date?->format('Y-m-d');
        $deadline = array_key_exists('deadline', $validated)
            ? $validated['deadline']
            : $plan->deadline?->format('Y-m-d');

        if ($startDate && $deadline && Carbon::parse($deadline)->lt(Carbon::parse($startDate))) {
            return back()->withErrors(['deadline' => '期限は開始日以降にしてください。'])->withInput();
        }

        $before = $plan->only(['title', 'description', 'category', 'priority', 'priority_mode', 'start_date', 'deadline', 'is_public']);
        $previousTitle = (string) $plan->title;

        $plan->update([
            'title' => $validated['title'],
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $plan->description,
            'category' => array_key_exists('category', $validated) ? $validated['category'] : $plan->category,
            'priority' => $validated['priority'] ?? (int) ($plan->priority ?? 3),
            'priority_mode' => $validated['priority_mode'] ?? ($plan->priority_mode ?: 'auto'),
            'visual_icon' => array_key_exists('visual_icon', $validated) ? $validated['visual_icon'] : $plan->visual_icon,
            'accent_key' => $validated['accent_key'] ?? $plan->accentKey(),
            'roadmap_world' => $validated['roadmap_world'] ?? $plan->roadmapWorld(),
            'start_date' => $startDate,
            'deadline' => $deadline,
            'is_public' => $request->has('is_public') ? $request->boolean('is_public') : $plan->is_public,
        ]);

        $changedFields = collect($plan->only(array_keys($before)))
            ->filter(fn ($value, $key) => (string) ($before[$key] ?? '') !== (string) $value)
            ->keys()
            ->values()
            ->all();
        $activity->record($plan, $request->user(), 'plan_updated', 'plan', (int) $plan->id, [
            'changed_fields' => $changedFields,
        ]);

        if ((string) $plan->title !== $previousTitle) {
            $goalContexts->syncPlanTitle($plan, $previousTitle);
        }

        return redirect()->route('plans.show', $plan)->with('success', '計画を更新しました。');
    }

    public function destroy(Request $request, Plan $plan, PlanOwnershipService $ownership)
    {
        $ownership->authorizePlan($request, $plan);

        $careerScreenshotPaths = $plan->careerCaptures()
            ->whereNotNull('screenshot_path')
            ->pluck('screenshot_path')
            ->filter()
            ->values();

        $plan->delete();

        $careerScreenshotPaths->each(fn ($path) => Storage::delete($path));

        return redirect()->route('home')->with('success', '計画を削除しました。');
    }
}
