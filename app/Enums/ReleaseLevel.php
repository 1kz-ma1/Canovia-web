<?php

namespace App\Enums;

enum ReleaseLevel: int
{
    case CoreStable = 0;
    case EarlyAccessCore = 1;
    case ProductPreview = 2;
    case BetaExpansion = 3;
    case InternalPreview = 4;

    public function label(): string
    {
        return match ($this) {
            self::CoreStable => 'Core Stable',
            self::EarlyAccessCore => 'Early Access Core',
            self::ProductPreview => 'Product Preview',
            self::BetaExpansion => 'Beta Expansion',
            self::InternalPreview => 'Internal Preview',
        };
    }

    public function isAtLeast(self $minimum): bool
    {
        return $this->value >= $minimum->value;
    }
}
