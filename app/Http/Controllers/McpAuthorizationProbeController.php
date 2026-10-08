<?php

namespace App\Http\Controllers;

use App\Services\McpProtectedResourceConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Strictly unauthenticated-proof OAuth challenge. It deliberately accepts
 * NOTHING until token validation, fresh user consent and MCP tools exist.
 */
final class McpAuthorizationProbeController extends Controller
{
    public function __invoke(
        Request $request,
        McpProtectedResourceConfiguration $configuration,
    ): JsonResponse {
        abort_unless($configuration->isReady(), 404);

        // Even a valid Canovia browser session, a forged bearer token and a
        // prepared ChatGPT sharing preference cannot authorize this endpoint.
        return response()->json([
            'error' => 'authorization_required',
            'message' => 'MCP read access is not yet enabled.',
        ], 401)
            ->header('WWW-Authenticate', $configuration->challenge(
                $request->hasHeader('Authorization'),
            ))
            ->header('Cache-Control', 'no-store, private')
            ->header('Vary', 'Authorization')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
