<?php

namespace App\Services;

use App\Contracts\ExecutionActivityProjector;
use App\Contracts\ExecutionProviderCatalog;
use App\Enums\EvidenceSource;
use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;
use App\Models\ExecutionActivity;
use App\Models\Task;
use App\Models\TaskEvidence;
use InvalidArgumentException;

final class TaskEvidenceExecutionActivityProjector implements ExecutionActivityProjector
{
    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
        private readonly TaskEvidenceService $evidence,
    ) {}

    public function project(
        ExecutionActivity $activity,
        Task $task,
    ): TaskEvidence {
        if (
            $activity->plan_id !== null
            && (int) $activity->plan_id !== (int) $task->plan_id
        ) {
            throw new InvalidArgumentException(
                'Execution Activity cannot be projected across Plans.',
            );
        }

        $provider = $this->providers->find((string) $activity->provider_key);

        if ($provider === null) {
            throw new InvalidArgumentException(
                'Unknown execution provider cannot be projected.',
            );
        }

        $source = $provider->kind === ExecutionProviderKind::External
            ? EvidenceSource::External
            : EvidenceSource::Native;

        [$evidenceType, $metadata] = $this->evidencePayload($activity);

        return $this->evidence->record(
            $task,
            $source,
            $evidenceType,
            $metadata,
            confidence: 1.0,
            externalKey: 'execution-activity:'.$activity->id,
            userId: $activity->user_id ? (int) $activity->user_id : null,
            actorToken: $activity->user_id
                ? null
                : (string) ($activity->actor_token ?? ''),
            occurredAt: $activity->completed_at
                ?? $activity->started_at
                ?? $activity->created_at
                ?? now(),
        );
    }

    /**
     * Map only explicitly normalized Activity semantics into domain Evidence.
     *
     * Provider metadata is intentionally ignored. Domain Evidence can only be
     * created from known capability/type/status plus allowlisted metrics.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function evidencePayload(
        ExecutionActivity $activity,
    ): array {
        $metrics = is_array($activity->metrics)
            ? $activity->metrics
            : [];

        $score = $this->boundedPercent($metrics['score_percent'] ?? null);

        if (
            (string) $activity->capability === ExecutionCapability::STUDY_PRACTICE
            && (string) $activity->type === 'study_practice_completed'
            && (string) $activity->status === 'completed'
            && $score !== null
        ) {
            return [
                'study_practice_assessed',
                [
                    'execution_activity_id' => (int) $activity->id,
                    'provider_key' => (string) $activity->provider_key,
                    'score_percent' => $score,
                    'strengths' => $this->stringList(
                        $metrics['strengths'] ?? [],
                    ),
                    'weaknesses' => $this->stringList(
                        $metrics['weaknesses'] ?? [],
                    ),
                    'weakness_topics' => $this->stringList(
                        $metrics['weakness_topics'] ?? [],
                    ),
                    'evidence_summary' =>
                        'External Practice result '.$score.'%.',
                ],
            ];
        }

        return [
            'execution_activity_observed',
            [
                'execution_activity_id' => (int) $activity->id,
                'provider_key' => (string) $activity->provider_key,
                'capability' => (string) $activity->capability,
                'activity_type' => (string) $activity->type,
                'activity_status' => (string) $activity->status,
                'title' => (string) $activity->title,
                'duration_seconds' => $activity->duration_seconds !== null
                    ? (int) $activity->duration_seconds
                    : null,
                'metrics' => $metrics,
            ],
        ];
    }

    private function boundedPercent(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        if ($value === false) {
            return null;
        }

        return max(0, min(100, (int) $value));
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(
                fn ($item) =>
                    is_scalar($item)
                    && trim((string) $item) !== '',
            )
            ->map(
                fn ($item) =>
                    mb_substr(trim((string) $item), 0, 191),
            )
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }
}
