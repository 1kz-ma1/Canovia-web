<?php

namespace App\Services;

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
     * The request carries user intent only. Task/Plan remain the source of truth.
     *
     * @return array<string,mixed>
     */
    public function confirmFromInbox(
        Request $request,
        InboxItem $item,
        Plan $plan,
        Task $task,
        string $actorType = 'human_ai',
        ?int $availableMinutes = null,
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

        $instruction = trim((string) (
            $item->content
            ?: $item->title
            ?: $item->source_url
            ?: $item->original_name
            ?: 'このInbox Itemをもとに次の実行内容を整理する'
        ));

        $executionRequest = [
            'schema_version' => self::SCHEMA_VERSION,
            'flow' => self::FLOW,
            'source' => [
                'type' => 'inbox_item',
                'id' => (int) $item->id,
                'title' => $item->displayTitle(),
                'source_type' => (string) $item->source_type,
                'url' => filled($item->source_url) ? (string) $item->source_url : null,
                'file_name' => filled($item->original_name) ? (string) $item->original_name : null,
            ],
            'target_plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
            ],
            'target_task' => [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
            ],
            'instruction' => mb_substr($instruction, 0, 6000),
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
