<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebRealDeviceFinishV5198Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_mobile_shell_has_safe_area_and_bottom_dock_clearance_contract(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '--canovia-mobile-dock-clearance: calc(7rem + env(safe-area-inset-bottom));',
            $css,
        );
        $this->assertStringContainsString(
            'scroll-padding-bottom: var(--canovia-mobile-dock-clearance);',
            $css,
        );
        $this->assertStringContainsString(
            'right: max(0.75rem, env(safe-area-inset-right));',
            $css,
        );
        $this->assertStringContainsString(
            'left: max(0.75rem, env(safe-area-inset-left));',
            $css,
        );
        $this->assertStringContainsString(
            'padding-right: max(1rem, env(safe-area-inset-right));',
            $css,
        );
        $this->assertStringContainsString(
            'padding-left: max(1rem, env(safe-area-inset-left));',
            $css,
        );
    }

    public function test_virtual_keyboard_hides_fixed_mobile_dock(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'html[data-canovia-keyboard="open"] .mobile-tabbar',
            $css,
        );
        $this->assertStringContainsString(
            "document.documentElement.dataset.canoviaKeyboard = keyboardOpen ? 'open' : 'closed';",
            $js,
        );
        $this->assertStringContainsString(
            "visualViewport?.addEventListener('resize', syncVirtualKeyboardState",
            $js,
        );
        $this->assertStringContainsString(
            "document.addEventListener('focusin'",
            $js,
        );
    }

    public function test_primary_horizontal_rails_share_ios_touch_contract(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach ([
            '.pk-v395-guidance-track',
            '.pk-v18-focus-track',
            '.canovia-action-home-signals-track',
            '.execution-mode-switcher',
            '.execution-plan-switcher',
            '.execution-recommendation-track',
            '.canovia-constellation-star-rail',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $this->assertStringContainsString('-webkit-overflow-scrolling: touch;', $css);
        $this->assertStringContainsString('overscroll-behavior-inline: contain;', $css);
        $this->assertStringContainsString('touch-action: pan-x pan-y;', $css);
        $this->assertStringContainsString('scroll-snap-stop: always;', $css);
    }

    public function test_constellation_plan_swipe_resets_on_touch_cancel_and_multitouch(): void
    {
        $js = file_get_contents(resource_path('js/constellation-roadmap.mjs'));

        $this->assertStringContainsString(
            "if ((event.touches?.length ?? 0) !== 1)",
            $js,
        );
        $this->assertStringContainsString(
            "zone.addEventListener('touchcancel', reset",
            $js,
        );
    }

    public function test_mobile_text_surfaces_wrap_long_task_copy_instead_of_clipping(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.execution-recommendation-next', $css);
        $this->assertStringContainsString('.canovia-star-task-item strong', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $css);
        $this->assertStringContainsString('text-wrap: pretty;', $css);
    }
}
