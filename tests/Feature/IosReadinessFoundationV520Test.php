<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IosReadinessFoundationV520Test extends TestCase
{
    public function test_native_client_performance_surface_is_accepted_and_logged(): void
    {
        config()->set('performance.enabled', true);
        Log::spy();

        $this->postJson(route('performance.client'), [
            'type' => 'surface_mount',
            'path' => '/roadmap',
            'route' => 'roadmap.index',
            'source' => 'initial',
            'surface' => 'native',
            'device' => 'mobile',
            'platform' => 'ios',
            'metrics' => [
                'mount_ms' => 4.25,
            ],
        ])->assertNoContent();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $data): bool {
                return $message === 'canovia.client_performance'
                    && $data['surface'] === 'native'
                    && $data['platform'] === 'ios'
                    && $data['metrics']['mount_ms'] === 4.25;
            })
            ->once();
    }

    public function test_layout_exposes_non_secret_native_bootstrap_contract(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            'meta name="canovia-native-bridge-version" content="1"',
            $layout,
        );
        $this->assertStringContainsString(
            'meta name="canovia-native-session-mode" content="web_cookie"',
            $layout,
        );
        $this->assertStringContainsString(
            'meta name="canovia-native-deep-link-origin"',
            $layout,
        );
    }

    public function test_native_runtime_owns_os_boundaries_without_replacing_laravel_session(): void
    {
        $runtime = file_get_contents(resource_path('js/client-runtime.mjs'));
        $app = file_get_contents(resource_path('js/app.js'));
        $instantStart = file_get_contents(resource_path('js/instant-start.mjs'));

        $this->assertStringContainsString('CanoviaNative\\/(iOS|Android)', $runtime);
        $this->assertStringContainsString("surface: 'native'", $runtime);
        $this->assertStringContainsString("session_mode: 'web_cookie'", $runtime);
        $this->assertStringContainsString("csrf_present:", $runtime);
        $this->assertStringContainsString("postCanoviaNativeMessage('openExternal'", $runtime);
        $this->assertStringContainsString("postCanoviaNativeMessage('fileInputRequested'", $runtime);
        $this->assertStringContainsString("case 'openPath':", $runtime);
        $this->assertStringContainsString("case 'back':", $runtime);
        $this->assertStringContainsString("requestClose", $runtime);

        $this->assertStringContainsString('mountCanoviaNativeBridge();', $app);
        $this->assertStringContainsString('window.CanoviaNativeBridge?.handleBack?.()', $app);
        $this->assertStringContainsString('isCanoviaNativeRuntime(windowRef)', $instantStart);
    }

    public function test_native_surface_is_whitelisted_across_client_telemetry_boundaries(): void
    {
        $performance = file_get_contents(app_path('Http/Controllers/ClientPerformanceController.php'));
        $behavior = file_get_contents(app_path('Http/Controllers/BehaviorEventController.php'));
        $funnel = file_get_contents(app_path('Http/Middleware/TrackAiPlanFunnel.php'));
        $mapTelemetry = file_get_contents(resource_path('js/map-telemetry.mjs'));
        $interaction = file_get_contents(resource_path('js/interaction-performance.mjs'));

        $this->assertStringContainsString("Rule::in(['web', 'pwa', 'native'])", $performance);
        $this->assertStringContainsString("['web', 'pwa', 'native']", $behavior);
        $this->assertStringContainsString("['web', 'pwa', 'native']", $funnel);
        $this->assertStringContainsString('return canoviaClientSurface(windowRef);', $mapTelemetry);
        $this->assertStringContainsString('surface: canoviaClientSurface(windowRef)', $interaction);
        $this->assertStringContainsString('platform: canoviaClientPlatform(windowRef)', $interaction);
    }

    public function test_same_origin_blank_links_do_not_require_a_second_wkwebview(): void
    {
        $runtime = file_get_contents(resource_path('js/client-runtime.mjs'));

        $this->assertStringContainsString("if (link.target === '_blank')", $runtime);
        $this->assertStringContainsString('const sameOrigin = safeSameOriginUrl(link.href, windowRef);', $runtime);
        $this->assertStringContainsString('openPath(sameOrigin.href);', $runtime);
    }
}
