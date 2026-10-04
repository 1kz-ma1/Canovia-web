<?php

namespace App\Data;

use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;

final readonly class ExecutionProviderDefinitionData
{
    /**
     * @param array<int,string> $capabilities
     */
    public function __construct(
        public string $key,
        public string $name,
        public ExecutionProviderKind $kind,
        public array $capabilities,
        public bool $enabled = true,
        public ?string $launchType = null,
        public ?string $launchTarget = null,
    ) {}

    public function supports(string $capability): bool
    {
        return in_array(
            ExecutionCapability::normalize($capability),
            $this->capabilities,
            true,
        );
    }

    public function isNative(): bool
    {
        return $this->kind === ExecutionProviderKind::Native;
    }
}
