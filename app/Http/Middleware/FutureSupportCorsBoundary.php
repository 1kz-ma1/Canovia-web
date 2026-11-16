<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Outer fence: the application's generic api/* CORS policy must not leak here. */
final class FutureSupportCorsBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/future/*')) {
            return $next($request);
        }
        $response = $request->isMethod('OPTIONS')
            ? response()->json(['code' => 'FUTURE_UNAVAILABLE'], 404) : $next($request);
        foreach (array_keys($response->headers->all()) as $header) {
            if (str_starts_with(strtolower($header), 'access-control-')) {
                $response->headers->remove($header);
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
