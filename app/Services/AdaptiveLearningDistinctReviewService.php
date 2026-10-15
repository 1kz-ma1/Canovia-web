<?php

namespace App\Services;

use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only V6.16 published Bank selection: independently addressable
 * Questions never previously answered in this actor's exact Plan/Task.
 * Full scoped AnswerEvent history is tested via a DB subquery (not a
 * 60-event heuristic). No content, grade or proficiency fabrication.
 */
final class AdaptiveLearningDistinctReviewService
{
    public function __construct(
        private readonly AdaptiveLearningBankQueueService $queue,
        private readonly AdaptiveLearningReviewEntryService $review,
    ) {}

    /** @return list<int> The first two eligible unseen source Question IDs. */
    public function questionIds(
        Request $request, Plan $plan, Task $task, string $actorToken,
        QuestionPack $pack, string $topic,
    ): array {
        if ($pack->status !== 'published' || trim($topic) === '') return [];

        // A prior answered question counts even if its grade was adjusted
        // or was in Exam mode. The original source was still exposed.
        // Scope by actor AND Task; never expose cross-user history.
        $seen = LearningRunItem::query()->select('question_id')
            ->whereNotNull('question_id')->whereHas('answer')
            ->whereHas('run', function ($q) use ($request, $plan, $task, $actorToken): void {
                $q->where('plan_id', $plan->id)->where('task_id', $task->id);
                if ($request->user()) {
                    $q->where('user_id', $request->user()->id);
                } else {
                    $q->whereNull('user_id')->where('actor_token', $actorToken);
                }
            });

        $ids = [];
        foreach ($pack->questions()->where('is_active', true)
            ->whereNotIn('id', $seen)->orderBy('sort_order')->orderBy('id')->cursor() as $question) {
            if ($this->queue->isSupported($question) && $this->review->matches($question, $topic)) {
                $ids[] = (int) $question->id;
                if (count($ids) === 2) break;
            }
        }
        return count($ids) === 2 ? $ids : [];
    }

    /** @return Collection<int,QuestionPack> */
    public function packsForTopic(Request $request, Plan $plan, Task $task,
        string $actorToken, string $topic): Collection
    {
        $found = collect();
        foreach (QuestionPack::query()->where('status', 'published')
            ->whereHas('questions', fn ($q) => $q->where('is_active', true))
            ->orderBy('title')->orderBy('id')->lazy(30) as $pack) {
            if (count($this->questionIds($request, $plan, $task, $actorToken, $pack, $topic)) === 2) {
                $found->push($pack);
            }
            if ($found->count() >= 8) break;
        }
        return $found;
    }
}
