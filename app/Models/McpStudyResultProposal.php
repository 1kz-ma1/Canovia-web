<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McpStudyResultProposal extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'plan_id', 'task_id', 'grant_id', 'idempotency_key',
        'payload_hash', 'question_count', 'correct_count',
        'weakness_topics', 'status', 'expires_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'correct_count' => 'integer',
            'weakness_topics' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
