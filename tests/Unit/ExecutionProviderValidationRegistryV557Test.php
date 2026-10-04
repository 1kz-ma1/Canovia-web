<?php

namespace Tests\Unit;

use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;
use App\Services\ExecutionLaunchResolver;
use App\Services\ExecutionProviderRegistry;
use PHPUnit\Framework\TestCase;

class ExecutionProviderValidationRegistryV557Test extends TestCase
{
    public function test_validation_provider_is_opt_in_and_capability_scoped(): void
    {
        $default = new ExecutionProviderRegistry();

        $this->assertNull(
            $default->find(
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            ),
        );

        $validation = new ExecutionProviderRegistry(true);
        $provider = $validation->find(
            ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
        );

        $this->assertNotNull($provider);
        $this->assertSame(ExecutionProviderKind::External, $provider->kind);
        $this->assertSame(
            [ExecutionCapability::STUDY_PRACTICE],
            $provider->capabilities,
        );
        $this->assertSame(
            ['canovia.study.practice', 'validation.study.practice.external'],
            $validation
                ->forCapability(ExecutionCapability::STUDY_PRACTICE)
                ->pluck('key')
                ->all(),
        );
    }
}
