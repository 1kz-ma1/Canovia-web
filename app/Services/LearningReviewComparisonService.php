<?php

namespace App\Services;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use Illuminate\Support\Collection;

/**
 * V6.14: read-only comparison for V6.13 focused review attempts.
 * Uses ONLY the actor/Plan/Task-scoped bounded AnswerEvent window already
 * fetched by AdaptiveLearningHistoryService. No mastery or Task inference.
 */
final class LearningReviewComparisonService
{
    private const FOCUSED_POLICIES = ['bank_topic_review_initial_v613', 'bank_topic_distinct_initial_v616'];
    private const RUN_LIMIT = 3;

    public function __construct(
        private readonly LearningReviewSourceSpacingService $sourceSpacing,
    ) {}

    /**
     * @param Collection<int,LearningAnswerEvent> $events Newest-first, actor-scoped (max 60).
     * @param Collection<int,array<string,mixed>> $reviewTopics Existing tentative candidates.
     * @return list<array<string,mixed>>
     */
    public function fromHistory(Collection $events, Collection $reviewTopics): array
    {
        // The caller has already scoped events by current actor, exact Plan
        // and Task, and excluded Exam mode. Never perform a global Run lookup.
        $focusedRunIds = $events
            ->filter(fn (LearningAnswerEvent $event) =>
                in_array($event->item?->run?->queue_policy_version, self::FOCUSED_POLICIES, true))
            ->pluck('item.learning_run_id')->unique()->take(self::RUN_LIMIT)->all();

        $result = [];
        foreach ($focusedRunIds as $runId) {
            $runEvents = $events->filter(fn (LearningAnswerEvent $event) =>
                (int) ($event->item?->learning_run_id ?? 0) === (int) $runId
                && in_array((int) ($event->item?->ordinal ?? 0), [1, 2], true))
                ->sortBy(fn (LearningAnswerEvent $event) => (int) $event->item->ordinal)
                ->values();

            if ($runEvents->isEmpty()) continue;
            $rows = [];
            $topicSets = [];
            foreach ($runEvents as $event) {
                $questionId = (int) ($event->item?->question_id ?? 0);
                if ($questionId <= 0) continue;
                // Only a previous answer of this *same source Question*, from
                // a different Run and earlier event ID, counts as comparable.
                // Evaluations excluded as accidental taps are never baselines.
                $prior = $events->first(fn (LearningAnswerEvent $past) =>
                    (int) $past->id < (int) $event->id
                    && ! $past->evaluationAdjustment
                    && (int) ($past->item?->question_id ?? 0) === $questionId
                    && (int) ($past->item?->learning_run_id ?? 0) !== (int) $runId);

                $metadata = data_get($event->item->question_snapshot, 'learning_metadata', []);
                $names = is_array($metadata) ? array_merge(
                    is_array($metadata['concepts'] ?? null) ? $metadata['concepts'] : [],
                    is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [],
                ) : [];
                $names = collect($names)->filter(fn ($x) =>
                    is_string($x) && trim($x) !== '' && mb_strlen(trim($x)) <= 50)
                    ->map(fn (string $x) => trim($x))
                    ->unique(fn (string $x) => mb_strtolower($x))->take(3)->values();
                $topicSets[] = $names->all();

                $after = $event->evaluationAdjustment ? null : (bool) $event->was_correct;
                $before = $prior ? (bool) $prior->was_correct : null;
                $rows[] = [
                    'ordinal' => (int) $event->item->ordinal,
                    'question_id' => $questionId,
                    'prompt' => (string) data_get($event->item->question_snapshot, 'prompt', ''),
                    'answered_at' => $event->answered_at,
                    'before' => $before,
                    'after' => $after,
                    'prior_within_window' => $prior !== null,
                    'excluded' => (bool) $event->evaluationAdjustment,
                ];
            }
            if ($rows === []) continue;
            // Neither the explicit original topic nor a frozen baseline was
            // persisted in V6.13. Show ONLY labels shared by the two source
            // snapshots, never reconstruct an unsupported topic claim.
            $common = count($topicSets) === 2
                ? collect($topicSets[0])->filter(fn (string $name) =>
                    collect($topicSets[1])->contains(fn (string $other) =>
                        mb_strtolower($other) === mb_strtolower($name)))->values()->all()
                : [];
            $topic = count($common) === 1 ? $common[0] : null;

            $state = 'not_determined';
            if ($topic !== null) {
                if ($reviewTopics->contains(fn (array $candidate) =>
                    mb_strtolower((string) $candidate['name']) === mb_strtolower($topic))) {
                    $state = 'still_suggested';
                } else {
                    // Recent correct answers for THIS topic must be observed,
                    // not inferred from the fact that the review card is absent.
                    $recentTopicAnswers = $events->filter(fn (LearningAnswerEvent $event) =>
                        ! $event->evaluationAdjustment && $event->item
                        && $this->hasTopic($event, $topic))
                        ->take(2)->map(fn (LearningAnswerEvent $event) =>
                            (bool) $event->was_correct)->values()->all();
                    if ($recentTopicAnswers === [true, true]) {
                        $state = 'two_recent_correct';
                    }
                }
            }
            // These are exposure/timing observations only; do not turn a
            // different question or 24h interval into an ability estimate.
            $sourceSpacing = $this->sourceSpacing->inspect($events, $runEvents, (int) $runId, $topic);
            foreach ($rows as &$row) {
                $row['source_relation'] = $sourceSpacing['by_ordinal'][$row['ordinal']] ?? 'unknown';
            }
            unset($row);
            $run = $runEvents->first()->item->run;
            $result[] = [
                'run_id' => (int) $runId,
                'pack_title' => (string) ($run?->pack_title_snapshot ?? '問題集'),
                'started_at' => $run?->started_at,
                'topic' => $topic,
                'topic_state' => $state,
                'answers' => $rows,
                'source_spacing' => [
                    'same_count' => $sourceSpacing['same_count'],
                    'different_count' => $sourceSpacing['different_count'],
                    'unknown_count' => $sourceSpacing['unknown_count'],
                    'spacing' => $sourceSpacing['spacing'],
                    'hours_since_previous' => $sourceSpacing['hours_since_previous'],
                    'previous_answer_at' => $sourceSpacing['previous_answer_at'],
                ],
                'answered_initial_count' => count($rows),
                'comparable_count' => count(array_filter($rows, fn (array $row) =>
                    $row['prior_within_window'] && ! $row['excluded'])),
                'wrong_to_correct_count' => count(array_filter($rows, fn (array $row) =>
                    $row['before'] === false && $row['after'] === true)),
            ];
        }
        return $result;
    }

    private function hasTopic(LearningAnswerEvent $event, string $topic): bool
    {
        $metadata = data_get($event->item?->question_snapshot, 'learning_metadata', []);
        if (! is_array($metadata)) return false;
        $names = array_merge(
            is_array($metadata['concepts'] ?? null) ? $metadata['concepts'] : [],
            is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [],
        );
        return collect($names)->contains(fn ($value) =>
            is_string($value) && mb_strtolower(trim($value)) === mb_strtolower($topic));
    }
}
