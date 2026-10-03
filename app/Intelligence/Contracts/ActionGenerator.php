<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;

interface ActionGenerator
{
    /**
     * @return array<ActionProposal>
     */
    public function generate(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        Decision $decision,
    ): array;
}
