<?php

namespace Tests\Unit;

use App\Enums\ExecutionProviderKind;
use App\Execution\ExecutionCapability;
use App\Services\ExecutionProviderRegistry;
use PHPUnit\Framework\TestCase;

class ExecutionProviderRegistryV556Test extends TestCase
{
    public function test_registry_exposes_native_and_external_providers_by_capability(): void
    {
        $registry = new ExecutionProviderRegistry();

        $development = $registry->forCapability(
            ExecutionCapability::CODING_REPOSITORY,
        );

        $this->assertSame(
            ['canovia.development', 'github'],
            $development->pluck('key')->all(),
        );
        $this->assertSame(
            ExecutionProviderKind::Native,
            $registry->find('canovia.development')?->kind,
        );
        $this->assertSame(
            ExecutionProviderKind::External,
            $registry->find('github')?->kind,
        );
    }

    public function test_registry_keeps_capabilities_semantic_and_provider_specific(): void
    {
        $registry = new ExecutionProviderRegistry();

        $this->assertSame(
            ['canovia.study.practice'],
            $registry
                ->forCapability(ExecutionCapability::STUDY_PRACTICE)
                ->pluck('key')
                ->all(),
        );
        $this->assertSame(
            ['canovia.study.recall'],
            $registry
                ->forCapability(ExecutionCapability::STUDY_RECALL)
                ->pluck('key')
                ->all(),
        );
        $this->assertSame(
            ['canovia.general'],
            $registry
                ->forCapability(ExecutionCapability::GENERAL_TASK)
                ->pluck('key')
                ->all(),
        );
    }
}
