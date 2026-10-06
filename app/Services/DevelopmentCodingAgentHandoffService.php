<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

final class DevelopmentCodingAgentHandoffService
{
    public function __construct(
        private readonly ExecutionRequestHandoffService $requests,
        private readonly ExecutionOrchestrationContextService $contexts,
        private readonly ExecutionPacketService $packets,
    ) {}

    /**
     * Prepare the existing external Execution Orchestration path from a
     * human-confirmed Development Implementation Brief.
     *
     * This method performs no AI request and no external provider request.
     *
     * @param array<string,mixed> $brief
     * @return array<string,mixed>
     */
    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        array $brief,
        ?int $availableMinutes = null,
    ): array {
        $executionRequest = $this->requests
            ->confirmFromDevelopmentBrief(
                $request,
                $plan,
                $task,
                $brief,
                $availableMinutes,
            );

        $context = $this->contexts->snapshot(
            $request,
            $plan,
            $task,
        );

        $prompt = $this->packets->prompt(
            $context,
            'external',
            $availableMinutes,
            $executionRequest,
            'Coding Agent',
        );

        $key = ExecutionRequestHandoffService::sessionKey(
            $plan,
            $task,
        );
        $state = $request->session()->get($key, []);

        $state['context_fingerprint'] = (string) (
            $context['context_fingerprint']
            ?? ''
        );
        $state['actor_type'] = 'external';
        $state['available_minutes'] = $availableMinutes;
        $state['execution_request'] = $executionRequest;
        $state['packet'] = null;
        $state['handoff_prompt'] = $prompt;
        $state['development_agent_handoff'] = [
            'version' => 1,
            'state' => 'prepared',
            'confirmed_by' => 'user',
            'brief_hash' => (string) data_get(
                $executionRequest,
                'source.brief_hash',
                '',
            ),
            'prepared_at' => now()->toIso8601String(),
        ];

        $request->session()->put($key, $state);

        return [
            'execution_request' => $executionRequest,
            'handoff_prompt' => $prompt,
            'context_fingerprint' => (string) (
                $context['context_fingerprint']
                ?? ''
            ),
        ];
    }
}
