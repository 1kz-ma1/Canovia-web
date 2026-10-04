<?php

namespace App\Models;

use App\Intelligence\Enums\IntelligenceDomain;
use Illuminate\Database\Eloquent\Model;

class IntelligenceActionProjection extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'user_id',
        'plan_id',
        'intelligence_decision_trace_id',
        'projected_task_id',
        'domain',
        'scope_type',
        'scope_id',
        'state_fingerprint',
        'action_fingerprint',
        'action_reference',
        'kind',
        'title',
        'intent',
        'confidence',
        'estimated_minutes',
        'success_signals',
        'metadata',
        'status',
        'superseded_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'domain' => IntelligenceDomain::class,
            'confidence' => 'float',
            'estimated_minutes' => 'integer',
            'success_signals' => 'array',
            'metadata' => 'array',
            'superseded_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decisionTrace()
    {
        return $this->belongsTo(
            IntelligenceDecisionTrace::class,
            'intelligence_decision_trace_id',
        );
    }

    public function projectedTask()
    {
        return $this->belongsTo(Task::class, 'projected_task_id');
    }
}
