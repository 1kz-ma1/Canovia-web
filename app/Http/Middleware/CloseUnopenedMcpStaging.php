<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Newly provisioned MCP staging instances expose only Render's /up probe
 * until a separate operator-approved staging access configuration is ready.
 * Production routing is unaffected and no sensitive diagnostic is exposed.
 */
final class CloseUnopenedMcpStaging
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'staging') {
            // Never permit an unmarked instance to become an open app, even
            // if someone accidentally switches the web-access flag.
            if (config('canovia_staging.isolated') !== true) {
                return $this->unavailable();
            }

            if ($request->is('up')) {
                return $next($request);
            }

            if (config('canovia_staging.web_access_enabled') !== true) {
                return $this->unavailable();
            }
        }

        return $next($request);
    }

    private function unavailable(): Response
    {
        return response('Staging service unavailable.', 503)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
