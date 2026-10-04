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
            ['overview', 'study', 'development'],
            $registry->publicKeys(),
        );

        $this->assertSame(
            $registry->publicKeys(),
            array_values(array_unique($registry->publicKeys())),
        );

        $this->assertNull($registry->forProfile('career'));
        $this->assertNull($registry->forProfile('creative'));
        $this->assertNull($registry->forProfile('general'));
    }

    public function test_mode_definitions_keep_workspace_semantics_out_of_plan_categories(): void
    {
        $registry = new WorkspaceModeRegistry();

        $overview = $registry->definition(WorkspaceMode::Overview);
        $study = $registry->definition(WorkspaceMode::Study);
        $development = $registry->definition(WorkspaceMode::Development);

        $this->assertSame([], $overview->supportedProfileKeys);
        $this->assertSame(['study'], $study->supportedProfileKeys);
        $this->assertSame(
            ['development'],
            $development->supportedProfileKeys,
        );

        $this->assertContains('study_scope', $study->navigationKeys);
        $this->assertContains('practice', $study->navigationKeys);
        $this->assertContains(
            'release_readiness',
            $development->navigationKeys,
        );
        $this->assertContains('github', $development->navigationKeys);

        $this->assertSame(
            'capture_study_scope',
            $study->emptyStateActionKey,
        );
        $this->assertSame(
            'connect_github',
            $development->emptyStateActionKey,
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
        $this->assertFalse($registry->supportsProfile('career'));
        $this->assertFalse($registry->supportsProfile(''));
    }
}
