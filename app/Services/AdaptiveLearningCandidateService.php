<?php
namespace App\Services;

use App\Models\LearningRun;
use App\Models\LearningRunCandidate;
use App\Models\LearningRunItem;
use App\Models\Question;
use App\Models\QuestionPack;

/**
 * EXPERIMENTAL conservative, deterministic recommendation for the replaceable
 * candidate layer. It never touches previously locked LearningRunItems.
 * Uses ONLY recorded answers (not inferred mastery).
 */
final class AdaptiveLearningCandidateService
{
    public const POLICY_VERSION = 'candidate_recent_repeated_miss_v1';

    public function __construct(private readonly AdaptiveLearningBankQueueService $bank) {}

    /** @return list<string> */
    public function recurringMissedTopics(LearningRun $run): array
    {
        $limit = max(2, min(30, (int) config('study.adaptive_learning.signal_window', 8)));
        $threshold = max(2, min(5, (int) config('study.adaptive_learning.minimum_misses', 2)));
        $recent = LearningRunItem::query()->where('learning_run_id', $run->id)
            ->whereHas('answer', fn ($q) => $q->whereDoesntHave('evaluationAdjustment'))
            ->with('answer')
            ->orderByDesc('ordinal')->limit($limit)->get();

        $histories = [];
        foreach ($recent as $item) {
            $metadata = data_get($item->question_snapshot, 'learning_metadata', []);
            $topics = collect(is_array($metadata) ? ($metadata['concepts'] ?? []) : [])
                ->merge(is_array($metadata) ? ($metadata['weakness_targets'] ?? []) : [])
                ->filter(fn ($v) => is_string($v) && trim($v) !== '')
                ->map(fn ($v) => mb_strtolower(trim($v)))->unique();

            foreach ($topics as $topic) {
                $histories[$topic][] = (bool) $item->answer->was_correct;
            }
        }

        $repeated = [];
        foreach ($histories as $topic => $newestFirst) {
            $misses = count(array_filter($newestFirst, fn (bool $correct) => ! $correct));
            // Two newest correct answers are evidence to revisit breadth.
            if ($misses >= $threshold
                && array_slice($newestFirst, 0, 2) !== [true, true]) {
                $repeated[] = $topic;
            }
        }
        return $repeated;
    }

    /** Replaces only uncommitted candidates. Called after an accepted answer. */
    public function refresh(LearningRun $run): void
    {
        if ($run->status !== LearningRun::STATUS_ACTIVE || ! $run->question_pack_id) return;
        $pack = QuestionPack::whereKey($run->question_pack_id)->where('status', 'published')->first();
        if (! $pack) return;

        $reserved = LearningRunItem::where('learning_run_id', $run->id)
            ->whereNotNull('question_id')->pluck('question_id')->map('intval')->all();
        $topics = $this->recurringMissedTopics($run);
        $limit = max(1, min(12, (int) config('study.adaptive_learning.candidate_limit', 6)));

        $available = $pack->questions()->where('is_active', true)
            ->whereNotIn('id', $reserved)->get()
            ->filter(fn (Question $q) => $this->bank->isSupported($q))
            ->map(function (Question $q) use ($topics) {
                $metadata = $q->learning_metadata ?? [];
                $concepts = collect(is_array($metadata) ? ($metadata['concepts'] ?? []) : [])
                    ->merge(is_array($metadata) ? ($metadata['weakness_targets'] ?? []) : [])
                    ->filter(fn ($x) => is_string($x) && trim($x) !== '')
                    ->map(fn ($x) => mb_strtolower(trim($x)))->unique();
                $focus = $concepts->contains(fn ($t) => in_array($t, $topics, true));
                return ['question' => $q, 'focus' => $focus];
            })->sort(function (array $a, array $b): int {
                return [$a['focus'] ? 0 : 1, (int) $a['question']->sort_order, (int) $a['question']->id]
                    <=> [$b['focus'] ? 0 : 1, (int) $b['question']->sort_order, (int) $b['question']->id];
            })->take($limit)->values();

        // The parent Run row is locked by caller transaction.
        LearningRunCandidate::where('learning_run_id', $run->id)->delete();
        $nextGeneration = (int) $run->candidate_generation + 1;
        foreach ($available as $position => $row) {
            LearningRunCandidate::create([
                'learning_run_id' => $run->id, 'question_id' => $row['question']->id,
                'generation' => $nextGeneration,
                'position' => $position + 1,
                'reason' => $row['focus'] ? 'repeated_miss_review' : 'bank_order',
            ]);
        }
        $run->update(['candidate_generation' => $nextGeneration]);
    }
}
