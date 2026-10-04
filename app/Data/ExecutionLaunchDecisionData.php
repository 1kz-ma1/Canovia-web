<?php

namespace App\Data;

final readonly class ExecutionLaunchDecisionData
{
    /**
     * @param array<int,mixed> $parameters
     */
    public function __construct(
        public ExecutionProviderDefinitionData $provider,
        public string $routeName,
        public array $parameters,
        public bool $validationExternal = false,
    ) {}
}
