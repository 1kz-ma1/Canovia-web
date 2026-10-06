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

    public function test_desktop_header_keeps_workspace_switcher_inside_primary_row(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-workspace-mode-inline="1"', false)
            ->assertSee('data-workspace-mode-compact="1"', false);

        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);

        $this->assertSame(
            1,
            $xpath->query(
                '//header[contains(concat(" ", normalize-space(@class), " "), " desktop-app-header ")]'
                .'/div[contains(concat(" ", normalize-space(@class), " "), " mx-auto ")]'
                .'//*[@data-workspace-mode-inline="1"]'
            )->length,
        );

        $this->assertSame(
            0,
            $xpath->query(
                '//header[contains(concat(" ", normalize-space(@class), " "), " desktop-app-header ")]'
                .'>div[@data-workspace-mode-bar]'
            )->length,
        );

        $this->assertSame(
            1,
            $xpath->query(
                '//header[contains(concat(" ", normalize-space(@class), " "), " mobile-app-header ")]'
                .'//*[@data-workspace-mode-compact="1"]'
            )->length,
        );
    }

    public function test_desktop_header_density_is_compact_without_changing_mobile_touch_shell(): void
    {
        $layout = file_get_contents(
            resource_path('views/layouts/app.blade.php'),
        );
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'gap-3 px-6 py-2.5',
            $layout,
        );
        $this->assertStringContainsString(
            "'workspaceModeInline' => true",
            $layout,
        );
        $this->assertStringContainsString(
            '.workspace-mode-bar.is-inline {',
            $css,
        );
        $this->assertStringContainsString(
            'min-height: 1.9rem;',
            $css,
        );
        $this->assertStringContainsString(
            'width: 19.5rem;',
            $css,
        );
        $this->assertStringContainsString(
            '.desktop-app-header .nav-link {',
            $css,
        );

        // Mobile keeps the existing compact/touch implementation.
        $this->assertStringContainsString(
            "'workspaceModeCompact' => true",
            $layout,
        );
        $this->assertStringContainsString(
            '@media (max-width: 767px)',
            $css,
        );
        $this->assertStringContainsString(
            '.workspace-mode-option {',
            $css,
        );
    }
}
