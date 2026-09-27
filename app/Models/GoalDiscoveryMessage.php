<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalDiscoveryMessage extends Model
{
    public const ROLES = ['user', 'assistant'];

    protected $fillable = [
        'goal_context_id',
        'user_id',
        'native_ai_run_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function goalContext()
    {
        return $this->belongsTo(GoalContext::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function nativeAiRun()
    {
        return $this->belongsTo(NativeAiRun::class);
    }
}
