<?php

namespace App\Services;

use App\Models\LearningAnswerEvent;
use App\Models\Task;
use Illuminate\Support\Collection;

/**
 * V6.17: read-only delayed recheck cue.
 * This is a bounded record-based invitation, not an assessment of mastery,
 * not a scheduled notification and never Task evidence.
 */
final class LearningDelayedRecheckService
{
    private const GAP_SECONDS = 86400;

    /**
     * @param Collection<int,LearningAnswerEvent> $events Latest 60 actor/Plan/Task scoped Understanding/Practice answers.
     * @param Collection<int,array<string,mixed>> $reviewTopics Current repeated-error candidates.
     * @return array<string,mixed>|null
     */
    public function fromHistory(Collection $events, Collection $reviewTopics, Task $task,
        string $sourceSignature): ?array
    {
        if (in_array((string) $task->status, ['done', 'completed', 'cancelled'], true)
            || (int) $task->progress_percent >= 100) {
            return null;
        }
        $topics = [];
        foreach ($events as $event) {
            if (! $event->item || $event->evaluationAdjustment || ! $event->answered_at
                || (int) ($event->item->question_id ?? 0) <= 0) {
                continue;
            }
            $metadata = data_get($event->item->question_snapshot, 'learning_metadata', []);
            if (! is_array($metadata)) continue;
            $names = array_merge(
                is_array($metadata['concepts'] ?? null) ? $metadata['concepts'] : [],
                is_array($metadata['weakness_targets'] ?? null) ? $metadata['weakness_targets'] : [],
            );
            foreach (collect($names)->filter(fn ($x) => is_string($x)
                    && trim($x) !== '' && mb_strlen(trim($x)) <= 50)
                ->map(fn (string $x) => trim($x))->unique(fn (string $x) => mb_strtolower($x))
                ->take(3) as $name) {
                $key = mb_strtolower($name);
                $topics[$key] ??= ['name' => $name, 'answers' => []];
                $topics[$key]['answers'][] = $event;
            }
        }

        // Newest qualifying topic is chosen deterministically from the
        // recorded event stream. Only one cue per Task; no attention spam.
        foreach ($topics as $key => $group) {
            if ($reviewTopics->contains(fn (array $candidate) =>
                mb_strtolower((string) ($candidate['name'] ?? '')) === $key)) continue;
            $answers = $group['answers']; // newest first
            if (count($answers) < 4
                || ! $answers[0]->was_correct || ! $answers[1]->was_correct) continue;
            $missedIds = [];
            foreach (array_slice($answers, 2) as $older) {
                if (! $older->was_correct) $missedIds[(int) $older->item->question_id] = true;
            }
            if (count($missedIds) < 2) continue;

            // Never turn a future-dated record or missing timestamp into an
            // already-due cue. The 24h threshold is a transparent convention,
            // not a calibrated retention interval.
            $elapsedSeconds = now()->getTimestamp() - $answers[0]->answered_at->getTimestamp();
            if ($elapsedSeconds < 0) continue;
            return [
                'topic' => $group['name'],
                'state' => $elapsedSeconds >= self::GAP_SECONDS ? 'due' : 'later',
                'available_at' => $answers[0]->answered_at->copy()->addSeconds(self::GAP_SECONDS),
                'last_answered_at' => $answers[0]->answered_at,
                'recent_correct_count' => 2,
                'older_distinct_misses' => count($missedIds),
                'source_signature' => $sourceSignature,
            ];
        }

        return null;
    }
}
