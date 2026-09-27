<?php

namespace App\Services;

use App\Data\UserBehaviorBaselineData;
use App\Data\UserStateData;
use App\Enums\BehaviorEventType;
use App\Enums\UserBehaviorState;
use App\Models\UserStateSnapshot;
use Illuminate\Support\Collection;

class UserStateService
{
    public function __construct(
        private readonly PlanProgressService $progressService,
        private readonly RequestBehaviorHistory $history,
    ) {}

    public function calculate(
        string $actorToken,
        UserBehaviorBaselineData $baseline,
        ?Collection $plans = null,
    ): UserStateData
    {
        $plans = collect(($plans ?? collect())->all());
        $windowDays = (int) config('recommendations.state_window_days', 14);
        $events = $this->history->events($actorToken, $windowDays);
        $recent = $events->where('occurred_at', '>=', now()->subHour());
        $today = $events->where('occurred_at', '>=', today());

        $recentPlanViews = $recent->where('event_type', BehaviorEventType::PlanTabViewed)->count();
        $recentTaskViews = $recent->where('event_type', BehaviorEventType::TaskViewed)->count();
        $recentAlternatives = $recent->where('event_type', BehaviorEventType::AlternativeRequested)->count();
        $recentRejections = $recent->where('event_type', BehaviorEventType::RecommendationRejected)->count();
        $compositeIdleSignals = $recent->where('event_type', BehaviorEventType::DashboardIdle)->count();
        $planSignals = $plans->map(function ($plan) {
            $plan->loadMissing(['tasks', 'workLogs']);

            return [
                'progress' => $this->progressService->calculate($plan),
                'active_tasks' => $plan->tasks->reject(fn ($task) => in_array($task->status, ['done', 'cancelled'], true))->count(),
                'today_minutes' => (int) $plan->workLogs
                    ->filter(fn ($log) => $log->worked_on?->isToday())
                    ->sum('actual_minutes'),
            ];
        });
        $availableTaskCount = (int) $planSignals->sum('active_tasks');
        $behindPlanCount = $planSignals
            ->filter(fn ($signal) => in_array($signal['progress']['status'], ['遅れ気味', '期限切れ'], true))
            ->count();
        $todayWorkedMinutes = (int) $planSignals->sum('today_minutes');
        $startedToday = $today->where('event_type', BehaviorEventType::WorkStarted)->isNotEmpty()
            || $todayWorkedMinutes > 0;

        $decisionLoad = $this->clamp(
            18
            + min(30, $recentPlanViews * 5)
            + min(24, $recentTaskViews * 4)
            + min(20, $recentAlternatives * 10)
            + min(12, $recentRejections * 6)
            + min(12, $compositeIdleSignals * 8)
            + min(12, max(0, $availableTaskCount - 5) * 2)
            + min(12, $behindPlanCount * 4)
            - ($startedToday ? 10 : 0)
        );

        $latestNavigation = $recent
            ->whereIn('event_type', [
                BehaviorEventType::NavigationStarted,
                BehaviorEventType::RecommendationShown,
            ])
            ->sortByDesc('occurred_at')
            ->first();
        $navigationWait = $latestNavigation && ! $startedToday
            ? max(0, now()->diffInSeconds($latestNavigation->occurred_at))
            : 0;
        $latencyRatio = $baseline->startLatencySeconds > 0
            ? $navigationWait / $baseline->startLatencySeconds
            : 0;

        $actionReadiness = $this->clamp(
            62
            + ($startedToday ? 18 : 0)
            - min(24, $decisionLoad * 0.22)
            - min(20, $compositeIdleSignals * 10)
            - min(16, max(0, $latencyRatio - 1) * 8)
        );

        $sessions = $this->history->completedSessions($actorToken, $windowDays);
        $minFocusSeconds = (int) config('recommendations.min_focus_session_seconds', 120);
        $qualifiedSessions = $sessions->filter(fn ($session) => (int) $session->actual_seconds >= $minFocusSeconds);
        $recentDurations = $qualifiedSessions->pluck('actual_seconds')->filter()->map(fn ($seconds) => $seconds / 60);
        $durationRatio = $baseline->medianWorkMinutes > 0
            ? ($recentDurations->median() ?? $baseline->medianWorkMinutes) / $baseline->medianWorkMinutes
            : 1;
        $interruptionRate = $qualifiedSessions->isNotEmpty()
            ? $qualifiedSessions->where('status', 'interrupted')->count() / $qualifiedSessions->count()
            : $baseline->interruptionRate;
        $rawFocusContinuity = $this->clamp(50 + min(28, ($durationRatio - 1) * 30) - ($interruptionRate * 35));
        $focusReliability = min(1, $qualifiedSessions->count() / 5);
        $focusContinuity = $this->clamp(50 + ($rawFocusContinuity - 50) * $focusReliability);

        $eventActiveDates = $events
            ->where('event_type', BehaviorEventType::WorkStarted)
            ->map(fn ($event) => $event->occurred_at->toDateString())
            ->unique();
        $workLogDates = $plans
            ->flatMap(fn ($plan) => $plan->workLogs->toBase())
            ->filter(fn ($log) => $log->worked_on?->gte(today()->subDays($windowDays)))
            ->map(fn ($log) => $log->worked_on->toDateString())
            ->unique();
        $activeDays = $eventActiveDates->merge($workLogDates)->unique()->count();
        $expectedActiveDays = max(3, min(10, (int) round(max($baseline->workFrequency, 0.25) * $windowDays)));
        $rawConsistency = $this->clamp(($activeDays / $expectedActiveDays) * 80 + ($startedToday ? 10 : 0));
        $consistencyReliability = min(1, $activeDays / max(3, (int) config('recommendations.analysis_min_days', 3)));
        $consistency = $this->clamp(50 + ($rawConsistency - 50) * $consistencyReliability);

        $state = match (true) {
            $decisionLoad >= 70 && $actionReadiness < 55 => UserBehaviorState::Overloaded,
            $decisionLoad >= 55 && ! $startedToday => UserBehaviorState::Undecided,
            $actionReadiness < 45 => UserBehaviorState::LowReadiness,
            $focusContinuity >= 72 && $actionReadiness >= 60 => UserBehaviorState::Focused,
            default => UserBehaviorState::Normal,
        };

        $evidence = array_values(array_filter([
            $recentPlanViews >= 2 ? "直近1時間に{$recentPlanViews}つのPlan表示がありました" : null,
            $recentTaskViews >= 2 ? "直近1時間に{$recentTaskViews}件のTaskを確認しました" : null,
            $recentAlternatives > 0 ? '別候補を確認した記録があります' : null,
            $availableTaskCount > 5 ? "現在選べる未完了Taskが{$availableTaskCount}件あります" : null,
            $behindPlanCount > 0 ? "見直し候補のPlanが{$behindPlanCount}件あります" : null,
            $todayWorkedMinutes > 0
                ? "今日はすでに{$todayWorkedMinutes}分の実績があります"
                : ($startedToday ? '今日はすでに作業開始の記録があります' : '今日はまだ作業開始の記録がありません'),
            $activeDays > 0 ? "直近{$windowDays}日で{$activeDays}日作業を開始しました" : null,
        ]));

        return new UserStateData(
            actionReadiness: $actionReadiness,
            decisionLoad: $decisionLoad,
            focusContinuity: $focusContinuity,
            consistency: $consistency,
            state: $state,
            evidence: $evidence,
            confidence: round(min(1, max($events->count(), $sessions->count(), $workLogDates->count()) / 20), 2),
        );
    }

    public function captureDaily(string $actorToken, UserStateData $state): void
    {
        $now = now();

        UserStateSnapshot::query()->upsert(
            [[
                'actor_token' => $actorToken,
                'snapshot_date' => today()->toDateString(),
                'action_readiness' => $state->actionReadiness,
                'decision_load' => $state->decisionLoad,
                'focus_continuity' => $state->focusContinuity,
                'consistency' => $state->consistency,
                'state' => $state->state->value,
                'evidence' => json_encode($state->evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['actor_token', 'snapshot_date'],
            [
                'action_readiness',
                'decision_load',
                'focus_continuity',
                'consistency',
                'state',
                'evidence',
                'updated_at',
            ],
        );
    }

    private function clamp(float|int $value): int
    {
        return (int) round(min(100, max(0, $value)));
    }
}
