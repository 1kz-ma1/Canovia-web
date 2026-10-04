<?php

namespace App\Data;

final readonly class ExecutionSetupData
{
    /**
     * @param array<int,ExecutionProviderDefinitionData> $providers
     */
    public function __construct(
        public string $capability,
        public array $providers,
        public ?ExecutionProviderDefinitionData $selectedProvider,
        public bool $hasExplicitPreference,
    ) {}

    public function hasChoice(): bool
    {
        return count($this->providers) > 1;
    }

    public function needsSetup(): bool
    {
        return $this->hasChoice() && ! $this->hasExplicitPreference;
    }
}
