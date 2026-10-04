<?php

namespace App\Models;

use App\Intelligence\Enums\IntelligenceDomain;
use Illuminate\Database\Eloquent\Model;

class IntelligenceReasoningRun extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'intelligence_decision_trace_id',
        'native_ai_run_id',
        'domain',
        'scope_type',
        'scope_id',
        'state_reference',
        'readiness_fingerprint',
        'input_fingerprint',
        'request_fingerprint',
        'mode_requested',
        'route_selected',
        'provider',
        'model',
        'status',
        'baseline_decision_type',
        'selected_decision_type',
        'baseline_confidence',
        'selected_confidence',
        'agrees_with_baseline',
        'used_fallback',
        'fallback_reason',
        'candidate_count',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'domain' => IntelligenceDomain::class,
            'baseline_confidence' => 'float',
            'selected_confidence' => 'float',
            'agrees_with_baseline' => 'boolean',
            'used_fallback' => 'boolean',
            'candidate_count' => 'integer',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:8',
            'metadata' => 'array',
        ];
    }

    public function decisionTrace()
    {
        return $this->belongsTo(
            IntelligenceDecisionTrace::class,
            'intelligence_decision_trace_id',
        );
    }

    public function nativeAiRun()
    {
        return $this->belongsTo(NativeAiRun::class);
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
