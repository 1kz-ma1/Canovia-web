<?php

namespace Tests\Feature;

use Tests\TestCase;

class GlobalHomeResetFocusV481Test extends TestCase
{
    public function test_global_home_and_canovia_breadcrumb_both_use_hard_reset_semantics(): void
    {
        $view = file_get_contents(
            resource_path('views/map/partials/extensions/global-navigation.blade.php')
        );

        $this->assertGreaterThanOrEqual(2, substr_count($view, 'data-map-global-home'));
        $this->assertStringContainsString('aria-label="Canovia全体へ戻る"', $view);
        $this->assertStringContainsString('>Canovia</a>', $view);
    }

    public function test_global_home_reset_is_consumed_again_on_l0_mount_before_focus_restore(): void
    {
        $runtime = file_get_contents(resource_path('js/living-map.mjs'));

        $this->assertStringContainsString('GLOBAL_HOME_RESET_KEY', $runtime);
        $this->assertStringContainsString('requestGlobalHomeReset(windowRef);', $runtime);
        $this->assertStringContainsString('const forceGlobalHomeReset = consumeGlobalHomeReset(', $runtime);
        $this->assertStringContainsString("page.dataset.mapLevel || ''", $runtime);
        $this->assertStringContainsString('const initialFocusId = forceGlobalHomeReset ? null : focusIdFromLocation(windowRef);', $runtime);
        $this->assertStringContainsString('const initialDockId = forceGlobalHomeReset ? null : dockIdFromLocation(windowRef);', $runtime);
    }

    public function test_instant_navigation_cache_does_not_preserve_map_runtime_mount_guards(): void
    {
        $runtime = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString('stripInstantRuntimeTransientState(page.cloneNode(true))', $runtime);
        $this->assertStringContainsString("'data-map-focus-initialized'", $runtime);
        $this->assertStringContainsString("'data-map-pages-initialized'", $runtime);
        $this->assertStringContainsString("'data-map-data-layers-initialized'", $runtime);
        $this->assertStringContainsString("'data-map-semantic-transition-marked'", $runtime);
    }

    public function test_global_home_runtime_resets_selection_dock_view_and_focus_history_before_navigation(): void
    {
        $runtime = file_get_contents(resource_path('js/living-map.mjs'));

        $this->assertStringContainsString('const resetGlobalHomeContext = () => {', $runtime);
        $this->assertStringContainsString('clearSpatialDock();', $runtime);
        $this->assertStringContainsString('clearFocus();', $runtime);
        $this->assertStringContainsString('resetMapView({ animate: false });', $runtime);
        $this->assertStringContainsString('focusHistoryDepth = 0;', $runtime);
        $this->assertStringContainsString('mapGlobalHomeHistoryState(windowRef.history.state || {})', $runtime);
        $this->assertStringContainsString("event.target.closest?.('a[data-map-global-home]')", $runtime);
        $this->assertStringContainsString("link?.closest?.('[data-map-global-home]')", $runtime);
    }
}
