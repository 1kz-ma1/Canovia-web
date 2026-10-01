<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardDocumentFoundationV501Test extends TestCase
{
    public function test_dashboard_document_runtime_is_independent_from_map_runtime(): void
    {
        $dashboardRuntime = file_get_contents(resource_path('js/dashboard-document.mjs'));
        $mapRuntime = file_get_contents(resource_path('js/living-map.mjs'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('export function documentFitScale', $dashboardRuntime);
        $this->assertStringContainsString('export function mountDashboardDocuments', $dashboardRuntime);
        $this->assertStringContainsString("dashboardDocumentOwner === 'map'", $dashboardRuntime);

        $this->assertStringContainsString(
            "from './dashboard-document.mjs'",
            $mapRuntime,
        );
        $this->assertStringNotContainsString(
            'export function documentFitScale({',
            $mapRuntime,
        );

        $this->assertStringContainsString(
            "import { mountDashboardDocuments } from './dashboard-document.mjs';",
            $app,
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($app, 'mountDashboardDocuments();'),
        );

        $mountPosition = strpos(
            $app,
            "document.addEventListener('DOMContentLoaded', () => {\n    mountDashboardDocuments();",
        );
        $focusModePosition = strpos(
            $app,
            "if (document.body?.dataset.focusMode === '1') return;",
        );

        $this->assertNotFalse($mountPosition);
        $this->assertNotFalse($focusModePosition);
        $this->assertLessThan($focusModePosition, $mountPosition);

        $this->assertStringContainsString(
            'activeDashboardDocuments',
            $dashboardRuntime,
        );
        $this->assertStringContainsString(
            'if (!state.root?.isConnected) state.destroy?.();',
            $dashboardRuntime,
        );
    }

    public function test_shared_dashboard_document_surface_has_fixed_chrome_camera_and_canvas_contract(): void
    {
        $component = file_get_contents(
            resource_path('views/components/dashboard-document.blade.php'),
        );
        $region = file_get_contents(
            resource_path('views/components/dashboard-document-region.blade.php'),
        );
        $controls = file_get_contents(
            resource_path('views/dashboard/partials/document-camera-controls.blade.php'),
        );
        $css = file_get_contents(resource_path('css/dashboard-document.css'));
        $appCss = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-dashboard-document', $component);
        $this->assertStringContainsString('data-dashboard-document-chrome', $component);
        $this->assertStringContainsString('data-dashboard-document-scroll', $component);
        $this->assertStringContainsString('data-dashboard-document-stage', $component);
        $this->assertStringContainsString('data-dashboard-document-canvas', $component);

        $this->assertStringContainsString('data-dashboard-document-region', $region);
        $this->assertStringContainsString('--dashboard-region-span', $region);
        $this->assertStringContainsString('--dashboard-region-rows', $region);
        $this->assertStringContainsString('data-dashboard-region-emphasis', $region);

        $this->assertStringContainsString('data-dashboard-document-camera', $controls);
        $this->assertStringContainsString('data-dashboard-document-zoom-out', $controls);
        $this->assertStringContainsString('data-dashboard-document-fit', $controls);
        $this->assertStringContainsString('data-dashboard-document-zoom-in', $controls);
        $this->assertStringContainsString('data-dashboard-document-zoom-label', $controls);

        $this->assertStringContainsString('canovia-dashboard-document-canvas', $css);
        $this->assertStringContainsString('canovia-dashboard-document-grid', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(12', $css);
        $this->assertStringContainsString('--dashboard-document-natural-width', $css);
        $this->assertStringContainsString("@import './dashboard-document.css';", $appCss);
    }

    public function test_existing_map_documents_publish_generic_contract_without_double_mounting(): void
    {
        $plan = file_get_contents(
            resource_path('views/map/partials/plan-workspace-palette.blade.php'),
        );
        $detail = file_get_contents(
            resource_path('views/map/partials/detail-palette.blade.php'),
        );
        $legacyControls = file_get_contents(
            resource_path('views/map/partials/document-camera-controls.blade.php'),
        );

        foreach ([$plan, $detail] as $blade) {
            $this->assertStringContainsString('data-dashboard-document', $blade);
            $this->assertStringContainsString('data-dashboard-document-owner="map"', $blade);
            $this->assertStringContainsString('data-dashboard-document-scroll', $blade);
            $this->assertStringContainsString('data-dashboard-document-stage', $blade);
            $this->assertStringContainsString('data-dashboard-document-canvas', $blade);
        }

        $this->assertStringContainsString(
            "dashboard.partials.document-camera-controls",
            $legacyControls,
        );
    }
}
