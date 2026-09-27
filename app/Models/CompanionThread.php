<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanionThread extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'plan_id',
        'task_id',
        'status',
        'title',
        'context_scope',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'context_scope' => 'array',
            'last_message_at' => 'datetime',
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

    public function messages()
    {
        return $this->hasMany(CompanionMessage::class)->orderBy('id');
    }

    public function mutationCandidates()
    {
        return $this->hasMany(CompanionMutationCandidate::class)->latest('id');
    }
}
