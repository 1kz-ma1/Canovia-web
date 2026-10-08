<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A future explicit OAuth consent. No service or route currently issues an
 * active grant, and a ChatGPT sharing preference cannot become a grant.
 */
final class McpDelegatedGrant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'subject_link_id', 'user_id', 'plan_id',
        'client_resource_fingerprint', 'scope', 'status',
        'consented_at', 'expires_at', 'revoked_at',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
