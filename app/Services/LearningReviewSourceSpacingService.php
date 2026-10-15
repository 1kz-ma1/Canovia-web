<?php

namespace App\Services;

use App\Models\LearningAnswerEvent;
use Illuminate\Support\Collection;

/**
 * V6.15: classify recorded source exposure and time spacing, NOT knowledge gain.
 * Takes only the already actor/Plan/Task-scoped 60-event history window.
 */
final class LearningReviewSourceSpacingService
{
    private const SPACED_SECONDS = 86400;

    /**
     * @param Collection<int,LearningAnswerEvent> $events Newest first; scoped by caller.
     * @param Collection<int,LearningAnswerEvent> $focused Initial ordinal 1/2 events.
     * @return array{by_ordinal:array<int,string>,same_count:int,different_count:int,
     *     unknown_count:int,spacing:string,hours_since_previous:?int,previous_answer_at:mixed}
     */
    public function inspect(Collection $events, Collection $focused, int $runId, ?string $topic): array
    {
        $unknown = [
            'by_ordinal' => [], 'same_count' => 0, 'different_count' => 0,
            'unknown_count' => 0, 'spacing' => 'unknown',
            'hours_since_previous' => null, 'previous_answer_at' => null,
        ];

        // V6.13 persisted a policy version, not an immutable topic selection.
        // Without one uniquely shared tag from BOTH snapshots we cannot
        // safely infer which topic was selected.
        $earliestId = $focused->min('id');
        if (! $earliestId || $topic === null) {
            foreach ($focused as $event) {
                $unknown['by_ordinal'][(int) $event->item->ordinal] = 'unknown';
                $unknown['unknown_count']++;
            }
            return $unknown;
        }

        // A comparison baseline must predate *the whole focused Run*, not
        // merely the current ordinal. This prevents ordinal 1 from becoming
        // invented prior evidence for ordinal 2.
        $prior = $events->filter(fn (LearningAnswerEvent $event) =>
            (int) $event->id < (int) $earliestId
            && (int) ($event->item?->learning_run_id ?? 0) !== $runId
            && (int) ($event->item?->question_id ?? 0) > 0)->values();
        $priorEligibleTopic = $prior->filter(fn (LearningAnswerEvent $event) =>
            ! $event->evaluationAdjustment && $this->hasTopic($event, $topic))->values();

        foreach ($focused as $event) {
            $ordinal = (int) ($event->item?->ordinal ?? 0);
            $questionId = (int) ($event->item?->question_id ?? 0);
            $kind = 'unknown';
            if (! $event->evaluationAdjustment && $questionId > 0
                && $this->hasTopic($event, $topic)) {
                // Even an earlier *excluded* grade proves the same source
                // Question was seen before. It cannot become "different".
                if ($prior->contains(fn (LearningAnswerEvent $old) =>
                    (int) $old->item->question_id === $questionId)) {
                    $kind = 'same_source';
                } elseif ($priorEligibleTopic->isNotEmpty()) {
                    $kind = 'different_source';
                }
            }
            $unknown['by_ordinal'][$ordinal] = $kind;
            if ($kind === 'same_source') $unknown['same_count']++;
            elseif ($kind === 'different_source') $unknown['different_count']++;
            else $unknown['unknown_count']++;
        }

        // Use the *last recorded eligible* answer in the same topic from
        // another Run, and the first eligible focused answer. Do not claim
        // 24-hour spacing based on two questions answered minutes apart
        // within one Run. Missing or reversed timestamps fail closed.
        $previous = $priorEligibleTopic->first();
        $firstCurrent = $focused
            ->filter(fn (LearningAnswerEvent $event) =>
                ! $event->evaluationAdjustment && $this->hasTopic($event, $topic))
            ->sortBy('id')->first();

        if ($previous?->answered_at && $firstCurrent?->answered_at) {
            $delta = $firstCurrent->answered_at->getTimestamp()
                - $previous->answered_at->getTimestamp();
            if ($delta >= 0) {
                $unknown['spacing'] = $delta >= self::SPACED_SECONDS
                    ? 'spaced_24h' : 'within_24h';
                $unknown['hours_since_previous'] = intdiv($delta, 3600);
                $unknown['previous_answer_at'] = $previous->answered_at;
            }
        }

        return $unknown;
    }

    private function hasTopic(LearningAnswerEvent $event, string $topic): bool
    {
        $metadata = data_get($event->item?->question_snapshot, 'learning_metadata', []);
        if (! is_array($metadata)) return false;
        $names = array_merge(
            is_array($metadata['concepts'] ?? null) ? $metadata['concepts'] : [],
            is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [],
        );
        return collect($names)->contains(fn ($term) =>
            is_string($term) && trim($term) !== ''
            && mb_strlen(trim($term)) <= 50
            && mb_strtolower(trim($term)) === mb_strtolower($topic));
    }
}
