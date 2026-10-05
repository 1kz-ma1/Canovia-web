<?php

namespace Tests\Unit;

use App\Enums\WorkspaceMode;
use App\Services\WorkspaceModeRegistry;
use PHPUnit\Framework\TestCase;

class WorkspaceModeRegistryV540Test extends TestCase
{
    public function test_public_modes_are_ordered_and_future_mode_safe(): void
    {
        $registry = new WorkspaceModeRegistry();

        $this->assertSame(
            ['overview', 'study', 'development', 'career'],
            $registry->publicKeys(),
        );

        $this->assertSame(
            $registry->publicKeys(),
            array_values(array_unique($registry->publicKeys())),
        );

        $this->assertSame(WorkspaceMode::Career, $registry->forProfile('career')?->mode);
        $this->assertNull($registry->forProfile('creative'));
        $this->assertNull($registry->forProfile('general'));
    }

    public function test_mode_definitions_keep_workspace_semantics_out_of_plan_categories(): void
    {
        $registry = new WorkspaceModeRegistry();

        $overview = $registry->definition(WorkspaceMode::Overview);
        $study = $registry->definition(WorkspaceMode::Study);
        $development = $registry->definition(WorkspaceMode::Development);
        $career = $registry->definition(WorkspaceMode::Career);

        $this->assertSame([], $overview->supportedProfileKeys);
        $this->assertSame(['study'], $study->supportedProfileKeys);
        $this->assertSame(
            ['development'],
            $development->supportedProfileKeys,
        );
        $this->assertSame(['career'], $career->supportedProfileKeys);

        $this->assertContains('study_scope', $study->navigationKeys);
        $this->assertContains('practice', $study->navigationKeys);
        $this->assertContains(
            'release_readiness',
            $development->navigationKeys,
        );
        $this->assertContains('github', $development->navigationKeys);
        $this->assertContains('pipeline', $career->navigationKeys);
        $this->assertContains('interviews', $career->navigationKeys);

        $this->assertSame(
            'create_plan',
            $study->emptyStateActionKey,
        );
        $this->assertSame(
            'connect_github',
            $development->emptyStateActionKey,
        );
        $this->assertSame(
            'capture_career_signal',
            $career->emptyStateActionKey,
        );

        $this->assertSame('資格学習', $study->suggestedPlanCategory);
        $this->assertSame('個人開発', $development->suggestedPlanCategory);
        $this->assertSame('就活・キャリア', $career->suggestedPlanCategory);
        $this->assertSame([], $overview->onboardingSteps);
        $this->assertSame(
            ['create_plan'],
            array_column($study->onboardingSteps, 'key'),
        );
        $this->assertSame(
            ['create_plan', 'connect_github_evidence'],
            array_column($development->onboardingSteps, 'key'),
        );
        $this->assertSame(
            ['create_plan', 'capture_career_signal'],
            array_column($career->onboardingSteps, 'key'),
        );
    }

    public function test_registry_maps_only_profiles_with_dedicated_workspace_modes(): void
    {
        $registry = new WorkspaceModeRegistry();

        $this->assertSame(
            WorkspaceMode::Study,
            $registry->forProfile('study')?->mode,
        );
        $this->assertSame(
            WorkspaceMode::Development,
            $registry->forProfile('development')?->mode,
        );
        $this->assertSame(
            WorkspaceMode::Career,
            $registry->forProfile('career')?->mode,
        );
        $this->assertTrue($registry->supportsProfile('career'));
        $this->assertFalse($registry->supportsProfile(''));
    }
}
