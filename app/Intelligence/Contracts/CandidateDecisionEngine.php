<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\DecisionCandidate;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;

interface CandidateDecisionEngine extends DecisionEngine
{
    /**
     * @return array<int,DecisionCandidate>
     */
    public function candidates(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        array $context = [],
    ): array;
}
