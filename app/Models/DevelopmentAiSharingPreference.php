<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevelopmentAiSharingPreference extends Model
{
    public const PROVIDER_CHATGPT = 'chatgpt';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id', 'plan_id', 'provider_key', 'scope',
        'status', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Preparation is not a real OAuth grant and must never authenticate a
     * delegated request, irrespective of this state.
     */
    public function isPrepared(): bool
    {
        return $this->status === self::STATUS_PREPARED
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
