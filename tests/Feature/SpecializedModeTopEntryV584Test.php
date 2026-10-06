<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecializedModeTopEntryV584Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_specialized_mode_entry_routes_are_distinct_from_plan_workspace_urls(): void
    {
        $this->assertSame(
            url('/workspace/mode/study'),
            route('workspace_modes.enter', [
                'workspaceMode' => WorkspaceMode::Study->value,
            ]),
        );
        $this->assertSame(
            url('/workspace/study'),
            route('workspace.study.index'),
        );

        $this->assertSame(
            url('/workspace/mode/development'),
            route('workspace_modes.enter', [
                'workspaceMode' => WorkspaceMode::Development->value,
            ]),
        );
        $this->assertSame(
            url('/workspace/development'),
            route('workspace.development.index'),
        );
    }

    public function test_explicit_specialized_mode_entry_redirects_to_mode_top(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => WorkspaceMode::Study->value,
            ]))
            ->assertRedirect(route('workspace.study.top'));

        $this->actingAs($user)
            ->get(route('workspace_modes.enter', [
                'workspaceMode' => WorkspaceMode::Development->value,
            ]))
            ->assertRedirect(route('workspace.development.top'));

        $this->assertNull(
            $user->fresh()->workspace_mode_preference,
        );
    }

    public function test_direct_plan_workspace_routes_remain_valid_compatibility_entries(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee('data-study-workspace', false)
            ->assertSee('data-study-workspace-no-plan', false);

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertSee('data-development-workspace', false)
            ->assertSee('data-development-workspace-no-plan', false);
    }

    public function test_unknown_ephemeral_mode_entry_remains_not_found(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/workspace/mode/unknown')
            ->assertNotFound();
    }
}
