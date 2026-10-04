<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutionActivity extends Model
{
    public const STATUSES = [
        'started',
        'completed',
        'interrupted',
        'failed',
        'cancelled',
    ];

    protected $fillable = [
        'user_id',
        'actor_token',
        'provider_key',
        'capability',
        'external_key',
        'type',
        'title',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'metrics',
        'metadata',
        'plan_id',
        'task_id',
        'task_evidence_id',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'metrics' => 'array',
            'metadata' => 'array',
            'linked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function taskEvidence()
    {
        return $this->belongsTo(TaskEvidence::class);
    }
}
