<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalContext extends Model
{
    public const STATUSES = ['discovery', 'active', 'archived'];
    public const READINESS_STATES = ['low', 'medium', 'high'];

    protected $fillable = [
        'user_id',
        'actor_token',
        'plan_id',
        'desired_state',
        'current_state_summary',
        'readiness_score',
        'readiness_state',
        'status',
        'last_assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'readiness_score' => 'integer',
            'last_assessed_at' => 'datetime',
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

    public function facts()
    {
        return $this->hasMany(GoalContextFact::class);
    }
}
