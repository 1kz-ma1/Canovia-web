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
        'user_id', 'identity_fingerprint', 'provider_key',
        'status', 'linked_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
