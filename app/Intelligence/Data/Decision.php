<?php

namespace App\Intelligence\Data;

final readonly class Decision
{
    public function __construct(
        public string $type,
        public string $reasonCode,
        public string $summary,
        public Confidence $confidence,
        public string $inputFingerprint,
        public array $reasons = [],
        public array $metadata = [],
    ) {}
}
