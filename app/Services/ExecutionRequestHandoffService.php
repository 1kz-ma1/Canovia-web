<?php

namespace App\Services;

use App\Models\CompanionMutationCandidate;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExecutionRequestHandoffService
{
    public const SCHEMA_VERSION = '1.0';
    public const FLOW = 'execution_request';

    public function __construct(
        private readonly ExecutionOrchestrationContextService $contexts,
    ) {}

    /**
     * Convert a human-confirmed Inbox routing decision into the canonical,
     * session-scoped Execution Request consumed by the orchestration layer.
     *
     * @return array<string,mixed>
     */
    public function confirmFromInbox(
        Request $request,
        InboxItem $item,
        Plan $plan,
        Task $task,
        string $instruction,
        string $actorType = 'human_ai',
        ?int $availableMinutes = null,
    ): array {
        return $this->confirm(
            request: $request,
            plan: $plan,
            task: $task,
            source: [
                'type' => 'inbox_item',
                'id' => (int) $item->id,
                'title' => $item->displayTitle(),
                'source_type' => (string) $item->source_type,
                'url' => filled($item->source_url) ? (string) $item->source_url : null,
                'file_name' => filled($item->original_name) ? (string) $item->original_name : null,
            ],
            instruction: $instruction,
            actorType: $actorType,
            availableMinutes: $availableMinutes,
        );
    }

    /**
     * Convert a human-confirmed Companion candidate into the same contract.
     *
     * @return array<string,mixed>
     */
    public function confirmFromCompanion(
        Request $request,
        CompanionMutationCandidate $candidate,
        Plan $plan,
        Task $task,
        string $instruction,
        string $actorType = 'human_ai',
        ?int $availableMinutes = null,
    ): array {
        return $this->confirm(
            request: $request,
            plan: $plan,
            task: $task,
            source: [
                'type' => 'companion_candidate',
                'id' => (int) $candidate->id,
                'title' => (string) ($candidate->title ?: 'Companionからの実行リクエスト'),
                'thread_id' => (int) $candidate->companion_thread_id,
            ],
            instruction: $instruction,
            actorType: $actorType,
            availableMinutes: $availableMinutes,
        );
    }

    /**
     * Convert a human-confirmed Development Implementation Brief into the
     * canonical session-scoped Execution Request.
     *
     * The brief is already bounded by V57.6 and intentionally excludes source
     * code / diff / provider bodies. This method does not invoke any agent.
     *
     * @param array<string,mixed> $brief
     * @return array<string,mixed>
     */
    public function confirmFromDevelopmentBrief(
        Request $request,
        Plan $plan,
        Task $task,
        array $brief,
        ?int $availableMinutes = null,
    ): array {
        $copyText = trim((string) ($brief['copy_text'] ?? ''));

        if ($copyText === '') {
            throw ValidationException::withMessages([
                'development_handoff' => 'Implementation Briefを確認できませんでした。',
            ]);
        }

        return $this->confirm(
            request: $request,
            plan: $plan,
            task: $task,
            source: [
                'type' => 'development_implementation_brief',
                'version' => max(1, (int) ($brief['version'] ?? 1)),
                'mode' => mb_substr(
                    trim((string) ($brief['mode'] ?? 'continuation')),
                    0,
                    80,
                ),
                'action_kind' => mb_substr(
                    trim((string) ($brief['kind'] ?? '')),
                    0,
                    120,
                ),
                'title' => mb_substr(
                    trim((string) ($brief['title'] ?? $task->title)),
                    0,
                    500,
                ),
                'brief_hash' => hash('sha256', $copyText),
            ],
            instruction: $copyText,
            actorType: 'external',
            availableMinutes: $availableMinutes,
        );
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function confirm(
        Request $request,
        Plan $plan,
        Task $task,
        array $source,
        string $instruction,
        string $actorType,
        ?int $availableMinutes,
    ): array {
        if ((int) $task->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages([
                'task_id' => '選択したPlanのTaskを選んでください。',
            ]);
        }

        if (! array_key_exists($actorType, ExecutionPacketService::ACTOR_TYPES)) {
            $actorType = 'human_ai';
        }

        if ($availableMinutes !== null) {
            $availableMinutes = max(5, min(1440, $availableMinutes));
        }

        $instruction = mb_substr(trim($instruction), 0, 6000);
        if ($instruction === '') {
            throw ValidationException::withMessages([
                'candidate' => '実行リクエストの内容を確認してください。',
            ]);
        }

        $executionRequest = [
            'schema_version' => self::SCHEMA_VERSION,
            'flow' => self::FLOW,
            'source' => $source,
            'target_plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
            ],
            'target_task' => [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
            ],
            'instruction' => $instruction,
            'actor_type' => $actorType,
            'available_minutes' => $availableMinutes,
            'confirmation' => [
                'state' => 'confirmed',
                'confirmed_by' => 'user',
            ],
        ];

        $context = $this->contexts->snapshot($request, $plan, $task);

        $request->session()->put(self::sessionKey($plan, $task), [
            'context_fingerprint' => (string) $context['context_fingerprint'],
            'actor_type' => $actorType,
            'available_minutes' => $availableMinutes,
            'execution_request' => $executionRequest,
            'packet' => null,
            'handoff_prompt' => null,
        ]);

        return $executionRequest;
    }

    public static function sessionKey(Plan $plan, Task $task): string
    {
        return 'execution_orchestration.'.$plan->id.'.'.$task->id;
    }
}
