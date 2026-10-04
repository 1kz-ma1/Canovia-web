<?php

namespace App\Intelligence\Data;

use App\Models\IntelligenceStateSnapshot;

final readonly class DevelopmentIntelligenceResult
{
    public function __construct(
        public StateSnapshot $state,
        public ReadinessAssessment $readiness,
        public ?IntelligenceStateSnapshot $persistedSnapshot = null,
    ) {}
}
