<?php

namespace App\Services;

use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * V6.12: actor/Plan-scoped review proposals for the Study work surface.
 *
 * This is a deterministic *review queue*, not a mastery estimate or a
 * replacement for the Study Adaptive Decision Engine. No persistence.
 */
final class StudyLearningNextActionQueueService
{
    private const RECENT_RUN_WINDOW = 80;
    private const TASK_LIMIT = 12;
    private const DISPLAY_LIMIT = 3;

    public function __construct(
        private readonly AdaptiveLearningHistoryService $history,
    ) {}

    /** @return array{items:list<array<string,mixed>>,has_more:bool,task_limit:int} */
    public function forWorkspace(
        Request $request,
        Plan $plan,
        ?string $actorToken,
        bool $canEdit,
    ): array {
        $empty = ['items' => [], 'has_more' => false, 'has_history' => false,
            'task_limit' => self::TASK_LIMIT];
        if (! $canEdit || (! $request->user() && ! filled($actorToken))) {
            return $empty;
        }

        // Only inspect tasks with this actor's actual Understanding/Practice
        // answers. Bound both candidate run scan and per-task history reads.
        $runs = LearningRun::query()
            ->where('plan_id', $plan->id)
            ->whereIn('mode', [LearningRun::MODE_UNDERSTANDING, LearningRun::MODE_PRACTICE])
            ->whereHas('items.answer');
        if ($request->user()) {
            $runs->where('user_id', $request->user()->id);
        } else {
            $runs->whereNull('user_id')->where('actor_token', $actorToken);
        }
        $taskIds = $runs->orderByDesc('id')->limit(self::RECENT_RUN_WINDOW)
            ->pluck('task_id')->map(fn ($id) => (int) $id)
            ->unique()->take(self::TASK_LIMIT);

        $tasks = ($plan->relationLoaded('tasks') ? $plan->tasks : $plan->tasks()->get())
            ->keyBy('id');
        $rows = [];
        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof Task
                || (int) $task->plan_id !== (int) $plan->id
                || in_array((string) $task->status, ['done', 'completed', 'cancelled'], true)
                || (int) $task->progress_percent >= 100) {
                continue;
            }

            $overview = $this->history->forPlanTask(
                $request, $plan, $task, (string) $actorToken,
            );
            $preview = $overview['next_action_preview'] ?? null;
            if (! is_array($preview)) {
                continue;
            }
            $topic = $overview['review_topics']->firstWhere('name', $preview['topic']);
            if (! is_array($topic)) {
                continue;
            }

            $rows[] = [
                'task_id' => (int) $task->id,
                'task_title' => (string) $task->title,
                'task_priority' => (int) $task->priority,
                'sort_order' => (int) $task->sort_order,
                'topic' => (string) $preview['topic'],
                'action' => (string) $preview['label'],
                'distinct_missed_questions' => (int) ($topic['distinct_missed_questions'] ?? 0),
                'incorrect' => (int) ($topic['incorrect'] ?? 0),
                'already_adopted' => (bool) $preview['already_adopted'],
                'history_url' => route('plans.tasks.learning.history', [$plan, $task]),
                'review_url' => route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]),
            ];
        }

        // Explicit tie-break: unadopted first; more independent missed
        // Questions, then observed incorrect answers, then existing Task
        // priority / sort order / ID. No invented confidence or completion.
        usort($rows, static fn (array $a, array $b): int =>
            ((int) $a['already_adopted'] <=> (int) $b['already_adopted'])
            ?: ($b['distinct_missed_questions'] <=> $a['distinct_missed_questions'])
            ?: ($b['incorrect'] <=> $a['incorrect'])
            ?: ($a['task_priority'] <=> $b['task_priority'])
            ?: ($a['sort_order'] <=> $b['sort_order'])
            ?: ($a['task_id'] <=> $b['task_id'])
        );

        return [
            'items' => array_slice($rows, 0, self::DISPLAY_LIMIT),
            'has_more' => count($rows) > self::DISPLAY_LIMIT,
            'has_history' => $taskIds->isNotEmpty(),
            'task_limit' => self::TASK_LIMIT,
        ];
    }
}
