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
                'weakness_topics' => $this->stringList($metadata['weakness_topics'] ?? []),
            ],
            'study_language_activity_completed' => [
                'activity_type' => $this->nullableString(
                    $metadata['activity_type'] ?? null,
                ),
                'rounds' => $this->nullableInt(
                    $metadata['rounds'] ?? null,
                ),
                'outcome_rating' => $this->nullableString(
                    $metadata['outcome_rating'] ?? null,
                ),
            ],
            'study_recall_reviewed' => [
                'study_recall_review_id' => $this->nullableInt($metadata['study_recall_review_id'] ?? null),
                'study_recall_item_id' => $this->nullableInt($metadata['study_recall_item_id'] ?? null),
                'study_recall_candidate_ids' => $this->intList(
                    $metadata['study_recall_candidate_ids'] ?? [],
                ),
                'study_recall_source_ids' => $this->intList(
                    $metadata['study_recall_source_ids'] ?? [],
                ),
                'candidate_confidences' => $this->percentList(
                    $metadata['candidate_confidences'] ?? [],
                ),
                'rating' => $this->nullableString($metadata['rating'] ?? null),
                'repetitions' => $this->nullableInt($metadata['repetitions'] ?? null),
                'lapse_count' => $this->nullableInt($metadata['lapse_count'] ?? null),
                'interval_days' => $this->nullableInt($metadata['interval_days'] ?? null),
                'mastered' => (bool) ($metadata['mastered'] ?? false),
            ],
            'focus_session_completed',
            'focus_session_interrupted' => [
                'actual_minutes' => $this->nullableInt($metadata['actual_minutes'] ?? null),
                'active_seconds' => $this->nullableInt($metadata['active_seconds'] ?? null),
                'interrupted' => $evidence->type === 'focus_session_interrupted',
            ],
            'pull_request_observed' => [
                ...$this->githubBaseFacts($metadata),
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'pull_request_state' => $this->nullableString(
                    $metadata['state'] ?? null,
                ),
                'draft' => (bool) ($metadata['draft'] ?? false),
                'merged' => (bool) ($metadata['merged'] ?? false),
                'head_sha' => $this->nullableString($metadata['head_sha'] ?? null),
                'head_ref' => $this->nullableString($metadata['head_ref'] ?? null),
                'base_ref' => $this->nullableString($metadata['base_ref'] ?? null),
            ],
            'pull_request_ci_observed' => [
                ...$this->githubBaseFacts($metadata),
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'ci_state' => $this->nullableString($metadata['ci_state'] ?? null),
                'head_sha' => $this->nullableString($metadata['head_sha'] ?? null),
            ],
            'pull_request_merged' => [
                ...$this->githubBaseFacts($metadata),
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'merge_commit_sha' => $this->nullableString($metadata['merge_commit_sha'] ?? null),
                'head_sha' => $this->nullableString($metadata['head_sha'] ?? null),
                'head_ref' => $this->nullableString($metadata['head_ref'] ?? null),
                'base_ref' => $this->nullableString($metadata['base_ref'] ?? null),
            ],
            'pull_request_review_submitted' => [
                ...$this->githubBaseFacts($metadata),
                'pull_request_number' => $this->nullableInt(
                    $metadata['pull_request_number'] ?? $metadata['pull_request'] ?? null,
                ),
                'review_id' => $this->nullableInt($metadata['review_id'] ?? null),
                'review_state' => $this->nullableString($metadata['review_state'] ?? null),
                'reviewer_key' => $this->hashedString(
                    $metadata['reviewer'] ?? null,
                ),
            ],
            'github_issue_observed' => [
                ...$this->githubBaseFacts($metadata),
                'issue_number' => $this->nullableInt($metadata['issue_number'] ?? null),
                'issue_state' => $this->nullableString($metadata['issue_state'] ?? null),
                'state_reason' => $this->nullableString($metadata['state_reason'] ?? null),
                'locked' => (bool) ($metadata['locked'] ?? false),
                'assignee_count' => max(0, (int) ($metadata['assignee_count'] ?? 0)),
            ],
            'github_branch_observed' => [
                ...$this->githubBaseFacts($metadata),
                'branch' => $this->nullableString($metadata['branch'] ?? null),
                'head_sha' => $this->nullableString($metadata['head_sha'] ?? null),
                'protected' => (bool) ($metadata['protected'] ?? false),
            ],
            'github_commit_observed' => [
                ...$this->githubBaseFacts($metadata),
                'commit_sha' => $this->nullableString($metadata['commit_sha'] ?? null),
                'branch' => $this->nullableString($metadata['branch'] ?? null),
                'parent_count' => max(0, (int) ($metadata['parent_count'] ?? 0)),
                'verified' => (bool) ($metadata['verified'] ?? false),
            ],
            'github_deployment_observed' => [
                ...$this->githubBaseFacts($metadata),
                'deployment_id' => $this->nullableInt($metadata['deployment_id'] ?? null),
                'deployment_sha' => $this->nullableString($metadata['deployment_sha'] ?? null),
                'ref' => $this->nullableString($metadata['ref'] ?? null),
                'environment' => $this->nullableString($metadata['environment'] ?? null),
                'deployment_status' => $this->nullableString(
                    $metadata['deployment_status'] ?? null,
                ),
                'production_environment' => (bool) (
                    $metadata['production_environment'] ?? false
                ),
                'transient_environment' => (bool) (
                    $metadata['transient_environment'] ?? false
                ),
            ],
            'development_quality_gate_confirmed' => [
                'quality_gate' => $this->nullableString(
                    $metadata['quality_gate'] ?? null,
                ),
                'gate_status' => $this->nullableString(
                    $metadata['gate_status'] ?? null,
                ),
                'confirmation_source' => $this->nullableString(
                    $metadata['confirmation_source'] ?? null,
                ),
                'target_sha' => $this->nullableString(
                    $metadata['target_sha'] ?? null,
                ),
                'deployment_id' => $this->nullableInt(
                    $metadata['deployment_id'] ?? null,
                ),
            ],
            'interview_review_completed' => [
                'career_application_id' => $this->nullableInt(
                    $metadata['career_application_id'] ?? null,
                ),
                'career_selection_event_id' => $this->nullableInt(
                    $metadata['career_selection_event_id'] ?? null,
                ),
                'interview_review_id' => $this->nullableInt(
                    $metadata['interview_review_id'] ?? null,
                ),
                'stage' => $this->nullableString(
                    $metadata['stage'] ?? null,
                ),
            ],
            'interview_result_recorded' => [
                'career_application_id' => $this->nullableInt(
                    $metadata['career_application_id'] ?? null,
                ),
                'career_selection_event_id' => $this->nullableInt(
                    $metadata['career_selection_event_id'] ?? null,
                ),
                'stage' => $this->nullableString(
                    $metadata['stage'] ?? null,
                ),
                'result' => $this->nullableString(
                    $metadata['result'] ?? null,
                ),
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

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function githubBaseFacts(array $metadata): array
    {
        return [
            'plan_artifact_id' => $this->nullableInt(
                $metadata['plan_artifact_id'] ?? null,
            ),
            'repo_full_name' => $this->nullableString(
                $metadata['repo_full_name'] ?? null,
            ),
        ];
    }

    private function boundedPercent(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : max(0, min(100, (int) $value));
    }

    /**
     * @return array<int,int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => filter_var(
                $item,
                FILTER_VALIDATE_INT,
            ))
            ->filter(fn ($item) => $item !== false)
            ->map(fn ($item) => (int) $item)
            ->filter(fn (int $item) => $item > 0)
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    private function percentList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => filter_var(
                $item,
                FILTER_VALIDATE_INT,
            ))
            ->filter(fn ($item) => $item !== false)
            ->map(fn ($item) => max(
                0,
                min(100, (int) $item),
            ))
            ->take(12)
            ->values()
            ->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    private function hashedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = mb_strtolower(trim((string) $value));

        return $value === ''
            ? null
            : hash('sha256', $value);
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
