<?php

namespace App\Http\Middleware;

use App\Services\McpStagingSyntheticActorBootstrap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Additional session-aware fence AFTER Laravel's web session middleware.
 * No non-synthetic staging user can view the account or link an IdP subject.
 */
final class EnsureMcpStagingSyntheticPilotActor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'staging'
            && config('canovia_staging.login_pilot_enabled') === true) {
            $actor = $request->user();
            if ($actor !== null
                && $actor->email !== McpStagingSyntheticActorBootstrap::EMAIL) {
                return response('Staging service unavailable.', 503)
                    ->header('Cache-Control', 'no-store, private')
                    ->header('X-Content-Type-Options', 'nosniff');
            }
        }

        return $next($request);
    }
}
