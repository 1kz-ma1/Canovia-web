<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Models\GitHubWebhookDelivery;
use App\Services\GitHubWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GitHubWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        GitHubWebhookService $webhooks,
    ) {
        if (! $webhooks->configured()) {
            return response()->json(['status' => 'webhook_not_configured'], 503);
        }

        $raw = $request->getContent();
        if (strlen($raw) > GitHubWebhookService::MAX_PAYLOAD_BYTES) {
            return response()->json(['status' => 'payload_too_large'], 413);
        }

        if (! $webhooks->verifySignature(
            $raw,
            $request->header('X-Hub-Signature-256'),
        )) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $eventName = mb_strtolower(mb_substr(
            trim((string) $request->header('X-GitHub-Event', '')),
            0,
            80,
        ));
        $deliveryId = mb_substr(
            trim((string) $request->header('X-GitHub-Delivery', '')),
            0,
            100,
        );

        if (
            $eventName === ''
            || $deliveryId === ''
            || ! preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $deliveryId)
        ) {
            return response()->json(['status' => 'invalid_headers'], 400);
        }

        if ($eventName === 'ping') {
            return response()->json(['status' => 'pong']);
        }

        if (! $webhooks->supported($eventName)) {
            return response()->json(['status' => 'ignored_event'], 202);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['status' => 'invalid_json'], 400);
        }

        $routing = $webhooks->route($eventName, $payload);
        if (! is_array($routing)) {
            return response()->json(['status' => 'ignored_payload'], 202);
        }

        $deliveryAttributes = [
            'event_name' => (string) $routing['event_name'],
            'action' => $routing['action'],
            'repo_full_name' => (string) $routing['repo_full_name'],
            'installation_id' => (int) $routing['installation_id'],
            'pull_request_numbers' => array_values((array) ($routing['pull_request_numbers'] ?? [])),
            'routing_targets' => array_values((array) ($routing['routing_targets'] ?? [])),
            'status' => 'accepted',
            'matched_artifacts' => 0,
            'synced_tasks' => 0,
            'skipped_entitlement' => 0,
            'last_error' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];

        $delivery = GitHubWebhookDelivery::query()->firstOrCreate(
            ['delivery_id' => $deliveryId],
            $deliveryAttributes,
        );

        if (! $delivery->wasRecentlyCreated && $delivery->status !== 'failed') {
            return response()->json([
                'status' => 'duplicate',
                'delivery_id' => $deliveryId,
            ]);
        }

        if (! $delivery->wasRecentlyCreated) {
            $delivery->update($deliveryAttributes);
        }

        try {
            ProcessGitHubWebhookDelivery::dispatch((int) $delivery->id);
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            Log::error('GitHub webhook queue dispatch failed.', [
                'delivery_id' => $deliveryId,
                'event_name' => $eventName,
                'exception' => $exception::class,
            ]);

            return response()->json(['status' => 'queue_unavailable'], 503);
        }

        return response()->json([
            'status' => 'accepted',
            'delivery_id' => $deliveryId,
        ], 202);
    }
}
