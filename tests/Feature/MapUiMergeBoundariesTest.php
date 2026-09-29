<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapUiMergeBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_map_index_is_a_composition_shell_with_owned_extension_boundaries(): void
    {
        $source = file_get_contents(resource_path('views/map/index.blade.php'));

        foreach ([
            "map.partials.topbar",
            "map.partials.extensions.global-navigation",
            "map.partials.extensions.surface-controls",
            "map.partials.runtime-status",
            "map.partials.workspace",
            "map.partials.surface-templates",
            "map.partials.map-note",
        ] as $partial) {
            $this->assertStringContainsString("@include('".$partial."')", $source);
        }

        $this->assertStringNotContainsString('data-map-scene', $source);
        $this->assertStringNotContainsString('data-map-context-surface', $source);
        $this->assertStringNotContainsString('data-map-surface-templates', $source);

        foreach ([
            resource_path('views/map/partials/scene.blade.php'),
            resource_path('views/map/partials/detail-palette.blade.php'),
            resource_path('views/map/partials/extensions/global-navigation.blade.php'),
            resource_path('views/map/partials/extensions/surface-controls.blade.php'),
            resource_path('views/map/partials/extensions/canvas-overlays.blade.php'),
        ] as $path) {
            $this->assertFileExists($path);
        }
    }

    public function test_map_css_is_routed_through_feature_owned_files_instead_of_app_css_tail(): void
    {
        $appCss = file_get_contents(resource_path('css/app.css'));
        $mapCss = file_get_contents(resource_path('css/map/index.css'));

        $this->assertStringContainsString("@import './map/index.css';", $appCss);
        $this->assertStringNotContainsString('V47.7 Map Presentation Foundation', $appCss);

        foreach ([
            'presentation.css',
            'navigation.css',
            'data-layers.css',
            'surfaces.css',
            'roadmap.css',
            'space-station.css',
            'personalization.css',
        ] as $file) {
            $this->assertStringContainsString("@import './".$file."';", $mapCss);
            $this->assertFileExists(resource_path('css/map/'.$file));
        }
    }

    public function test_refactor_preserves_the_existing_rendered_map_runtime_contract(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('data-map-workspace', false)
            ->assertSee('data-map-scene', false)
            ->assertSee('data-map-context-surface', false)
            ->assertSee('data-map-surface-templates', false)
            ->assertSee('data-map-update-status', false)
            ->assertSee('data-canovia-map', false);
    }
}
