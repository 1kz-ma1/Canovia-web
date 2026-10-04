<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceDecisionTrace;

final readonly class DecisionEvaluationResult
{
    public function __construct(
        public ReadinessAssessment $readiness,
        public Decision $decision,
        public IntelligenceDecisionTrace $trace,
    ) {}
}
