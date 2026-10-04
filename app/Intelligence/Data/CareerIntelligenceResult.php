<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceStateSnapshot;

final readonly class CareerIntelligenceResult
{
    public function __construct(
        public StateSnapshot $state,
        public ReadinessAssessment $readiness,
        public ?IntelligenceStateSnapshot $persistedSnapshot = null,
    ) {}
}
