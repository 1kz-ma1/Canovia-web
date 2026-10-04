<?php

namespace App\Intelligence\Data;

use InvalidArgumentException;

final readonly class DecisionCandidate
{
    public function __construct(
        public string $type,
        public string $reasonCode,
        public string $summary,
        public int $priority,
        public Confidence $confidence,
        public array $reasons = [],
        public array $metadata = [],
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException('Decision candidate priority must be between 0 and 100.');
        }
    }

    public function toDecision(string $inputFingerprint, array $selectionMetadata = []): Decision
    {
        return new Decision(
            type: $this->type,
            reasonCode: $this->reasonCode,
            summary: $this->summary,
            confidence: $this->confidence,
            inputFingerprint: $inputFingerprint,
            reasons: $this->reasons,
            metadata: [
                ...$this->metadata,
                ...$selectionMetadata,
                'selected_priority' => $this->priority,
            ],
        );
    }
}
