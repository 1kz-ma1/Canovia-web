<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceActionProjection;
use App\Models\IntelligenceDecisionTrace;

final readonly class DevelopmentAdaptiveActionResult
{
    /**
     * @param array<int,ActionProposal> $actions
     */
    public function __construct(
        public DevelopmentIntelligenceResult $intelligence,
        public Decision $decision,
        public array $actions,
        public ?IntelligenceDecisionTrace $decisionTrace = null,
        public ?IntelligenceActionProjection $projection = null,
    ) {}

    public function primaryAction(): ?ActionProposal
    {
        return $this->actions[0] ?? null;
    }
}
