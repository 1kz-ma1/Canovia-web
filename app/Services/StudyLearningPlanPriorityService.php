<?php

namespace App\Services;

use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * V6.18: bounded read-only, explainable Plan-wide answer-led next candidates.
 * This does NOT replace Study Intelligence's next-action policy, does not
 * measure proficiency and cannot start or alter any user Task by itself.
 */
final class StudyLearningPlanPriorityService
{
    private const RECENT_RUNS = 80;
    private const TASK_SCAN = 12;
    private const DISPLAY = 3;

    public function __construct(
        private readonly AdaptiveLearningHistoryService $history,
        private readonly AdaptiveLearningReviewEntryService $reviewEntry,
        private readonly AdaptiveLearningDistinctReviewService $distinct,
    ) {}

    /** @return array<string,mixed> */
    public function forWorkspace(Request $request, Plan $plan, ?string $actorToken,
        bool $canEdit): array
    {
        $empty = ['items' => [], 'has_history' => false, 'has_more' => false,
            'task_limit' => self::TASK_SCAN, 'deadline_days' => null,
            'inspected' => 0, 'unavailable' => 0, 'waiting' => 0];
        if (! $canEdit || (! $request->user() && ! filled($actorToken))) return $empty;

        $days = $plan->deadline
            ? (int) today()->diffInDays($plan->deadline->copy()->startOfDay(), false)
            : null;
        $runs = LearningRun::query()->where('plan_id', $plan->id)
            ->whereIn('mode', [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE])
            ->whereHas('items.answer');
        if ($request->user()) {
            $runs->where('user_id', $request->user()->id);
        } else {
            $runs->whereNull('user_id')->where('actor_token', $actorToken);
        }
        $taskIds = $runs->orderByDesc('id')->limit(self::RECENT_RUNS)
            ->pluck('task_id')->map(fn ($id) => (int) $id)
            ->unique()->take(self::TASK_SCAN);
        $empty['has_history'] = $taskIds->isNotEmpty();
        $empty['deadline_days'] = $days;
        if ($taskIds->isEmpty()) return $empty;

        $tasks = ($plan->relationLoaded('tasks') ? $plan->tasks : $plan->tasks()->get())
            ->keyBy('id');
        $rows = [];
        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof Task || (int) $task->plan_id !== (int) $plan->id
                || in_array((string) $task->status, ['done', 'completed', 'cancelled'], true)
                || (int) $task->progress_percent >= 100) continue;

            $empty['inspected']++;
            $overview = $this->history->forPlanTask($request, $plan, $task, (string) $actorToken);
            $instant = $overview['next_action_preview'] ?? null;
            $later = $overview['delayed_recheck'] ?? null;
            if (! is_array($instant) && ! is_array($later)) continue;

            $state = 'unavailable';
            $topic = '';
            $misses = 0;
            $action = '';
            $reason = '';
            $url = route('plans.tasks.learning.history', [$plan, $task]);
            $packCount = 0;
            if (is_array($instant)) {
                $topic = (string) $instant['topic'];
                $topicRecord = $overview['review_topics']->firstWhere('name', $topic);
                $misses = (int) ($topicRecord['distinct_missed_questions'] ?? 0);
                // Prefer two unseen distinct source IDs; if none, an ordinary
                // published, graded topic Bank can still repeat the concept.
                $newPacks = $this->distinct->packsForTopic($request, $plan, $task,
                    (string) $actorToken, $topic);
                if ($newPacks->isNotEmpty()) {
                    $state = 'repeat_errors_new_sources';
                    $packCount = $newPacks->count();
                    $reason = '異なる問題での誤答が'.$misses.'問。未回答の別問題2問で今すぐ確認できます。';
                } else {
                    $ordinary = $this->reviewEntry->packsForTopic($topic);
                    if ($ordinary->isNotEmpty()) {
                        $state = 'repeat_errors_revisit';
                        $packCount = $ordinary->count();
                        $reason = '異なる問題での誤答が'.$misses.'問。別問題は不足していますが、既出問題の復習は選べます。';
                    }
                }
                $action = '繰り返しの誤答を確認する';
                if ($packCount > 0) {
                    $url = route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]);
                }
            } else {
                $topic = (string) $later['topic'];
                $misses = (int) $later['older_distinct_misses'];
                $newPacks = $this->distinct->packsForTopic($request, $plan, $task,
                    (string) $actorToken, $topic);
                $packCount = $newPacks->count();
                $availableAt = $later['available_at'];
                $action = '後日の別問題で再確認する';
                if ($packCount === 0) {
                    $reason = '過去の異なる問題での誤答と直近2回答の正解が記録されていますが、未回答の別問題2問がありません。';
                } elseif ($later['state'] === 'due') {
                    $state = 'delayed_due';
                    $reason = '直近2回答は正解。24時間以上経過し、別問題2問で再確認できます。';
                    $url = route('plans.tasks.learning.index', [$plan, $task]);
                } else {
                    $state = 'delayed_waiting';
                    $reason = '直近2回答は正解。24時間の目安は'.$availableAt->format('Y/m/d H:i').'です。';
                    // Pending must not link to a POST-capable form; the
                    // read-only Task History explains the milestone.
                }
            }
            if ($packCount === 0) $empty['unavailable']++;
            if ($state === 'delayed_waiting') $empty['waiting']++;
            $rows[] = [
                'task_id' => (int) $task->id, 'task_title' => (string) $task->title,
                'topic' => $topic, 'action' => $action, 'reason' => $reason,
                'state' => $state, 'actionable' => in_array($state, [
                    'repeat_errors_new_sources', 'repeat_errors_revisit', 'delayed_due',
                ], true),
                'task_priority' => (int) $task->priority,
                'sort_order' => (int) $task->sort_order,
                'distinct_missed_questions' => $misses,
                'pack_count' => $packCount,
                'url' => $url,
            ];
        }

        // Deterministic priorities:
        // 1. repeated errors with two unseen sources
        // 2. repeated errors using supported existing source Questions
        // 3. delayed recheck due with two unseen sources
        // 4. still waiting, read-only
        // 5. no supported Bank / unavailable
        // Task's existing priority and number of distinct misses only
        // break ties within the same evidence/actionability band.
        $bands = ['repeat_errors_new_sources' => 0, 'repeat_errors_revisit' => 1,
            'delayed_due' => 2, 'delayed_waiting' => 3, 'unavailable' => 4];
        usort($rows, static fn (array $a, array $b): int =>
            ($bands[$a['state']] <=> $bands[$b['state']])
            ?: ($a['task_priority'] <=> $b['task_priority'])
            ?: ($b['distinct_missed_questions'] <=> $a['distinct_missed_questions'])
            ?: ($a['sort_order'] <=> $b['sort_order'])
            ?: ($a['task_id'] <=> $b['task_id']));

        return [
            'items' => array_slice($rows, 0, self::DISPLAY),
            'has_more' => count($rows) > self::DISPLAY,
            'has_history' => $empty['has_history'],
            'task_limit' => self::TASK_SCAN,
            'deadline_days' => $days,
            'inspected' => $empty['inspected'],
            'unavailable' => $empty['unavailable'],
            'waiting' => $empty['waiting'],
        ];
    }
}
