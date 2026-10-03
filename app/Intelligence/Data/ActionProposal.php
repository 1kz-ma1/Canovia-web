<?php

namespace App\Intelligence\Data;

final readonly class ActionProposal
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $intent,
        public Confidence $confidence,
        public ?int $estimatedMinutes = null,
        public array $successSignals = [],
        public array $metadata = [],
    ) {}
}
