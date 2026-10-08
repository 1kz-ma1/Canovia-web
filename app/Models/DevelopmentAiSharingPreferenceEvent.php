<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevelopmentAiSharingPreferenceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'preference_id', 'actor_user_id', 'event', 'scope', 'expires_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
