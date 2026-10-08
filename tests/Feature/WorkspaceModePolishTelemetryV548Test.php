<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceModePolishTelemetryV548Test extends TestCase
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

    public function test_app_shell_exposes_mode_source_and_telemetry_endpoint(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
            'workspace_mode_preference' => 'development',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.study.index'))
            ->assertOk()
            ->assertSee('data-workspace-mode-source="route_hint"', false)
            ->assertSee('data-navigation-shell="workspace"', false)
            ->assertSee('data-workspace-exit', false);
    }

    public function test_workspace_mode_selected_telemetry_keeps_only_safe_metadata(): void
    {
        $this->postJson(route('behavior_events.store'), [
            'event_type' =>
                BehaviorEventType::WorkspaceModeSelected->value,
            'metadata' => [
                'selected_mode' => 'study',
                'from_mode' => 'overview',
                'from_source' => 'default',
                'surface' => 'native',
                'device' => 'mobile',
                'platform' => 'ios',
                'raw_text' => 'never persist this',
                'route_url' => '/plans/secret',
            ],
        ])->assertNoContent();

        $event = BehaviorEvent::query()->latest('id')->firstOrFail();

        $this->assertSame(
            BehaviorEventType::WorkspaceModeSelected,
            $event->event_type,
        );
        $this->assertSame([
            'selected_mode' => 'study',
            'from_mode' => 'overview',
            'from_source' => 'default',
            'surface' => 'native',
            'device' => 'mobile',
            'platform' => 'ios',
        ], $event->metadata);
    }

    public function test_workspace_mode_auto_context_accepts_only_automatic_sources(): void
    {
        $this->postJson(route('behavior_events.store'), [
            'event_type' =>
                BehaviorEventType::WorkspaceModeAutoContext->value,
            'metadata' => [
                'mode' => 'development',
                'source' => 'plan_profile',
                'surface' => 'pwa',
                'device' => 'mobile',
                'platform' => 'ios',
                'plan_title' => 'must not persist',
            ],
        ])->assertNoContent();

        $event = BehaviorEvent::query()->latest('id')->firstOrFail();

        $this->assertSame([
            'mode' => 'development',
            'source' => 'plan_profile',
            'surface' => 'pwa',
            'device' => 'mobile',
            'platform' => 'ios',
        ], $event->metadata);

        $this->postJson(route('behavior_events.store'), [
            'event_type' =>
                BehaviorEventType::WorkspaceModeAutoContext->value,
            'metadata' => [
                'mode' => 'study',
                'source' => 'manual_preference',
                'surface' => 'web',
                'device' => 'desktop',
                'platform' => 'other',
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('behavior_events', 1);
    }

    public function test_workspace_mode_runtime_closes_stale_menus_and_tracks_context_changes(): void
    {
        $runtime = file_get_contents(
            resource_path('js/workspace-mode-runtime.mjs'),
        );
        $app = file_get_contents(resource_path('js/app.js'));
        $instant = file_get_contents(
            resource_path('js/instant-navigation.mjs'),
        );

        $this->assertStringContainsString(
            "'workspace_mode_selected'",
            $runtime,
        );
        $this->assertStringContainsString(
            "'workspace_mode_auto_context'",
            $runtime,
        );
        $this->assertStringContainsString(
            "AUTO_CONTEXT_STORAGE_KEY",
            $runtime,
        );
        $this->assertStringContainsString(
            "documentRef.addEventListener('canovia:before-page-replace'",
            $runtime,
        );
        $this->assertStringContainsString(
            "documentRef.addEventListener('canovia:page-ready'",
            $runtime,
        );
        $this->assertStringContainsString(
            "windowRef.addEventListener('orientationchange'",
            $runtime,
        );
        $this->assertStringContainsString(
            "windowRef.visualViewport?.addEventListener('resize'",
            $runtime,
        );
        $this->assertStringContainsString(
            "mountWorkspaceModeRuntime();",
            $app,
        );
        $this->assertStringContainsString(
            'documentRef.body.dataset.workspaceModeSource',
            $instant,
        );
    }

    public function test_mode_bar_is_constrained_by_safe_area_and_dynamic_viewport(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '.desktop-app-header {',
            $css,
        );
        $this->assertStringContainsString(
            'padding-top: env(safe-area-inset-top);',
            $css,
        );
        $this->assertStringContainsString(
            '.workspace-mode-bar-inner {',
            $css,
        );
        $this->assertStringContainsString(
            'padding-left: max(1rem, env(safe-area-inset-left));',
            $css,
        );
        $this->assertStringContainsString(
            'padding-right: max(1rem, env(safe-area-inset-right));',
            $css,
        );
        $this->assertStringContainsString(
            'calc(100dvh - env(safe-area-inset-top) - 7rem)',
            $css,
        );
        $this->assertStringContainsString(
            'overscroll-behavior: contain;',
            $css,
        );
        $this->assertStringContainsString(
            '-webkit-overflow-scrolling: touch;',
            $css,
        );
        $this->assertStringContainsString(
            'html[data-canovia-keyboard="open"] .workspace-mode-menu',
            $css,
        );
    }

    public function test_workspace_mode_event_types_are_client_recordable(): void
    {
        $this->assertContains(
            BehaviorEventType::WorkspaceModeSelected->value,
            BehaviorEventType::clientRecordable(),
        );
        $this->assertContains(
            BehaviorEventType::WorkspaceModeAutoContext->value,
            BehaviorEventType::clientRecordable(),
        );
    }
}
