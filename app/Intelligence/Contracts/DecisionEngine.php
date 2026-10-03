<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;

interface DecisionEngine
{
    public function decide(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        array $context = [],
    ): Decision;
}
