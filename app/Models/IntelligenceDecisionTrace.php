<?php

namespace App\Models;

use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Enums\ReadinessLevel;
use Illuminate\Database\Eloquent\Model;

class IntelligenceDecisionTrace extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'intelligence_state_snapshot_id',
        'domain',
        'scope_type',
        'scope_id',
        'state_reference',
        'state_fingerprint',
        'readiness_fingerprint',
        'readiness_score',
        'readiness_level',
        'readiness_confidence',
        'readiness_components',
        'readiness_gaps',
        'decision_reference',
        'decision_type',
        'reason_code',
        'decision_summary',
        'decision_confidence',
        'input_fingerprint',
        'decision_reasons',
        'decision_metadata',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'domain' => IntelligenceDomain::class,
            'readiness_level' => ReadinessLevel::class,
            'readiness_score' => 'integer',
            'readiness_confidence' => 'float',
            'decision_confidence' => 'float',
            'readiness_components' => 'array',
            'readiness_gaps' => 'array',
            'decision_reasons' => 'array',
            'decision_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    public function stateSnapshot()
    {
        return $this->belongsTo(
            IntelligenceStateSnapshot::class,
            'intelligence_state_snapshot_id',
        );
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
