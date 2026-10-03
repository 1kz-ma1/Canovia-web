<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InteractionPerformanceV518Test extends TestCase
{
    public function test_client_performance_endpoint_logs_only_bounded_safe_metrics(): void
    {
        config()->set('performance.enabled', true);

        Log::spy();

        $this->postJson(route('performance.client'), [
            'type' => 'instant_navigation',
            'path' => '/roadmap?plan_id=123&secret=hidden',
            'route' => 'roadmap.index',
            'source' => 'cache',
            'surface' => 'pwa',
            'device' => 'mobile',
            'platform' => 'ios',
            'metrics' => [
                'total_ms' => 84.321,
                'mount_ms' => 12.75,
                'long_task_count' => 2,
                'long_task_ms' => 61.4,
                'layout_shift' => 0.02345,
                'response_bytes' => 99999999,
                'user_text' => 'must never be logged',
            ],
        ])->assertNoContent();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $data): bool {
                if ($message !== 'canovia.client_performance') {
                    return false;
                }

                $this->assertSame('instant_navigation', $data['type']);
                $this->assertSame('/roadmap', $data['path']);
                $this->assertSame('roadmap.index', $data['route']);
                $this->assertSame('cache', $data['source']);
                $this->assertSame('pwa', $data['surface']);
                $this->assertSame('mobile', $data['device']);
                $this->assertSame('ios', $data['platform']);
                $this->assertSame(84.32, $data['metrics']['total_ms']);
                $this->assertSame(12.75, $data['metrics']['mount_ms']);
                $this->assertSame(2, $data['metrics']['long_task_count']);
                $this->assertSame(61.4, $data['metrics']['long_task_ms']);
                $this->assertSame(0.0235, $data['metrics']['layout_shift']);
                $this->assertSame(10_000_000, $data['metrics']['response_bytes']);
                $this->assertArrayNotHasKey('user_text', $data['metrics']);

                $serialized = json_encode($data, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('secret', $serialized);
                $this->assertStringNotContainsString('must never be logged', $serialized);

                return true;
            })
            ->once();
    }

    public function test_client_performance_endpoint_rejects_payload_without_allowed_metrics(): void
    {
        $this->postJson(route('performance.client'), [
            'type' => 'surface_mount',
            'path' => '/timeline',
            'route' => 'timeline.index',
            'source' => 'initial',
            'surface' => 'web',
            'device' => 'desktop',
            'platform' => 'other',
            'metrics' => [
                'arbitrary' => 123,
            ],
        ])->assertUnprocessable();
    }

    public function test_v518_runtime_measures_navigation_without_permanent_background_work(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));
        $performance = file_get_contents(resource_path('js/interaction-performance.mjs'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            "from './interaction-performance.mjs'",
            $app,
        );
        $this->assertStringContainsString('mountCanoviaInteractionPerformance();', $app);
        $this->assertStringContainsString('syncWorkTimerTicker', $app);
        $this->assertStringContainsString('stopWorkTimerTicker', $app);
        $this->assertStringNotContainsString('window.setInterval(updateTimers, 1000)', $app);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', syncWorkTimerTicker)", $app);
        $this->assertStringContainsString('scheduleInstantOfflineSnapshotCapture', $app);
        $this->assertStringContainsString('window.requestIdleCallback(run', $app);
        $this->assertStringContainsString('canovia:surface-mounted', $app);

        $this->assertStringContainsString('canovia:instant-navigation-performance', $instant);
        $this->assertStringContainsString("source: 'cache'", $instant);
        $this->assertStringContainsString("const source = usedPrefetch ? 'prefetch' : 'network'", $instant);
        $this->assertStringContainsString('requestIdleCallback(run', $instant);
        $this->assertStringNotContainsString('prefetchTimer', $instant);

        $this->assertStringContainsString('CORE_PERFORMANCE_PATHS', $performance);
        $this->assertStringContainsString("supported.includes('longtask')", $performance);
        $this->assertStringContainsString("supported.includes('layout-shift')", $performance);
        $this->assertStringContainsString('keepalive: true', $performance);
        $this->assertStringContainsString("'/navigate'", $performance);

        $this->assertStringContainsString(
            'meta name="canovia-client-performance-url"',
            $layout,
        );
    }

    public function test_revalidate_is_deferred_but_keeps_real_navigate_request(): void
    {
        $instant = file_get_contents(resource_path('js/instant-navigation.mjs'));

        $this->assertStringContainsString("fetchPayload(target, 'navigate')", $instant);
        $this->assertStringContainsString('requestIdleCallback(run, { timeout: 1200 })', $instant);
        $this->assertStringContainsString('revalidateHandles', $instant);
    }
}
