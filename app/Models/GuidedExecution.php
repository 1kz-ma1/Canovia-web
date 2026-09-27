<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuidedExecution extends Model
{
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const OUTCOME_RATINGS = [
        'as_expected',
        'partly',
        'not_as_expected',
        'learning_only',
    ];

    protected $fillable = [
        'user_id',
        'actor_token',
        'plan_id',
        'task_id',
        'task_evidence_id',
        'status',
        'prepare_request_id',
        'reflection_request_id',
        'intent',
        'focus_points',
        'observation_points',
        'success_signal',
        'outcome_rating',
        'actual_outcome',
        'observations',
        'discoveries',
        'next_adjustment',
        'prepared_at',
        'reflected_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'focus_points' => 'array',
            'observation_points' => 'array',
            'prepared_at' => 'datetime',
            'reflected_at' => 'datetime',
            'metadata' => 'array',
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

    public function evidence()
    {
        return $this->belongsTo(TaskEvidence::class, 'task_evidence_id');
    }
}
