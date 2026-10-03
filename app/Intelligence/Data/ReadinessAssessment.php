<?php

namespace App\Intelligence\Data;

use App\Intelligence\Enums\ReadinessLevel;
use InvalidArgumentException;

final readonly class ReadinessAssessment
{
    public function __construct(
        public ?int $score,
        public ReadinessLevel $level,
        public Confidence $confidence,
        public array $components = [],
        public array $gaps = [],
        public array $metadata = [],
    ) {
        if ($score !== null && ($score < 0 || $score > 100)) {
            throw new InvalidArgumentException('Readiness score must be between 0 and 100.');
        }
    }
}
