<?php

namespace App\Intelligence\Data;

use App\Enums\EvidenceSource;
use DateTimeImmutable;

final readonly class EvidenceObservation
{
    public function __construct(
        public string $reference,
        public EvidenceSource $source,
        public string $type,
        public DateTimeImmutable $occurredAt,
        public Confidence $confidence,
        public array $facts = [],
        public array $references = [],
    ) {}
}
