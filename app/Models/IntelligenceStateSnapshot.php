<?php

namespace App\Models;

use App\Intelligence\Enums\IntelligenceDomain;
use Illuminate\Database\Eloquent\Model;

class IntelligenceStateSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'domain',
        'scope_type',
        'scope_id',
        'state_fingerprint',
        'state_reference',
        'captured_at',
        'metrics',
        'facts',
        'evidence_references',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'domain' => IntelligenceDomain::class,
            'captured_at' => 'datetime',
            'metrics' => 'array',
            'facts' => 'array',
            'evidence_references' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
