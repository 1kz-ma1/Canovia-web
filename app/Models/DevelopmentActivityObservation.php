<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevelopmentActivityObservation extends Model
{
    public const RESOLUTION_STATUSES = [
        'unlinked',
        'suggested',
        'linked',
        'ignored',
    ];

    protected $fillable = [
        'plan_id',
        'repository_artifact_id',
        'provider',
        'kind',
        'external_key',
        'provider_number',
        'url',
        'title',
        'state',
        'ref',
        'sha',
        'occurred_at',
        'last_observed_at',
        'suggested_task_id',
        'suggestion_confidence',
        'suggestion_basis',
        'resolution_status',
        'resolved_artifact_id',
    ];

    protected function casts(): array
    {
        return [
            'provider_number' => 'integer',
            'occurred_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'suggestion_confidence' => 'float',
            'suggestion_basis' => 'array',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function repositoryArtifact()
    {
        return $this->belongsTo(PlanArtifact::class, 'repository_artifact_id');
    }

    public function suggestedTask()
    {
        return $this->belongsTo(Task::class, 'suggested_task_id');
    }

    public function resolvedArtifact()
    {
        return $this->belongsTo(PlanArtifact::class, 'resolved_artifact_id');
    }
}
