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

    public function test_mobile_header_exposes_compact_registry_driven_workspace_switcher(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-workspace-mode-compact="1"', false)
            ->assertSee('data-workspace-mode-option="study"', false)
            ->assertSee('data-workspace-mode-option="development"', false);

        $html = $response->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(
            1,
            $xpath->query(
                '//header[contains(concat(" ", normalize-space(@class), " "), " mobile-app-header ")]'
                .'//div[contains(concat(" ", normalize-space(@class), " "), " mobile-app-header-inner ")]'
                .'//*[@data-workspace-mode-compact="1"]'
            )->length,
        );

        $this->assertSame(
            0,
            $xpath->query(
                '//header[contains(concat(" ", normalize-space(@class), " "), " mobile-app-header ")]'
                .'/div[@data-workspace-mode-bar and @data-workspace-mode-compact="0"]'
            )->length,
        );
    }
}
