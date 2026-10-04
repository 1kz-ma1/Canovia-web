<?php

namespace App\Data;

final readonly class ExecutionResolutionData
{
    public const SOURCE_PLAN_PREFERENCE = 'plan_preference';
    public const SOURCE_NATIVE_DEFAULT = 'native_default';
    public const SOURCE_CURRENT_FALLBACK = 'current_fallback';

    public function __construct(
        public string $capability,
        public ?ExecutionProviderDefinitionData $provider,
        public string $source,
    ) {}

    public function usesCurrentFallback(): bool
    {
        return $this->provider === null;
    }
}
