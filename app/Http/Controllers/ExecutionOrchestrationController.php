<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\Task;
use App\Services\ExecutionOrchestrationContextService;
use App\Services\ExecutionPacketService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\FeatureAccessService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExecutionOrchestrationController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionOrchestrationContextService $contexts,
        ExecutionPacketService $packets,
        FeatureAccessService $access,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $context = $contexts->snapshot($request, $plan, $task);
        $state = $request->session()->get($this->sessionKey($plan, $task), []);
        $hasGeneratedState = is_array($state['packet'] ?? null)
            || filled($state['handoff_prompt'] ?? null);
        $stale = $hasGeneratedState
            && filled($state['context_fingerprint'] ?? null)
            && ! hash_equals(
                (string) $context['context_fingerprint'],
                (string) $state['context_fingerprint'],
            );

        $nativeDecision = $access->resolveAccess(
            $request->user(),
            FeatureKey::AutomaticAiExecution,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

        return view('execution_orchestration.show', [
            'plan' => $plan,
            'task' => $task,
            'context' => $context,
            'packet' => is_array($state['packet'] ?? null) ? $state['packet'] : null,
            'handoffPrompt' => (string) ($state['handoff_prompt'] ?? ''),
            'executionRequest' => is_array($state['execution_request'] ?? null) ? $state['execution_request'] : null,
            'actorType' => (string) ($state['actor_type'] ?? 'human_ai'),
            'availableMinutes' => $state['available_minutes'] ?? null,
            'packetStale' => $stale,
            'nativeAiAvailable' => $nativeDecision->allowed && $packets->nativeConfigured(),
            'nativeAiEntitled' => $nativeDecision->allowed,
            'nativeAiConfigured' => $packets->nativeConfigured(),
            'actorTypes' => ExecutionPacketService::ACTOR_TYPES,
        ]);
    }

    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionOrchestrationContextService $contexts,
        ExecutionPacketService $packets,
        FeatureAccessService $access,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $validated = $request->validate([
            'generation_mode' => ['required', Rule::in(['native', 'external'])],
            'actor_type' => ['required', Rule::in(array_keys(ExecutionPacketService::ACTOR_TYPES))],
            'available_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $context = $contexts->snapshot($request, $plan, $task);
        $actorType = (string) $validated['actor_type'];
        $availableMinutes = isset($validated['available_minutes'])
            ? (int) $validated['available_minutes']
            : null;

        $existingState = $request->session()->get($this->sessionKey($plan, $task), []);
        $executionRequest = is_array($existingState['execution_request'] ?? null)
            ? $existingState['execution_request']
            : null;

        if ($executionRequest) {
            // The prepare form is another explicit human confirmation point.
            // Keep the request contract aligned with the actor/time actually used for this Packet.
            $executionRequest['actor_type'] = $actorType;
            $executionRequest['available_minutes'] = $availableMinutes;
        }

        $state = [
            'context_fingerprint' => (string) $context['context_fingerprint'],
            'actor_type' => $actorType,
            'available_minutes' => $availableMinutes,
            'execution_request' => $executionRequest,
            'packet' => null,
            'handoff_prompt' => null,
        ];

        if ($validated['generation_mode'] === 'external') {
            $state['handoff_prompt'] = $packets->prompt($context, $actorType, $availableMinutes, $executionRequest);
            $request->session()->put($this->sessionKey($plan, $task), $state);

            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('success', '外部AIへ渡すExecution Contextを準備しました。');
        }

        $access->authorizeUse(
            $request->user(),
            FeatureKey::AutomaticAiExecution,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

        if (! $packets->nativeConfigured()) {
            $state['handoff_prompt'] = $packets->prompt($context, $actorType, $availableMinutes, $executionRequest);
            $request->session()->put($this->sessionKey($plan, $task), $state);

            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', 'Canovia Native AIが利用できないため、外部AI用Promptへ切り替えました。');
        }

        try {
            $generated = $packets->generateNative(
                $context,
                $plan,
                $task,
                $request->user(),
                $actorType,
                $availableMinutes,
                $executionRequest,
            );
            $state['packet'] = $generated['packet'];
            $state['native_run_id'] = $generated['run_id'];
        } catch (NativeAiExecutionException $exception) {
            $state['handoff_prompt'] = $packets->prompt($context, $actorType, $availableMinutes, $executionRequest);
            $request->session()->put($this->sessionKey($plan, $task), $state);

            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', $exception->getMessage().' 外部AI用Promptへ切り替えました。');
        }

        $request->session()->put($this->sessionKey($plan, $task), $state);

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('success', '現在の全体ContextからExecution Packetを生成しました。');
    }

    public function importExternal(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionOrchestrationContextService $contexts,
        ExecutionPacketService $packets,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $validated = $request->validate([
            'packet_json' => ['required', 'string', 'max:100000'],
        ]);

        $context = $contexts->snapshot($request, $plan, $task);
        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);

        if (
            filled($state['context_fingerprint'] ?? null)
            && ! hash_equals(
                (string) $context['context_fingerprint'],
                (string) $state['context_fingerprint'],
            )
        ) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->withErrors([
                    'packet_json' => 'Prompt生成後にPlan状態が変わっています。現在ContextからPromptを再生成してください。',
                ]);
        }

        $state['context_fingerprint'] = (string) $context['context_fingerprint'];
        $state['packet'] = $packets->importExternal((string) $validated['packet_json'], $context);

        $request->session()->put($key, $state);

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('success', 'Execution Packetを読み込みました。');
    }

    public function reset(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $key = $this->sessionKey($plan, $task);
        $state = $request->session()->get($key, []);
        $executionRequest = is_array($state['execution_request'] ?? null)
            ? $state['execution_request']
            : null;

        if ($executionRequest) {
            $request->session()->put($key, [
                'actor_type' => (string) ($state['actor_type'] ?? data_get($executionRequest, 'actor_type', 'human_ai')),
                'available_minutes' => $state['available_minutes'] ?? data_get($executionRequest, 'available_minutes'),
                'execution_request' => $executionRequest,
                'packet' => null,
                'handoff_prompt' => null,
            ]);
        } else {
            $request->session()->forget($key);
        }

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('status', 'Execution Packetをリセットしました。確認済みの実行リクエストとTask / Planは変更していません。');
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ): void {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
    }

    private function sessionKey(Plan $plan, Task $task): string
    {
        return ExecutionRequestHandoffService::sessionKey($plan, $task);
    }
}
