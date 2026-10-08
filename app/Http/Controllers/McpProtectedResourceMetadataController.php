<?php

namespace App\Http\Controllers;

use App\Services\McpProtectedResourceConfiguration;
use Illuminate\Http\JsonResponse;

final class McpProtectedResourceMetadataController extends Controller
{
    public function __invoke(McpProtectedResourceConfiguration $configuration): JsonResponse
    {
        // No discovery in production until the real IdP and canonical HTTPS
        // resource have been explicitly configured.
        abort_unless($configuration->isReady(), 404);

        return response()->json($configuration->metadata())
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
