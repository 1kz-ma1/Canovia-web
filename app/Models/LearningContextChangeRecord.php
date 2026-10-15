<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LearningContextChangeRecord extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'dedupe_fingerprint', 'source_revision',
        'event_kinds', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'event_kinds' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
