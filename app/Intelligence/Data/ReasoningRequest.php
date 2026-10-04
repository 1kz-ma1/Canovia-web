<?php

namespace App\Intelligence\Data;

final readonly class ReasoningRequest
{
    /**
     * @param array<int,DecisionCandidate> $candidates
     */
    public function __construct(
        public StateSnapshot $state,
        public ReadinessAssessment $readiness,
        public array $candidates,
        public Decision $baselineDecision,
        public ?int $userId = null,
        public ?int $planId = null,
    ) {}
}
