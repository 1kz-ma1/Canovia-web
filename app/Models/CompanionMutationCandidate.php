<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanionMutationCandidate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_APPLIED = 'applied';

    public const TYPES = [
        'create_task',
        'update_task',
        'update_plan',
        'record_goal_fact',
        'create_future_memo',
        'create_inbox_item',
    ];

    protected $fillable = [
        'companion_thread_id',
        'companion_message_id',
        'user_id',
        'plan_id',
        'task_id',
        'candidate_key',
        'apply_request_id',
        'type',
        'status',
        'title',
        'summary',
        'payload',
        'metadata',
        'applied_target_type',
        'applied_target_id',
        'reviewed_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'applied_target_id' => 'integer',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function thread()
    {
        return $this->belongsTo(CompanionThread::class, 'companion_thread_id');
    }

    public function message()
    {
        return $this->belongsTo(CompanionMessage::class, 'companion_message_id');
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
}
