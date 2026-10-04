<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\PlanArtifact;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\WorkSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaskEvidenceService
{
    /**
     * Store a factual observation about Task execution.
     *
     * externalKey makes connector/native events idempotent. When no stable key
     * exists, the observation is appended as a new Evidence record.
     *
     * @param array<string, mixed> $metadata
     */
    public function record(
        Task $task,
        EvidenceSource $source,
        string $type,
        array $metadata = [],
        float $confidence = 1.0,
        ?string $externalKey = null,
        ?int $userId = null,
        ?string $actorToken = null,
        ?CarbonInterface $occurredAt = null,
    ): TaskEvidence {
        $type = Str::limit(trim($type), 64, '');
        $externalKey = filled($externalKey) ? Str::limit(trim((string) $externalKey), 191, '') : null;

        $values = [
            'plan_id' => (int) $task->plan_id,
            'user_id' => $userId,
            'actor_token' => $userId ? null : $actorToken,
            'type' => $type,
            'confidence' => max(0, min(1, $confidence)),
            'occurred_at' => $occurredAt ?? now(),
            'metadata' => $metadata,
        ];

        if ($externalKey !== null) {
            return TaskEvidence::query()->updateOrCreate(
                [
                    'task_id' => (int) $task->id,
                    'source' => $source->value,
                    'external_key' => $externalKey,
                ],
                $values,
            );
        }

        return TaskEvidence::query()->create([
            'task_id' => (int) $task->id,
            'source' => $source->value,
            'external_key' => null,
            ...$values,
        ]);
    }

    /**
     * Record the current state of an Artifact for every Task explicitly linked
     * to it. Registering/updating a file is useful execution evidence, but it
     * is not strong enough to advance progress on its own.
     *
     * @return Collection<int, TaskEvidence>
     */
    public function recordArtifactState(
        PlanArtifact $artifact,
        string $action,
        ?int $userId = null,
        ?string $actorToken = null,
    ): Collection {
        $artifact->loadMissing('tasks');

        $state = [
            'plan_artifact_id' => (int) $artifact->id,
            'title' => $artifact->title,
            'provider' => $artifact->provider,
            'artifact_type' => $artifact->artifact_type,
            'url' => $artifact->url,
            'version_label' => $artifact->version_label,
            'action' => $action,
        ];
        $stateHash = hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $artifact->tasks
            ->map(fn (Task $task) => $this->record(
                $task,
                EvidenceSource::Native,
                'artifact_state_observed',
                $state,
                confidence: 0.8,
                externalKey: 'plan-artifact:'.$artifact->id.':'.$stateHash,
                userId: $userId,
                actorToken: $actorToken,
                occurredAt: $artifact->updated_at ?? now(),
            ))
            ->values();
    }

    /**
     * A Focus Timer confirms that an activity happened, not how much of the
     * Task was completed. Its confidence is therefore intentionally below the
     * threshold used by EvidenceProgressService.
     *
     * @param array<string, mixed> $metrics
     */
    public function recordFocusSession(
        WorkSession $session,
        array $metrics,
        string $status,
    ): ?TaskEvidence {
        $session->loadMissing('task');
        $task = $session->task;

        if (! $task) {
            return null;
        }

        $type = $status === 'interrupted'
            ? 'focus_session_interrupted'
            : 'focus_session_completed';

        return $this->record(
            $task,
            EvidenceSource::Native,
            $type,
            [
                'work_session_id' => (int) $session->id,
                'actual_minutes' => (int) ($metrics['actual_minutes'] ?? max(1, (int) ceil(((int) $session->actual_seconds) / 60))),
                'active_seconds' => (int) ($metrics['active_seconds'] ?? $session->actual_seconds ?? 0),
                'wall_seconds' => isset($metrics['wall_seconds']) ? (int) $metrics['wall_seconds'] : null,
                'paused_seconds' => (int) ($metrics['paused_seconds'] ?? $session->paused_seconds ?? 0),
                'intended_minutes' => $session->intended_minutes ? (int) $session->intended_minutes : null,
                'source' => $session->source,
                'timer_adjustments' => data_get($session->metadata, 'timer_adjustments', []),
            ],
            confidence: $status === 'interrupted' ? 0.25 : 0.4,
            externalKey: 'work-session:'.$session->id.':'.$status,
            actorToken: $session->actor_token,
            occurredAt: $session->ended_at ?? now(),
        );
    }

    public function recordStudyPracticeAssessment(StudyPracticeAttempt $attempt): TaskEvidence
    {
        $attempt->loadMissing('task');
        $task = $attempt->task;

        return $this->record(
            $task,
            EvidenceSource::Native,
            'study_practice_assessed',
            [
                'study_practice_attempt_id' => (int) $attempt->id,
                'study_practice_session_id' => $attempt->study_practice_session_id
                    ? (int) $attempt->study_practice_session_id
                    : null,
                'score_percent' => (int) $attempt->score_percent,
                'recommended_task_progress_percent' => (int) $attempt->recommended_task_progress_percent,
                'evidence_summary' => $attempt->evidence_summary,
                'strengths' => $attempt->strengths ?? [],
                'weaknesses' => $attempt->weaknesses ?? [],
                'weakness_topics' => collect(data_get($attempt->assessment, 'question_feedback', []))
                    ->filter(fn ($item) => is_array($item))
                    ->flatMap(fn (array $item) => (array) ($item['weakness_topics'] ?? []))
                    ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
                    ->map(fn ($item) => mb_substr(trim((string) $item), 0, 191))
                    ->unique()
                    ->take(24)
                    ->values()
                    ->all(),
                'next_action' => $attempt->next_action,
                'next_step' => data_get($attempt->assessment, 'next_step'),
            ],
            confidence: 1.0,
            externalKey: 'study-practice-attempt:'.$attempt->id,
            userId: $attempt->user_id ? (int) $attempt->user_id : null,
            actorToken: $attempt->actor_token,
            occurredAt: $attempt->created_at,
        );
    }
}
