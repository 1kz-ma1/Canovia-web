<?php

namespace App\Intelligence\Data;

use App\Intelligence\Enums\IntelligenceDomain;
use DateTimeImmutable;

final readonly class StateSnapshot
{
    public function __construct(
        public IntelligenceDomain $domain,
        public string $scopeType,
        public string|int|null $scopeId,
        public DateTimeImmutable $capturedAt,
        public array $metrics = [],
        public array $facts = [],
        public array $evidenceReferences = [],
    ) {}
}
