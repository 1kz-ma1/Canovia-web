<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\ProviderConnection;
use App\Models\Task;
use App\Services\ExecutionResolver;
use App\Services\PlanOwnershipService;
use App\Services\ProviderExecutionContextService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProviderExecutionContextController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        Task $task,
        ProviderConnection $connection,
        PlanOwnershipService $ownership,
        ExecutionResolver $resolver,
        ProviderExecutionContextService $contexts,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);

        abort_unless(
            (int) $connection->user_id
                === (int) $request->user()->id,
            404,
        );

        $resolution = $resolver->resolve($plan, $task);

        abort_unless(
            $resolution->provider?->key
                === $connection->provider_key,
            409,
            '選択中のExecution ProviderとConnectionが一致しません。',
        );

        try {
            $issued = $contexts->issue(
                $connection,
                $plan,
                $task,
                $resolution->capability,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'context_unavailable',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'issued',
            'provider_key' => (string) $connection->provider_key,
            'connection_public_id' => (string) $connection->public_id,
            'execution_context' => $issued['token'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);
    }
}
