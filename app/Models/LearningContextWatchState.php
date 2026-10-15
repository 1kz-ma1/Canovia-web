<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class LearningContextWatchState extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'status', 'source_revision',
        'encrypted_baseline', 'consented_at',
    ];

    protected $hidden = ['encrypted_baseline'];

    protected function casts(): array
    {
        return [
            'encrypted_baseline' => 'encrypted:array',
            'consented_at' => 'datetime',
        ];
    }
}
