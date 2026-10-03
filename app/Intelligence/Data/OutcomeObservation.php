<?php

namespace App\Intelligence\Data;

use DateTimeImmutable;

final readonly class OutcomeObservation
{
    public function __construct(
        public string $status,
        public DateTimeImmutable $observedAt,
        public Confidence $confidence,
        public array $metrics = [],
        public array $facts = [],
        public array $evidenceReferences = [],
    ) {}
}
