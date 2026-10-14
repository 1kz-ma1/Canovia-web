<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intentionally no controller or OAuth callback can create these records yet.
 * Future dual-auth verified account linking is a separate security gate.
 */
final class McpLinkedSubject extends Model
{
    public const STATUS_LINKED = 'linked';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id', 'identity_fingerprint', 'active_identity_fingerprint', 'provider_key',
        'status', 'linked_at', 'revoked_at',
    ];

    protected static function booted(): void
    {
        // Every ORM write reserves the fingerprint ONLY for live links.
        // Revoke releases it but retains historical rows and foreign keys.
        static::saving(static function (self $subject): void {
            $subject->active_identity_fingerprint = $subject->status === self::STATUS_LINKED
                && $subject->revoked_at === null
                    ? $subject->identity_fingerprint : null;
        });
    }

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
