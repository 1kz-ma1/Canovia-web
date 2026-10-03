<?php

namespace App\Intelligence\Data;

use InvalidArgumentException;

final readonly class Confidence
{
    public function __construct(
        public float $value,
    ) {
        if (! is_finite($value) || $value < 0 || $value > 1) {
            throw new InvalidArgumentException('Confidence must be between 0 and 1.');
        }
    }

    public static function deterministic(): self
    {
        return new self(1.0);
    }

    public function percent(): int
    {
        return (int) round($this->value * 100);
    }
}
