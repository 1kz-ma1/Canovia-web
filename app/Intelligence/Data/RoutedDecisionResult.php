<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceReasoningRun;

final readonly class RoutedDecisionResult
{
    public function __construct(
        public Decision $decision,
        public Decision $baselineDecision,
        public IntelligenceReasoningRun $reasoningRun,
        public bool $agreesWithBaseline,
        public bool $usedFallback,
    ) {}
}
