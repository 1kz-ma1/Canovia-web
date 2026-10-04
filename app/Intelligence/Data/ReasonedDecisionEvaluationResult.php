<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceDecisionTrace;
use App\Models\IntelligenceReasoningRun;

final readonly class ReasonedDecisionEvaluationResult
{
    public function __construct(
        public ReadinessAssessment $readiness,
        public Decision $baselineDecision,
        public Decision $decision,
        public IntelligenceDecisionTrace $trace,
        public IntelligenceReasoningRun $reasoningRun,
    ) {}
}
