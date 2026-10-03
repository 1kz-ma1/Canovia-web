<?php

namespace App\Intelligence\Adapters;

use App\Enums\EvidenceSource;
use App\Intelligence\Data\Confidence;
use App\Intelligence\Data\EvidenceObservation;
use App\Models\TaskEvidence;
use DateTimeImmutable;

final class TaskEvidenceAdapter
{
    public function adapt(TaskEvidence $evidence): EvidenceObservation
    {
        $source = $evidence->source instanceof EvidenceSource
            ? $evidence->source
            : EvidenceSource::from((string) $evidence->source);

        $occurredAt = DateTimeImmutable::createFromInterface(
            $evidence->occurred_at
                ?? $evidence->created_at
                ?? now(),
        );

        return new EvidenceObservation(
            reference: 'task_evidence:'.(int) $evidence->id,
            source: $source,
            type: (string) $evidence->type,
            occurredAt: $occurredAt,
            confidence: new Confidence((float) $evidence->confidence),
            facts: [
                'plan_id' => (int) $evidence->plan_id,
                'task_id' => (int) $evidence->task_id,
                ...$this->normalizedFacts($evidence),
            ],
            references: [
                'task_evidence:'.(int) $evidence->id,
            ],
        );
    }

    /**
     * Keep provider/raw payloads outside Intelligence records.
     *
     * Only fields with known decision meaning cross this boundary.
     *
     * @return array<string,mixed>
     */
    private function normalizedFacts(TaskEvidence $evidence): array
    {
        $metadata = is_array($evidence->metadata) ? $evidence->metadata : [];

        return match ((string) $evidence->type) {
            'study_practice_assessed' => [
                'study_practice_attempt_id' => $this->nullableInt($metadata['study_practice_attempt_id'] ?? null),
                'study_practice_session_id' => $this->nullableInt($metadata['study_practice_session_id'] ?? null),
                'score_percent' => $this->boundedPercent($metadata['score_percent'] ?? null),
                'recommended_task_progress_percent' => $this->boundedPercent(
                    $metadata['recommended_task_progress_percent'] ?? null,
                ),
                'strengths' => $this->stringList($metadata['strengths'] ?? []),
                'weaknesses' => $this->stringList($metadata['weaknesses'] ?? []),
                'next_step' => $this->nullableString($metadata['next_step'] ?? null),
            ],
            'study_recall_reviewed' => [
                'rating' => $this->nullableString($metadata['rating'] ?? null),
                'interval_days' => $this->nullableInt($metadata['interval_days'] ?? null),
            ],
            'focus_session_completed',
            'focus_session_interrupted' => [
                'actual_minutes' => $this->nullableInt($metadata['actual_minutes'] ?? null),
                'active_seconds' => $this->nullableInt($metadata['active_seconds'] ?? null),
                'interrupted' => $evidence->type === 'focus_session_interrupted',
            ],
            'pull_request_ci_observed' => [
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'ci_state' => $this->nullableString($metadata['ci_state'] ?? null),
            ],
            'pull_request_merged' => [
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'merge_commit_sha' => $this->nullableString($metadata['merge_commit_sha'] ?? null),
            ],
            'pull_request_review_submitted' => [
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'review_state' => $this->nullableString($metadata['review_state'] ?? null),
            ],
            'artifact_state_observed' => [
                'provider' => $this->nullableString($metadata['provider'] ?? null),
                'artifact_type' => $this->nullableString($metadata['artifact_type'] ?? null),
                'version_label' => $this->nullableString($metadata['version_label'] ?? null),
                'action' => $this->nullableString($metadata['action'] ?? null),
            ],
            'guided_execution_reflected' => [
                'outcome_rating' => $this->nullableString($metadata['outcome_rating'] ?? null),
            ],
            default => [],
        };
    }

    private function boundedPercent(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : max(0, min(100, (int) $value));
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->take(24)
            ->map(fn (string $item) => mb_substr($item, 0, 191))
            ->values()
            ->all();
    }
}
