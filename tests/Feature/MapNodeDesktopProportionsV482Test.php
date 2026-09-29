<?php

namespace Tests\Feature;

use Tests\TestCase;

class MapNodeDesktopProportionsV482Test extends TestCase
{
    public function test_desktop_leaf_nodes_keep_horizontal_proportions_without_reserved_action_row(): void
    {
        $css = file_get_contents(resource_path('css/map/node-readability.css'));

        $this->assertStringContainsString('@media (min-width: 768px)', $css);
        $this->assertStringContainsString('width: 8.6rem;', $css);
        $this->assertStringContainsString('min-height: 4.35rem;', $css);
        $this->assertStringContainsString('padding-bottom: .52rem;', $css);
        $this->assertStringContainsString('top: -.72rem;', $css);
        $this->assertStringContainsString('bottom: auto;', $css);

        $this->assertStringNotContainsString('padding-bottom: 2.9rem;', $css);
    }

    public function test_primary_leaf_stays_emphasized_without_becoming_vertical(): void
    {
        $css = file_get_contents(resource_path('css/map/node-readability.css'));

        $this->assertStringContainsString(
            '.canovia-map-page .canovia-map-node[data-map-presentation-kind="leaf"].is-primary',
            $css,
        );
        $this->assertStringContainsString('width: 9.2rem;', $css);
        $this->assertStringContainsString('min-height: 4.6rem;', $css);
    }

    public function test_mobile_node_readability_contract_remains_separate(): void
    {
        $css = file_get_contents(resource_path('css/map/node-readability.css'));

        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString('width: 6.5rem;', $css);
        $this->assertStringContainsString('min-height: 4.5rem;', $css);
    }
}
