<?php

namespace App\Http\Middleware;

use App\Support\RequestPerformance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class MeasurePagePerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldMeasure($request)) {
            return $next($request);
        }

        $metrics = new RequestPerformance;
        $request->attributes->set(RequestPerformance::ATTRIBUTE, $metrics);
        $started = hrtime(true);
        $response = null;

        try {
            return $response = $next($request);
        } finally {
            $elapsedMs = max(0.0, (hrtime(true) - $started) / 1e6);
            $request->attributes->remove(RequestPerformance::ATTRIBUTE);

            try {
                $dbMs = round($metrics->queryMs, 2);
                $elapsedRounded = round($elapsedMs, 2);

                Log::info('canovia.performance', [
                    'path' => $request->getPathInfo(),
                    'route' => $request->route()?->getName(),
                    'method' => $request->method(),
                    'status' => $response?->getStatusCode(),
                    'elapsed_ms' => $elapsedRounded,
                    'db_ms' => $dbMs,
                    'non_db_ms' => round(max(0, $elapsedMs - $metrics->queryMs), 2),
                    'db_share_percent' => $elapsedMs > 0
                        ? round(min(100, ($metrics->queryMs / $elapsedMs) * 100), 1)
                        : 0.0,
                    'query_count' => $metrics->queryCount,
                    'duplicate_query_count' => $metrics->duplicateQueryCount(),
                    'session_query_count' => $metrics->sessionQueries,
                    'session_query_ms' => round($metrics->sessionMs, 2),
                    'tables' => $metrics->tableSummary(),
                    'duplicate_shapes' => $metrics->duplicateShapes(),
                    'slowest_shapes' => $metrics->slowestShapes(),
                    'network_retry' => $request->query('_canovia_network') === '1'
                        || $request->query('_pk_network') === '1',
                    'navigation_preload' => $request->hasHeader('Service-Worker-Navigation-Preload'),
                    'prefetch' => str_contains(
                        strtolower($request->header('Sec-Purpose', '').' '.$request->header('Purpose', '')),
                        'prefetch',
                    ),
                    'session_driver' => (string) config('session.driver'),
                    'db_connection' => (string) config('database.default'),
                ]);
            } catch (\Throwable) {
                // Performance diagnosis must never make a normal request fail.
            }
        }
    }

    private function shouldMeasure(Request $request): bool
    {
        if (! (bool) config('performance.enabled', false)) {
            return false;
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        return in_array($request->getPathInfo(), (array) config('performance.paths', []), true);
    }
}
