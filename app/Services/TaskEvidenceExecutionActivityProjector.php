<?php

namespace App\Services;

use App\Contracts\ExecutionActivityProjector;
use App\Contracts\ExecutionProviderCatalog;
use App\Enums\EvidenceSource;
use App\Enums\ExecutionProviderKind;
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

        return $this->evidence->record(
            $task,
            $source,
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
                'metrics' => is_array($activity->metrics)
                    ? $activity->metrics
                    : [],
            ],
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
}
