<?php

namespace App\Data;

use App\Models\Plan;
use App\Models\Task;
use Carbon\CarbonImmutable;

final readonly class ProviderExecutionContextData
{
    public function __construct(
        public Plan $plan,
        public Task $task,
        public string $capability,
        public CarbonImmutable $expiresAt,
    ) {}
}
