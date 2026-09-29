<?php

namespace App\Services;

use App\Data\TaskRecommendationData;
use App\Data\UserStateData;
use App\Enums\UserBehaviorState;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RecommendationService
{
    public function __construct(
        private readonly PlanProgressService $progressService,
        private readonly RecommendationPersonalizationService $personalizationService,
        private readonly RequestBehaviorHistory $history,
    ) {}

    public function recommend(
        Collection $plans,
        UserStateData $state,
        ?int $timeBudgetMinutes = null,
        array $excludedTaskIds = [],
        ?string $intent = null,
        ?string $actorToken = null,
        ?int $preferredPlanId = null,
        array $candidateTaskIds = [],
    ): ?TaskRecommendationData {
        $weights = config('recommendations.weights');
        $excludedTaskIds = array_map('intval', $excludedTaskIds);
        $candidateTaskIds = array_map('intval', $candidateTaskIds);
        $personalization = $this->personalizationService->profile($actorToken);
        $recentTaskSessions = $this->recentTaskSessions($actorToken);
        $totalDailyRequired = 0;
        $todayWorked = 0;
        $planContext = [];
        $baseAdaptiveMinutes = $this->adaptiveMinutes($state);
        $effectiveBudget = $timeBudgetMinutes ?? $this->personalizationService->recommendedMinutes($baseAdaptiveMinutes, $personalization);

        foreach ($plans as $plan) {
            $progress = $this->progressService->calculate($plan);
            $todayPlanMinutes = (int) $plan->workLogs
                ->filter(fn ($log) => $log->worked_on?->isToday())
                ->sum('actual_minutes');
            $lastWorkedAt = $plan->workLogs->max(fn ($log) => $log->worked_on?->timestamp ?? 0);
            $planContext[$plan->id] = compact('progress', 'todayPlanMinutes', 'lastWorkedAt');
            $totalDailyRequired += $progress['daily_required_minutes'];
            $todayWorked += $todayPlanMinutes;
        }

        $enoughDoneToday = $totalDailyRequired > 0 && $todayWorked >= $totalDailyRequired;
        $candidates = collect();

        foreach ($plans as $plan) {
            $context = $planContext[$plan->id];
            $progress = $context['progress'];
            $expectedProgress = $progress['expected_progress_percent'];
            $gap = $expectedProgress === null ? 0 : max(0, $expectedProgress - $progress['weighted_progress_percent']);
            $remainingDays = $progress['remaining_days'];

            foreach ($plan->tasks as $task) {
                if (($candidateTaskIds !== [] && ! in_array((int) $task->id, $candidateTaskIds, true))
                    || in_array($task->id, $excludedTaskIds, true)
                    || in_array($task->status, ['done', 'cancelled'], true)
                    || $task->progress_percent >= 100) {
                    continue;
                }

                $dependencyIds = $task->dependencyIds();
                if ($dependencyIds !== [] && collect($dependencyIds)->contains(function (int $dependencyId) use ($plan) {
                    $prerequisite = $plan->tasks->firstWhere('id', $dependencyId);

                    return ! $prerequisite
                        || ($prerequisite->status !== 'done' && (int) $prerequisite->progress_percent < 100);
                })) {
                    continue;
                }

                $remainingMinutes = $task->remaining_minutes ?? max(
                    0,
                    (int) round($task->estimated_minutes * (100 - $task->progress_percent) / 100)
                );

                if ($remainingMinutes <= 0) {
                    continue;
                }

                $candidateBudget = $effectiveBudget;
                if ($timeBudgetMinutes === null && ($progress['availability_configured'] ?? false)) {
                    $availableToday = (int) ($progress['today_available_remaining_minutes'] ?? 0);
                    // 予定上0分の日でも任意の5分候補は残すが、通常はその日の可処分時間を上限にする。
                    $candidateBudget = $availableToday > 0 ? min($candidateBudget, $availableToday) : 5;
                }

                $score = (6 - max(1, min(5, (int) $task->priority))) * $weights['task_priority'];
                $reasonScores = [];

                if ($gap >= 5) {
                    $bonus = min(22, $gap * $weights['behind_schedule']);
                    $score += $bonus;
                    $this->reason($reasonScores, '実績進捗が期待進捗を下回っているため', $bonus);
                }

                if ($remainingDays !== null && $remainingDays <= 7) {
                    $bonus = $weights['deadline_urgency'] * ($remainingDays <= 2 ? 1 : 0.6);
                    $score += $bonus;
                    $this->reason(
                        $reasonScores,
                        $remainingDays < 0 ? '期限を過ぎており見直しが必要なため' : "期限まで{$remainingDays}日だから",
                        $bonus
                    );
                }

                $staleDays = $context['lastWorkedAt'] > 0
                    ? (int) Carbon::createFromTimestamp($context['lastWorkedAt'])->diffInDays(now())
                    : 14;
                $staleBonus = min(12, $staleDays * $weights['stale_plan']);
                $score += $staleBonus;

                if ($staleDays >= 3 && $context['todayPlanMinutes'] === 0) {
                    $this->reason($reasonScores, '最近このPlanに取り組んでいないため', $staleBonus);
                }

                $dailyRequired = max(0, (int) $progress['daily_required_minutes']);
                if ($dailyRequired > 0) {
                    $dailyDeficit = max(0, $dailyRequired - $context['todayPlanMinutes']);
                    if ($dailyDeficit > 0) {
                        $deficitRatio = min(1, $dailyDeficit / $dailyRequired);
                        $dailyNeedBonus = 4 + ($deficitRatio * 8);
                        $score += $dailyNeedBonus;
                        if ($context['todayPlanMinutes'] === 0) {
                            $this->reason($reasonScores, '今日まだこのPlanを進めていないため', $dailyNeedBonus);
                        } elseif ($deficitRatio >= 0.45) {
                            $this->reason($reasonScores, '今日の目安までまだ余地があるため', $dailyNeedBonus);
                        }
                    } else {
                        $score -= $weights['recently_worked_penalty'] + 4;
                    }
                } elseif ($context['todayPlanMinutes'] === 0) {
                    $this->reason($reasonScores, '今日まだこのPlanを進めていないため', 3);
                }

                if ($preferredPlanId !== null && $plan->id === $preferredPlanId) {
                    $score += 20;
                    $this->reason($reasonScores, '選んだPlanの中で次に進めやすいため', 20);
                }

                if ($intent === 'recover') {
                    $score += min(15, $gap * 0.5);
                }

                $fitsBudget = $remainingMinutes <= $candidateBudget;

                if ($fitsBudget || $remainingMinutes <= $candidateBudget + 10) {
                    $score += $weights['time_fit'];
                    $fitMinutes = min($remainingMinutes, $candidateBudget);
                    $this->reason($reasonScores, "約{$fitMinutes}分で区切りよく進めやすいため", $weights['time_fit']);
                } elseif ($timeBudgetMinutes !== null) {
                    $overRatio = $remainingMinutes / max(1, $candidateBudget);
                    $score -= min(18, $weights['time_over_budget_penalty'] * min(2, $overRatio - 1));
                }

                $activationCost = $this->personalizationService->effectiveActivationCost($task, $personalization);
                $activationFit = 6 - $activationCost;
                $activationMultiplier = in_array($state->state, [UserBehaviorState::LowReadiness, UserBehaviorState::Overloaded], true)
                    ? $weights['activation_fit']
                    : ($state->state === UserBehaviorState::Focused ? 1.0 : 2.0);
                $activationBonus = $activationFit * $activationMultiplier;
                $score += $activationBonus;

                if ($activationCost <= 2 && in_array($state->state, [UserBehaviorState::LowReadiness, UserBehaviorState::Overloaded], true)) {
                    $this->reason($reasonScores, '今は始めやすい作業から着手しやすいため', $activationBonus + 8);
                }

                if ($intent === 'short') {
                    if ($remainingMinutes <= 20) {
                        $score += 10;
                        $this->reason($reasonScores, '短時間で区切りやすい作業のため', 18);
                    }
                    $score += max(0, 4 - $activationCost) * 3;
                }

                $isContinuationTask = ! empty($task->continuation_of_task_id);
                $recentSession = $recentTaskSessions[$task->id]
                    ?? ($isContinuationTask ? ($recentTaskSessions[$task->continuation_of_task_id] ?? null) : null);
                $continuation = $this->continuationBonus($recentSession, $weights['recent_continuation']);

                if ($isContinuationTask && $recentSession) {
                    $continuation += 12;
                }

                if ($continuation > 0) {
                    $score += $continuation;
                    $continuationReason = $isContinuationTask
                        ? '前回の作業結果から切り出した次のActionだから'
                        : '前回の続きで再開しやすいため';
                    $reasonWeight = $intent === 'continue' ? $continuation + 12 : $continuation;
                    $this->reason($reasonScores, $continuationReason, $reasonWeight);

                    if ($intent === 'continue') {
                        $score += 12;
                    }
                }

                $personalizationAdjustment = $this->personalizationService->scoreAdjustment(
                    $task,
                    $timeBudgetMinutes,
                    $personalization,
                ) * (float) config('recommendations.personalization_weight', 1.0);
                $score += $personalizationAdjustment;

                if ($personalizationAdjustment >= 6 && $this->personalizationService->hasMeaningfulPreference($task, $personalization)) {
                    $this->reason($reasonScores, 'これまでの選択傾向と相性がよいため', min(14, $personalizationAdjustment));
                }

                if ($enoughDoneToday) {
                    $score -= 10;
                }

                $recommendedMinutes = min($remainingMinutes, $candidateBudget);
                $reasons = collect($reasonScores)
                    ->sortDesc()
                    ->keys()
                    ->take(2)
                    ->values()
                    ->all();

                $candidates->push([
                    'plan' => $plan,
                    'task' => $task,
                    'score' => round($score, 1),
                    'reasons' => $reasons,
                    'recommended_minutes' => max(5, $recommendedMinutes),
                ]);
            }
        }

        $best = $candidates->sortByDesc('score')->first();

        if (! $best) {
            return null;
        }

        if ($enoughDoneToday) {
            array_unshift($best['reasons'], '今日必要な作業量には到達済みなので、追加で進める場合の軽い候補です');
            $best['reasons'] = array_slice(array_values(array_unique($best['reasons'])), 0, 2);
        }

        return new TaskRecommendationData(
            plan: $best['plan'],
            task: $best['task'],
            reasons: $best['reasons'],
            recommendedMinutes: $best['recommended_minutes'],
            priorityScore: $best['score'],
            optional: $enoughDoneToday,
        );
    }

    private function adaptiveMinutes(UserStateData $state): int
    {
        if (in_array($state->state, [UserBehaviorState::LowReadiness, UserBehaviorState::Overloaded], true)) {
            return (int) config('recommendations.low_readiness_minutes', 10);
        }

        if ($state->state === UserBehaviorState::Focused) {
            return (int) config('recommendations.high_focus_minutes', 45);
        }

        return (int) config('recommendations.default_minutes', 25);
    }

    private function continuationBonus(?WorkSession $session, float $maxBonus): float
    {
        if (! $session) {
            return 0;
        }

        $at = $session->ended_at ?? $session->started_at;
        $hours = max(0, $at->diffInHours(now()));
        $decayed = match (true) {
            $hours <= 6 => $maxBonus,
            $hours <= 24 => $maxBonus * 0.75,
            $hours <= 72 => $maxBonus * 0.4,
            $hours <= 168 => $maxBonus * 0.15,
            default => 0,
        };

        return $decayed;
    }

    private function recentTaskSessions(?string $actorToken): array
    {
        if (! $actorToken) {
            return [];
        }

        return $this->history
            ->completedSessions($actorToken, 7)
            ->filter(fn ($session) => $session->task_id !== null)
            ->sortByDesc(fn ($session) => $session->ended_at?->timestamp ?? $session->started_at?->timestamp ?? 0)
            ->groupBy('task_id')
            ->map(fn ($sessions) => $sessions->first())
            ->all();
    }

    private function reason(array &$reasonScores, string $reason, float $weight): void
    {
        $reasonScores[$reason] = max($reasonScores[$reason] ?? 0, $weight);
    }
}
