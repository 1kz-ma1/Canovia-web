<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProviderConnection extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'provider_key',
        'public_id',
        'secret_ciphertext',
        'label',
        'status',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->revoked_at === null;
    }
}
