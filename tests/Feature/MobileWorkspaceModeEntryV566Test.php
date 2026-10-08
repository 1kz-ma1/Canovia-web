<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileWorkspaceModeEntryV566Test extends TestCase
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

    public function test_mobile_workspace_tabs_replace_global_selector_only_inside_workspace(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace"', false)
            ->assertDontSee('data-workspace-mode-compact', false);
        $this->get(route('workspace.study.top'))->assertOk()
            ->assertSee('data-navigation-shell="workspace"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace-study"', false)
            ->assertSee('data-canovia-nav-key="mobile-workspace-development"', false)
            ->assertSee('data-workspace-exit', false);
    }

}
