<?php

namespace App\Services;

use App\Data\UserBehaviorBaselineData;
use App\Data\UserStateData;
use App\Enums\BehaviorEventType;
use App\Enums\UserBehaviorState;
use App\Models\User;
use App\Models\UserStateSnapshot;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class DashboardPresentationService
{
    public function __construct(
        private readonly PlanProgressService $progressService,
        private readonly RecommendationService $recommendationService,
        private readonly DashboardGuidanceService $guidanceService,
        private readonly RoadmapService $roadmapService,
        private readonly PlanToolService $toolService,
        private readonly ExecutionActionPolicyService $executionActions,
        private readonly PlanCategoryProfileService $categoryProfiles,
        private readonly PlanSituationResolver $situationResolver,
        private readonly PlanSurfaceEngine $surfaceEngine,
        private readonly RequestBehaviorHistory $history,
    ) {}

    public function build(
        Collection $plans,
        string $actorToken,
        UserBehaviorBaselineData $baseline,
        UserStateData $state,
        array $excludedTaskIds = [],
        array $editablePlanIds = [],
        ?User $actor = null,
        ?array $workSessionContext = null,
    ): array {
        $plans = collect($plans->all());
        $historyDays = max(7, (int) config('recommendations.baseline_days', 28));
        $completedSessions = $this->history->completedSessions($actorToken, $historyDays);
        $previousSessions = $completedSessions
            ->filter(fn ($session) => $session->task_id !== null)
            ->sortByDesc(fn ($session) => $session->ended_at?->timestamp ?? $session->started_at?->timestamp ?? 0)
            ->groupBy('plan_id')
            ->map(fn ($sessions) => $sessions->first());

        $editablePlanIds = collect($editablePlanIds)->map(fn ($id) => (int) $id)->flip();
        $guidanceDeck = $this->guidanceService->build(
            $plans,
            $state,
            $actorToken,
            $editablePlanIds->keys()->all(),
            $actor,
        );

        $currentTasksByPlan = $plans->mapWithKeys(function ($plan) use ($guidanceDeck) {
            $planGuidance = $guidanceDeck->first(
                fn (array $guidance) => (int) $guidance['plan']->id === (int) $plan->id
            );
            $currentTask = data_get($planGuidance, 'task');

            if (! $currentTask) {
                $currentTask = $plan->tasks
                    ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true)
                        && (int) $task->progress_percent < 100)
                    ->sort(function ($left, $right) {
                        $doing = ($left->status === 'doing' ? 0 : 1) <=> ($right->status === 'doing' ? 0 : 1);
                        if ($doing !== 0) {
                            return $doing;
                        }

                        $priority = (int) $left->priority <=> (int) $right->priority;
                        if ($priority !== 0) {
                            return $priority;
                        }

                        return (int) ($left->sort_order ?? PHP_INT_MAX) <=> (int) ($right->sort_order ?? PHP_INT_MAX);
                    })
                    ->first();
            }

            return [(int) $plan->id => $currentTask];
        });

        $currentTasks = $currentTasksByPlan->filter()->values();
        if ($currentTasks->isNotEmpty()) {
            (new EloquentCollection($currentTasks->all()))
                ->load(['evidences' => fn ($query) => $query->limit(8)]);
        }

        $planTabs = $plans->map(function ($plan) use (
            $previousSessions,
            $editablePlanIds,
            $guidanceDeck,
            $actor,
            $currentTasksByPlan,
        ) {
            $progress = $this->progressService->calculate($plan);
            $todayMinutes = (int) $plan->workLogs
                ->filter(fn ($log) => $log->worked_on?->isToday())
                ->sum('actual_minutes');
            $canEdit = $editablePlanIds->has((int) $plan->id);
            $planGuidance = $guidanceDeck->first(
                fn (array $guidance) => (int) $guidance['plan']->id === (int) $plan->id
            );
            $recommendation = $canEdit ? data_get($planGuidance, 'adaptive') : null;
            $previousSession = $previousSessions->get($plan->id);
            $roadmap = $this->roadmapService->build(
                $plan,
                $recommendation?->task?->id,
                $previousSession?->task_id,
            );

            $currentTask = $currentTasksByPlan->get((int) $plan->id);

            $hubTasks = $plan->tasks
                ->filter(fn ($task) => ! in_array($task->status, ['done', 'cancelled'], true)
                    && (int) $task->progress_percent < 100
                    && (int) $task->id !== (int) ($currentTask?->id ?? 0))
                ->sort(function ($left, $right) use ($currentTask) {
                    $current = ((int) $left->id === (int) ($currentTask?->id ?? 0) ? 0 : 1)
                        <=> ((int) $right->id === (int) ($currentTask?->id ?? 0) ? 0 : 1);
                    if ($current !== 0) {
                        return $current;
                    }

                    $doing = ($left->status === 'doing' ? 0 : 1) <=> ($right->status === 'doing' ? 0 : 1);
                    if ($doing !== 0) {
                        return $doing;
                    }

                    $priority = (int) $left->priority <=> (int) $right->priority;
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return (int) ($left->sort_order ?? PHP_INT_MAX) <=> (int) ($right->sort_order ?? PHP_INT_MAX);
                })
                ->take(3)
                ->values();

            $executionTools = $currentTask && $canEdit
                ? collect($this->toolService->forTask($plan, $currentTask, true, $actor))
                : collect();
            $primaryExecutionTool = $this->executionActions->primary($executionTools);

            $recentEvidenceModels = $currentTask
                ? $currentTask->getRelation('evidences')
                : collect();

            $recentEvidence = $recentEvidenceModels
                ->take(3)
                ->map(fn ($evidence) => [
                    'id' => (int) $evidence->id,
                    'source_label' => $evidence->sourceLabel(),
                    'type_label' => $evidence->typeLabel(),
                    'summary' => $evidence->summary(),
                    'confidence' => (float) $evidence->confidence,
                    'occurred_at' => $evidence->occurred_at,
                ])
                ->values();

            $categoryProfile = $this->categoryProfiles->forPlan($plan);
            $situation = $this->situationResolver->resolve(
                $plan,
                $categoryProfile,
                $currentTask,
                $recentEvidenceModels,
                $executionTools,
            );
            $surfaceModules = $this->surfaceEngine->build(
                $plan,
                $categoryProfile,
                $situation,
                $currentTask,
            );
            $surfacePolicyContext = $this->surfaceEngine->policyContext(
                $plan,
                $categoryProfile,
                $situation,
                $surfaceModules,
            );

            return [
                'plan' => $plan,
                'progress' => $progress,
                'today_minutes' => $todayMinutes,
                'previous_session' => $previousSession,
                'recent_logs' => $plan->workLogs->sortByDesc('worked_on')->take(3)->values(),
                'recommendation' => $recommendation,
                'roadmap' => $roadmap,
                'can_edit' => $canEdit,
                'hub_current_task' => $currentTask,
                'hub_tasks' => $hubTasks,
                'execution_tools' => $executionTools,
                'primary_execution_tool' => $primaryExecutionTool,
                'recent_evidence' => $recentEvidence,
                'category_profile' => $categoryProfile,
                'situation' => $situation,
                'surface_modules' => $surfaceModules,
                'surface_policy_context' => $surfacePolicyContext,
            ];
        })->values();

        $recentActivity = $planTabs
            ->flatMap(fn (array $item) => $item['recent_logs']->map(fn ($log) => ['plan' => $item['plan'], 'log' => $log]))
            ->sortByDesc(fn (array $item) => sprintf('%s-%010d', $item['log']->worked_on?->format('Y-m-d') ?? '0000-00-00', $item['log']->id))
            ->take(6)
            ->values();

        $totalDailyRequired = (int) $planTabs->sum(fn ($item) => $item['progress']['daily_required_minutes']);
        $todayMinutes = (int) $planTabs->sum('today_minutes');
        $remainingMinutes = (int) $planTabs->sum(fn ($item) => $item['progress']['remaining_minutes']);
        // Compatibility alias: consumers that still read dashboard['recommendation']
        // receive the adaptive advice for the objective first Guidance Task.
        // Home no longer lets behavioral scoring replace the selected Task.
        $recommendation = data_get($guidanceDeck->first(), 'adaptive');
        $attentionPlans = $planTabs
            ->filter(fn ($item) => in_array($item['progress']['status'], ['遅れ気味', '期限切れ', '作業時間不足'], true))
            ->sortByDesc(fn ($item) => $item['progress']['daily_required_minutes'])
            ->take(3)
            ->values();
        if ($workSessionContext !== null) {
            $pendingPlanUpdates = collect($workSessionContext['pending_plan_updates'] ?? [])->values();
            $activeWorkSession = $workSessionContext['active_work_session'] ?? null;
        } else {
            $dashboardSessions = WorkSession::with(['plan', 'task'])
                ->where('actor_token', $actorToken)
                ->where(function ($query) {
                    $query->whereIn('status', ['active', 'paused'])
                        ->orWhere(function ($pendingQuery) {
                            $pendingQuery
                                ->where('needs_plan_update', true)
                                ->whereIn('status', ['completed', 'interrupted']);
                        });
                })
                ->get();

            $pendingPlanUpdates = $dashboardSessions
                ->filter(fn ($session) => (bool) $session->needs_plan_update
                    && in_array($session->status, ['completed', 'interrupted'], true))
                ->sortByDesc(fn ($session) => $session->ended_at?->timestamp ?? 0)
                ->take(5)
                ->values();
            $activeWorkSession = $dashboardSessions
                ->filter(fn ($session) => in_array($session->status, ['active', 'paused'], true))
                ->sortByDesc(fn ($session) => $session->started_at?->timestamp ?? 0)
                ->first();
        }
        $behaviorEvents = $this->history->events($actorToken, 60);
        $workStartedEvents = $behaviorEvents
            ->where('event_type', BehaviorEventType::WorkStarted)
            ->values();
        $activeDays = $workStartedEvents
            ->filter(fn ($event) => $event->occurred_at?->gte(
                now()->subDays((int) config('recommendations.baseline_days', 28))
            ))
            ->map(fn ($event) => $event->occurred_at->toDateString())
            ->unique()
            ->count();
        $analysisReady = $baseline->sampleCount >= (int) config('recommendations.analysis_min_samples', 5)
            && $activeDays >= (int) config('recommendations.analysis_min_days', 3);

        $trend = $analysisReady
            ? UserStateSnapshot::query()
                ->where('actor_token', $actorToken)
                ->latest('snapshot_date')
                ->take(14)
                ->get()
                ->sortBy('snapshot_date')
                ->values()
            : collect();

        $trendReady = $analysisReady && $trend->count() >= (int) config('recommendations.trend_min_days', 3);

        $streakDays = $this->streakDays($workStartedEvents);
        $processHighlights = $this->processHighlights(
            $workStartedEvents,
            $completedSessions,
            $baseline,
            $todayMinutes,
            $totalDailyRequired,
            $streakDays,
        );
        $uiMode = $this->uiMode($state, $analysisReady);

        return [
            'plans' => $plans,
            'plan_tabs' => $planTabs,
            'recent_activity' => $recentActivity,
            'total_daily_required_minutes' => $totalDailyRequired,
            'today_minutes' => $todayMinutes,
            'remaining_minutes' => $remainingMinutes,
            'recommendation' => $recommendation,
            'guidance_deck' => $guidanceDeck,
            'attention_plans' => $attentionPlans,
            'baseline' => $baseline,
            'state' => $state,
            'active_work_session' => $activeWorkSession,
            'pending_plan_updates' => $pendingPlanUpdates,
            'trend' => $trend,
            'analysis_ready' => $analysisReady,
            'trend_ready' => $trendReady,
            'active_days' => $activeDays,
            'streak_days' => $streakDays,
            'process_highlights' => $processHighlights,
            'process_message' => $processHighlights[0] ?? null,
            'ui_mode' => $uiMode,
        ];
    }

    private function processHighlights(
        Collection $workStartedEvents,
        Collection $completedSessions,
        UserBehaviorBaselineData $baseline,
        int $todayMinutes,
        int $dailyRequiredMinutes,
        int $streakDays,
    ): array {
        if ($todayMinutes <= 0) {
            return [];
        }

        $highlights = [];
        $started = $workStartedEvents
            ->filter(fn ($event) => $event->occurred_at?->gte(today()))
            ->sortBy('occurred_at')
            ->first();
        $latency = (int) data_get($started?->metadata, 'start_latency_seconds', 0);

        if ($baseline->sampleCount >= 5 && $latency > max(120, $baseline->startLatencySeconds * 1.3)) {
            $highlights[] = "開始まで普段より時間がかかりましたが、その後{$todayMinutes}分取り組めています。";
        }

        if ($streakDays >= 2) {
            $highlights[] = "今日も取り組みが続き、{$streakDays}日連続で作業を開始できています。";
        }

        $qualified = $completedSessions
            ->filter(fn ($session) => $session->started_at?->gte(today()))
            ->filter(fn ($session) => (int) $session->actual_seconds >= (int) config('recommendations.min_focus_session_seconds', 120));

        if ($qualified->count() >= 2) {
            $highlights[] = "今日は{$qualified->count()}回に分けて、合計{$todayMinutes}分を積み上げています。";
        }

        if ($qualified->sum('paused_seconds') > 0 && $todayMinutes >= 10) {
            $highlights[] = '途中で休憩を挟みながら、作業時間を積み上げられています。';
        }

        if ($dailyRequiredMinutes > 0 && $todayMinutes < $dailyRequiredMinutes) {
            $highlights[] = "今日の目安にはまだ届いていませんが、{$todayMinutes}分の実績は残せています。";
        } elseif ($dailyRequiredMinutes > 0 && $todayMinutes >= $dailyRequiredMinutes) {
            $highlights[] = '今日の作業目安に到達しています。追加で進める場合も無理に増やす必要はありません。';
        }

        return collect($highlights)->unique()->take(2)->values()->all();
    }

    private function uiMode(UserStateData $state, bool $analysisReady): string
    {
        if (! $analysisReady || $state->confidence < 0.3) {
            return 'balanced';
        }

        return match ($state->state) {
            UserBehaviorState::LowReadiness,
            UserBehaviorState::Overloaded,
            UserBehaviorState::Undecided => 'guided',
            UserBehaviorState::Focused => 'expanded',
            default => 'balanced',
        };
    }

    private function streakDays(Collection $workStartedEvents): int
    {
        $dates = $workStartedEvents
            ->filter(fn ($event) => $event->occurred_at?->gte(today()->subDays(60)))
            ->map(fn ($event) => $event->occurred_at->toDateString())
            ->unique()
            ->flip();
        $streak = 0;

        for ($day = today(); $day->gte(today()->subDays(60)); $day->subDay()) {
            if (! $dates->has($day->toDateString())) {
                if ($streak === 0 && $day->isToday()) {
                    continue;
                }
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
