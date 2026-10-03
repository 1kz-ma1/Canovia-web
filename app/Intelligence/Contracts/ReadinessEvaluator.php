<?php

namespace App\Intelligence\Contracts;

use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;

interface ReadinessEvaluator
{
    public function evaluate(StateSnapshot $state): ReadinessAssessment;
}
