<?php

namespace App\Services;

use App\Intelligence\Support\CanonicalJson;
use App\Models\TaskEvidence;
use App\Models\WorkLog;

/**
 * Source versions for new V58 action drafts. Store only the digest alongside
 * sanitized snapshot fields: do not copy hidden career/AI/provider payloads.
 */
final class PlanActionDraftSourceFingerprint
{
    public function workLog(WorkLog $log): string
    {
        return $this->hash([
            'kind' => 'work_log',
            'id' => (int) $log->id,
            'plan_id' => (int) $log->plan_id,
            'task_id' => $log->task_id === null ? null : (int) $log->task_id,
            'task_title_snapshot' => $log->task_title_snapshot,
            'worked_on' => $log->worked_on?->toDateString(),
            'actual_minutes' => $log->actual_minutes,
            'progress_delta_percent' => $log->progress_delta_percent,
            'progress_before_percent' => $log->progress_before_percent,
            'progress_after_percent' => $log->progress_after_percent,
            'remaining_minutes_before' => $log->remaining_minutes_before,
            'remaining_minutes_after' => $log->remaining_minutes_after,
            'difficulty' => $log->difficulty,
            'memo' => $log->memo,
            'outcome' => $log->outcome,
        ]);
    }

    public function taskEvidence(TaskEvidence $row): string
    {
        return $this->hash([
            'kind' => 'task_evidence',
            'id' => (int) $row->id,
            'plan_id' => (int) $row->plan_id,
            'task_id' => $row->task_id === null ? null : (int) $row->task_id,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'source' => $row->source->value,
            'type' => $row->type,
            'external_key' => $row->external_key,
            'confidence' => $row->confidence,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
            'metadata' => $row->metadata,
        ]);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', CanonicalJson::encode($payload));
    }
}
