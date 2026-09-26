<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalContextFact extends Model
{
    public const TYPES = [
        'target',
        'current_state',
        'signal',
        'constraint',
        'driver',
        'unknown',
    ];

    public const SOURCES = [
        'user_answer',
        'inbox',
        'evidence',
        'native_ai',
        'system',
    ];

    public const STATES = [
        'confirmed',
        'candidate',
        'unknown',
        'superseded',
    ];

    protected $fillable = [
        'goal_context_id',
        'type',
        'key',
        'label',
        'value_json',
        'source',
        'state',
        'confidence',
        'importance',
        'observed_at',
        'confirmed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
            'confidence' => 'float',
            'importance' => 'integer',
            'observed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function goalContext()
    {
        return $this->belongsTo(GoalContext::class);
    }

    public function isConfirmed(): bool
    {
        return $this->state === 'confirmed';
    }
}
