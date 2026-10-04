<?php

namespace App\Data;

use App\Enums\WorkspaceMode;
use App\Enums\WorkspaceModeSource;

final readonly class WorkspaceModeContextData
{
    public function __construct(
        public WorkspaceMode $mode,
        public WorkspaceModeSource $source,
        public ?int $planId = null,
        public ?string $profileKey = null,
        public ?string $routeName = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'source' => $this->source->value,
            'plan_id' => $this->planId,
            'profile_key' => $this->profileKey,
            'route_name' => $this->routeName,
        ];
    }
}
