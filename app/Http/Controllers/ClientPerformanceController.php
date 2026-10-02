<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClientPerformanceController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([
                'initial_load',
                'instant_navigation',
                'surface_mount',
            ])],
            'path' => ['required', 'string', 'max:160'],
            'route' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', Rule::in([
                'initial',
                'cache',
                'prefetch',
                'network',
                'history',
                'programmatic',
            ])],
            'surface' => ['required', Rule::in(['web', 'pwa'])],
            'device' => ['required', Rule::in(['mobile', 'desktop'])],
            'platform' => ['required', Rule::in(['ios', 'android', 'other'])],
            'metrics' => ['required', 'array'],
        ]);

        $path = parse_url($validated['path'], PHP_URL_PATH) ?: '/';
        if (! is_string($path) || ! str_starts_with($path, '/') || strlen($path) > 160) {
            throw ValidationException::withMessages([
                'path' => 'Performance pathを確認してください。',
            ]);
        }

        $allowedMetrics = [
            'total_ms',
            'wait_ms',
            'fetch_ms',
            'parse_ms',
            'render_ms',
            'mount_ms',
            'frame_ready_ms',
            'response_bytes',
            'long_task_count',
            'long_task_ms',
            'layout_shift',
            'ttfb_ms',
            'dom_content_loaded_ms',
            'load_ms',
        ];

        $safeMetrics = [];
        foreach ($allowedMetrics as $key) {
            if (! array_key_exists($key, $validated['metrics'])) {
                continue;
            }

            $value = $validated['metrics'][$key];
            if (! is_numeric($value)) {
                continue;
            }

            $number = (float) $value;
            if (! is_finite($number)) {
                continue;
            }

            $safeMetrics[$key] = match ($key) {
                'response_bytes' => (int) max(0, min(10_000_000, round($number))),
                'long_task_count' => (int) max(0, min(1000, round($number))),
                'layout_shift' => round(max(0, min(100, $number)), 4),
                default => round(max(0, min(3_600_000, $number)), 2),
            };
        }

        if ($safeMetrics === []) {
            throw ValidationException::withMessages([
                'metrics' => 'Performance metricsを確認してください。',
            ]);
        }

        if ((bool) config('performance.enabled', false)) {
            Log::info('canovia.client_performance', [
                'type' => $validated['type'],
                'path' => $path,
                'route' => $validated['route'] ?? null,
                'source' => $validated['source'] ?? null,
                'surface' => $validated['surface'],
                'device' => $validated['device'],
                'platform' => $validated['platform'],
                'authenticated' => $request->user() !== null,
                'metrics' => $safeMetrics,
            ]);
        }

        return response()->noContent();
    }
}
