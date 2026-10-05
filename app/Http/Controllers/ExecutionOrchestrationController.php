<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\ExecutionCoordinationService;
use App\Services\ExecutionGitHubHandoffService;
use App\Services\ExecutionOrchestrationContextService;
use App\Services\ExecutionPacketService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\FeatureAccessService;
use App\Services\GitHubEvidenceDecisionService;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\GitHubRepositoryWriter;
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
        ExecutionGitHubHandoffService $githubHandoff,
        GitHubIntegrationReadinessService $githubReadiness,
        GitHubRepositoryWriter $githubWriter,
        GitHubEvidenceDecisionService $githubDecision,
        ExecutionCoordinationService $coordination,
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
        $githubIntegrationStatus = $githubReadiness->forActor(
            $request->user(),
        );

        $githubRepositories = PlanArtifact::query()
            ->where('plan_id', $plan->id)
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->orderBy('title')
            ->get();

        $latestExecutionPullRequest = $task->artifacts()
            ->where('provider', 'github')
            ->where('artifact_type', 'link')
            ->orderByDesc('plan_artifacts.id')
            ->get()
            ->first(fn (PlanArtifact $artifact) =>
                data_get($artifact->metadata, 'github_write_origin.source') === 'execution_github_handoff'
            );

        $githubReturnSnapshot = $latestExecutionPullRequest
            && is_array(data_get($latestExecutionPullRequest->metadata, 'github_return_snapshot'))
                ? data_get($latestExecutionPullRequest->metadata, 'github_return_snapshot')
                : null;
        $githubDecisionCandidate = $latestExecutionPullRequest && is_array($githubReturnSnapshot)
            ? $githubDecision->candidate($task, $latestExecutionPullRequest, $githubReturnSnapshot)
            : null;

        $coordinationProjection = $coordination->projection(
            $request,
            $plan,
            $task,
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
            'githubRepositories' => $githubRepositories,
            'githubChangeCandidate' => $githubHandoff->candidate($request, $plan, $task),
            'githubHandoffResult' => is_array($state['github_handoff_result'] ?? null)
                ? $state['github_handoff_result']
                : null,
            'githubWriteEntitled' => (bool) data_get(
                $githubIntegrationStatus,
                'write.allowed',
                false,
            ),
            'githubEvidenceEntitled' => (bool) data_get(
                $githubIntegrationStatus,
                'evidence.allowed',
                false,
            ),
            'githubWriteConfigured' => (bool) data_get(
                $githubIntegrationStatus,
                'runtime.app_configured',
                false,
            ),
            'githubWebhookConfigured' => (bool) data_get(
                $githubIntegrationStatus,
                'runtime.webhook_configured',
                false,
            ),
            'githubIntegrationStatus' => $githubIntegrationStatus,
            'latestExecutionPullRequest' => $latestExecutionPullRequest,
            'githubReturnSnapshot' => $githubReturnSnapshot,
            'githubDecisionCandidate' => $githubDecisionCandidate,
            'coordinationProjection' => $coordinationProjection,
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

        if ($this->executionClosed($task)) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '完了・中止済みTaskには新しいExecution Packetを生成しません。後続TaskのCoordinationを確認してください。');
        }

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

        if ($this->executionClosed($task)) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->withErrors([
                    'packet_json' => '完了・中止済みTaskには新しいExecution Packetを読み込みません。後続TaskのCoordinationを確認してください。',
                ]);
        }

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

        // A GitHub candidate is bound to the exact active Packet hash.
        // Resetting the Packet therefore invalidates and removes the candidate.
        $request->session()->forget(ExecutionGitHubHandoffService::sessionKey($plan, $task));

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

    private function executionClosed(Task $task): bool
    {
        return in_array($task->status, ['done', 'cancelled'], true)
            || (int) $task->progress_percent >= 100;
    }

    private function sessionKey(Plan $plan, Task $task): string
    {
        return ExecutionRequestHandoffService::sessionKey($plan, $task);
    }
}
