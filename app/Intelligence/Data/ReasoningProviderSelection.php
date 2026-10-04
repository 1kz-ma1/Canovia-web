<?php

namespace App\Intelligence\Data;

final readonly class ReasoningProviderSelection
{
    public function __construct(
        public string $selectedType,
        public Confidence $confidence,
        public string $provider,
        public ?string $model = null,
        public ?int $nativeAiRunId = null,
        public array $reasonCodes = [],
        public array $metadata = [],
    ) {}
}
