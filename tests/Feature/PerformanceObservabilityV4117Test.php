<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PerformanceObservabilityV4117Test extends TestCase
{
    use RefreshDatabase;

    public function test_core_request_logs_timing_query_tables_and_duplicate_shapes_without_sql_or_bindings(): void
    {
        config()->set('performance.enabled', true);
        config()->set('performance.paths', ['/__performance-probe']);
        config()->set('session.driver', 'database');
        config()->set('session.lottery', [0, 100]);

        // AppServiceProvider booted while the testing config had measurement
        // disabled, so register the opt-in query listener after enabling it.
        $this->app->getProvider(\App\Providers\AppServiceProvider::class)->boot();

        Route::middleware('web')
            ->get('/__performance-probe', function () {
                DB::table('users')->count();
                DB::table('users')->count();

                return response('ok');
            })
            ->name('performance.probe');

        Log::spy();

        $this->get('/__performance-probe')->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $data): bool {
                if ($message !== 'canovia.performance') {
                    return false;
                }

                $this->assertSame('/__performance-probe', $data['path']);
                $this->assertSame('performance.probe', $data['route']);
                $this->assertSame('GET', $data['method']);
                $this->assertSame(200, $data['status']);
                $this->assertGreaterThanOrEqual(2, $data['query_count']);
                $this->assertGreaterThanOrEqual(1, $data['duplicate_query_count']);
                $this->assertGreaterThanOrEqual(0, $data['db_ms']);
                $this->assertGreaterThanOrEqual($data['db_ms'], $data['elapsed_ms']);
                $this->assertArrayHasKey('users', $data['tables']);
                $this->assertGreaterThanOrEqual(2, $data['tables']['users']['count']);

                $userDuplicate = collect($data['duplicate_shapes'])
                    ->first(fn (array $shape) => $shape['table'] === 'users' && $shape['count'] >= 2);
                $this->assertNotNull($userDuplicate);

                $serialized = json_encode($data, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('select ', strtolower($serialized));
                $this->assertStringNotContainsString('bindings', strtolower($serialized));
                $this->assertStringNotContainsString('actor_token', strtolower($serialized));

                return true;
            })
            ->once();
    }

    public function test_default_monitored_paths_cover_current_core_navigation(): void
    {
        $paths = config('performance.paths');

        foreach (['/', '/inbox', '/roadmap', '/timeline', '/navigate', '/calendar'] as $path) {
            $this->assertContains($path, $paths);
        }
    }
}
