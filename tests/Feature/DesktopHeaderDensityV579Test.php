<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopHeaderDensityV579Test extends TestCase
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

    public function test_desktop_header_uses_one_contextual_navigation_row(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee('data-navigation-shell="global"', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace"', false)
            ->assertDontSee('data-workspace-mode-bar', false);

        $this->get(route('workspace.development.top'))->assertOk()
            ->assertSee('data-navigation-shell="workspace"', false)
            ->assertSee('data-workspace-exit', false)
            ->assertSee('data-canovia-nav-key="desktop-workspace-development"', false);
    }

    public function test_compact_header_and_mobile_shell_still_exist(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('gap-3 px-6 py-2.5', $layout);
        $this->assertStringContainsString('mobile-app-header md:hidden', $layout);
        $this->assertStringContainsString("layouts.partials.primary-navigation-desktop", $layout);
    }

}
