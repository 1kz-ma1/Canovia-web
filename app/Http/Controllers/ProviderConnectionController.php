<?php

namespace App\Http\Controllers;

use App\Models\ProviderConnection;
use App\Services\ProviderConnectionService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProviderConnectionController extends Controller
{
    public function store(
        Request $request,
        string $providerKey,
        ProviderConnectionService $connections,
    ) {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $credentials = $connections->create(
                $request->user(),
                $providerKey,
                $validated['label'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_provider',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $connection = $credentials->connection;

        return response()->json([
            'status' => 'created',
            'connection' => [
                'public_id' => (string) $connection->public_id,
                'provider_key' => (string) $connection->provider_key,
                'label' => $connection->label,
                'status' => $connection->status,
            ],
            'secret' => $credentials->secret,
            'secret_notice' =>
                'This secret is returned once. Store it on the provider side.',
            'signing' => [
                'algorithm' => 'HMAC-SHA256',
                'message' => '<unix_timestamp>.<raw_request_body>',
                'headers' => [
                    'connection' => 'X-Canovia-Connection',
                    'timestamp' => 'X-Canovia-Timestamp',
                    'signature' => 'X-Canovia-Signature',
                ],
            ],
        ], 201);
    }

    public function destroy(
        Request $request,
        ProviderConnection $connection,
        ProviderConnectionService $connections,
    ) {
        abort_unless(
            (int) $connection->user_id
                === (int) $request->user()->id,
            404,
        );

        $revoked = $connections->revoke($connection);

        return response()->json([
            'status' => 'revoked',
            'connection' => [
                'public_id' => (string) $revoked->public_id,
                'provider_key' => (string) $revoked->provider_key,
                'status' => $revoked->status,
                'revoked_at' => $revoked->revoked_at?->toIso8601String(),
            ],
        ]);
    }
}
