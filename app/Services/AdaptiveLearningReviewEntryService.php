<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only, evidence-scoped bridge from V6.12 review candidate to ordinary
 * published Bank runs. No question fabrication or learning-progress inference.
 */
final class AdaptiveLearningReviewEntryService
{
    public function __construct(
        private readonly AdaptiveLearningHistoryService $history,
        private readonly AdaptiveLearningBankQueueService $queue,
    ) {}

    /** @return array<string,mixed>|null */
    public function preview(Request $request, Plan $plan, Task $task, string $actorToken): ?array
    {
        return $this->history->forPlanTask($request, $plan, $task, $actorToken)
            ['next_action_preview'] ?? null;
    }

    public function matches(Question $question, string $topic): bool
    {
        $metadata = $question->learning_metadata ?? [];
        if (! is_array($metadata)) return false;

        $names = array_merge(
            is_array($metadata['concepts'] ?? null) ? $metadata['concepts'] : [],
            is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [],
        );
        $needle = mb_strtolower(trim($topic));
        return $needle !== '' && collect($names)
            ->contains(fn ($name) => is_string($name)
                && mb_strtolower(trim($name)) === $needle);
    }

    /** Require two independently answerable source Questions in the same pack. */
    public function supportsReview(QuestionPack $pack, string $topic): bool
    {
        if ($pack->status !== 'published' || trim($topic) === '') return false;
        $seen = 0;
        foreach ($pack->questions()->where('is_active', true)->cursor() as $question) {
            if ($this->queue->isSupported($question) && $this->matches($question, $topic)) {
                if (++$seen >= 2) return true;
            }
        }
        return false;
    }

    /** @return Collection<int,QuestionPack> */
    public function packsForTopic(string $topic): Collection
    {
        $found = collect();
        // Same public/published policy as ordinary 1-question Bank. The cap
        // bounds visible options, without capping the search at first 30 packs.
        foreach (QuestionPack::query()->where('status', 'published')
            ->whereHas('questions', fn ($q) => $q->where('is_active', true))
            ->orderBy('title')->orderBy('id')->lazy(30) as $pack) {
            if ($this->supportsReview($pack, $topic)) {
                $found->push($pack);
            }
            if ($found->count() >= 8) break;
        }
        return $found;
    }
}
