<?php
namespace App\Services;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\LearningRunCandidate;
use App\Models\LearningRunItem;
use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Support\Collection;

/**
 * EXPERIMENTAL conservative, deterministic recommendation for the replaceable
 * candidate layer. It never touches previously locked LearningRunItems.
 * Uses ONLY recorded answers (not inferred mastery).
 */
final class AdaptiveLearningCandidateService
{
    public const POLICY_VERSION = 'candidate_scoped_repeated_miss_breadth_v2';

    public function __construct(private readonly AdaptiveLearningBankQueueService $bank) {}

    /**
     * A bounded, actor-scoped evidence window across short Learning Runs.
     * Grade events are immutable; adjusted answers are excluded. Do not mix
     * different owners, Plan/Task, or exam simulation data into this ranking.
     *
     * @return Collection<int, LearningAnswerEvent>
     */
    private function recentEvidence(LearningRun $run): Collection
    {
        // Never aggregate anonymous events whose actor identity is absent.
        if ($run->user_id === null && ! filled($run->actor_token)) {
            return collect();
        }
        $limit = max(2, min(30, (int) config('study.adaptive_learning.signal_window', 8)));

        return LearningAnswerEvent::query()
            ->whereDoesntHave('evaluationAdjustment')
            ->whereHas('item.run', function ($query) use ($run) {
                $query->where('plan_id', $run->plan_id)
                    ->where('task_id', $run->task_id)
                    ->whereIn('mode', [
                        LearningRun::MODE_UNDERSTANDING,
                        LearningRun::MODE_PRACTICE,
                    ]);
                if ($run->user_id !== null) {
                    $query->where('user_id', $run->user_id);
                } else {
                    $query->whereNull('user_id')
                        ->where('actor_token', $run->actor_token);
                }
            })
            ->with('item')
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** @return list<string> */
    public function recurringMissedTopics(LearningRun $run, ?Collection $evidence = null): array
    {
        $threshold = max(2, min(5, (int) config('study.adaptive_learning.minimum_misses', 2)));
        $histories = [];

        foreach (($evidence ?? $this->recentEvidence($run)) as $event) {
            $item = $event->item;
            // A missing source Question ID cannot provide independent
            // question evidence for a repeated weakness.
            if (! $item || ! $item->question_id) continue;

            $metadata = data_get($item->question_snapshot, 'learning_metadata', []);
            $topics = collect(is_array($metadata) ? ($metadata['concepts'] ?? []) : [])
                ->merge(is_array($metadata) ? ($metadata['weakness_targets'] ?? []) : [])
                ->filter(fn ($v) => is_string($v) && trim($v) !== '')
                ->map(fn ($v) => mb_strtolower(trim($v)))->unique();

            foreach ($topics as $topic) {
                $histories[$topic][] = [
                    'correct' => (bool) $event->was_correct,
                    'question_id' => (int) $item->question_id,
                ];
            }
        }

        $repeated = [];
        foreach ($histories as $topic => $newestFirst) {
            $misses = array_values(array_filter($newestFirst, fn (array $row) => ! $row['correct']));
            $distinctMissedQuestions = count(array_unique(array_column($misses, 'question_id')));

            // A single miss (or repeating the same question) never becomes
            // a calibrated weakness. Two recent correct answers open breadth.
            if (count($misses) >= $threshold
                && $distinctMissedQuestions >= $threshold
                && array_slice(array_column($newestFirst, 'correct'), 0, 2) !== [true, true]) {
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
        $evidence = $this->recentEvidence($run);
        $topics = $this->recurringMissedTopics($run, $evidence);
        // Recently seen questions are not permanently banned: tiny Banks
        // remain usable, but unseen candidates get a deterministic first look.
        $recentlySeen = $evidence
            ->pluck('item.question_id')->filter()->map('intval')->unique()->all();
        $limit = max(1, min(12, (int) config('study.adaptive_learning.candidate_limit', 6)));

        $available = $pack->questions()->where('is_active', true)
            ->whereNotIn('id', $reserved)->get()
            ->filter(fn (Question $q) => $this->bank->isSupported($q))
            ->map(function (Question $q) use ($topics, $recentlySeen) {
                $metadata = $q->learning_metadata ?? [];
                $concepts = collect(is_array($metadata) ? ($metadata['concepts'] ?? []) : [])
                    ->merge(is_array($metadata) ? ($metadata['weakness_targets'] ?? []) : [])
                    ->filter(fn ($x) => is_string($x) && trim($x) !== '')
                    ->map(fn ($x) => mb_strtolower(trim($x)))->unique();
                $focus = $concepts->contains(fn ($t) => in_array($t, $topics, true));
                return [
                    'question' => $q,
                    'focus' => $focus,
                    'recently_seen' => in_array((int) $q->id, $recentlySeen, true),
                ];
            })->sort(function (array $a, array $b): int {
                return [
                    $a['recently_seen'] ? 1 : 0, $a['focus'] ? 0 : 1,
                    (int) $a['question']->sort_order, (int) $a['question']->id,
                ] <=> [
                    $b['recently_seen'] ? 1 : 0, $b['focus'] ? 0 : 1,
                    (int) $b['question']->sort_order, (int) $b['question']->id,
                ];
            })->values();

        // Do not create an all-weakness tunnel: after two focused choices,
        // insert one broader question when available. Only future candidates
        // are reordered; locked questions and their snapshots never change.
        $available = $available->values();
        $balanced = collect();
        $focusStreak = 0;
        while ($balanced->count() < $limit && $available->isNotEmpty()) {
            $index = 0;
            if ($focusStreak >= 2) {
                $breadth = $available->search(fn (array $row) => ! $row['focus']);
                if ($breadth !== false) $index = $breadth;
            }
            $row = $available->splice($index, 1)->first();
            $balanced->push($row);
            $focusStreak = $row['focus'] ? $focusStreak + 1 : 0;
        }

        // The parent Run row is locked by caller transaction.
        LearningRunCandidate::where('learning_run_id', $run->id)->delete();
        $nextGeneration = (int) $run->candidate_generation + 1;
        foreach ($balanced as $position => $row) {
            LearningRunCandidate::create([
                'learning_run_id' => $run->id, 'question_id' => $row['question']->id,
                'generation' => $nextGeneration,
                'position' => $position + 1,
                'reason' => $row['recently_seen'] ? 'recent_question_revisit'
                    : ($row['focus'] ? 'repeated_miss_review' : 'bank_order'),
            ]);
        }
        $run->update(['candidate_generation' => $nextGeneration]);
    }
}
