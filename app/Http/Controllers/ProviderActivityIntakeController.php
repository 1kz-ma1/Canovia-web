<?php

namespace App\Http\Controllers;

use App\Services\ProviderActivityIntakeService;
use App\Services\ProviderConnectionService;
use App\Services\ProviderExecutionContextService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProviderActivityIntakeController extends Controller
{
    public const MAX_PAYLOAD_BYTES = 65536;

    public function __invoke(
        Request $request,
        ProviderConnectionService $connections,
        ProviderExecutionContextService $contexts,
        ProviderActivityIntakeService $intake,
    ) {
        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return response()->json([
                'status' => 'payload_too_large',
            ], 413);
        }

        $connection = $connections->authenticate(
            (string) $request->header('X-Canovia-Connection', ''),
            (string) $request->header('X-Canovia-Timestamp', ''),
            (string) $request->header('X-Canovia-Signature', ''),
            $raw,
        );

        if (! $connection) {
            return response()->json([
                'status' => 'invalid_signature',
            ], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json([
                'status' => 'invalid_json',
            ], 400);
        }

        if ((string) ($payload['schema_version'] ?? '') !== '1.0') {
            return response()->json([
                'status' => 'unsupported_schema',
            ], 422);
        }

        $executionContext = $payload['execution_context'] ?? null;
        $activityPayload = $payload['activity'] ?? null;

        if (
            ! is_string($executionContext)
            || trim($executionContext) === ''
            || strlen($executionContext) > 8192
            || ! is_array($activityPayload)
        ) {
            return response()->json([
                'status' => 'invalid_payload',
            ], 422);
        }

        try {
            $context = $contexts->resolve(
                $executionContext,
                $connection,
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'status' => 'invalid_execution_context',
            ], 403);
        }

        try {
            $activity = $intake->accept(
                $connection,
                $context,
                $activityPayload,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'invalid_activity',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'accepted',
            'activity_id' => (int) $activity->id,
            'evidence_id' => $activity->task_evidence_id
                ? (int) $activity->task_evidence_id
                : null,
        ], 202);
    }
}
