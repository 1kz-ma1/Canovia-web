<?php

namespace App\Services;

use App\Models\CompanionMutationCandidate;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use LogicException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExecutionRequestHandoffService
{
    public const SCHEMA_VERSION = '1.0';
    public const FLOW = 'execution_request';

    public function __construct(
        private readonly ExecutionOrchestrationContextService $contexts,
        private readonly CompanionDerivedEvidenceSourceGuard $derivedSources,
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
        if ((int) $candidate->plan_id !== (int) $plan->id
            || (int) $candidate->task_id !== (int) $task->id
            || ! $this->derivedSources->isCurrent(
                $plan, data_get($candidate->metadata, 'source_provenance'),
            )) {
            throw ValidationException::withMessages([
                'candidate' => 'Companionの元のAI根拠が未検証です。現在の情報で新しい実行候補を作成してください。',
            ]);
        }

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
            publishAfterCommit: true,
        );
    }

    /**
     * Re-deliver a previously committed human-approved Companion request.
     * This NEVER re-applies its Candidate; the saved audit is the approved
     * payload and the same-row lock/lineage fence is held by the caller.
     * A newer Execution session must never be overwritten by an old retry.
     */
    public function recoverCommittedCompanion(
        Request $request,
        CompanionMutationCandidate $candidate,
        Plan $plan,
        Task $task,
        string $requestId,
    ): bool {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Companion handoff recovery requires a database transaction.');
        }

        $audit = data_get($candidate->metadata, 'apply_audit');
        $approved = data_get($audit, 'after');
        $normalized = data_get($audit, 'normalized_payload');
        $payload = is_array($candidate->payload) ? $candidate->payload : [];
        $instruction = trim((string) data_get($approved, 'instruction'));
        $deny = ! is_array($audit) || ! is_array($approved) || ! is_array($normalized)
            || $candidate->type !== 'prepare_execution_request'
            || $candidate->status !== CompanionMutationCandidate::STATUS_APPLIED
            || (string) $candidate->apply_request_id !== $requestId
            || (string) data_get($audit, 'apply_request_id') !== $requestId
            || (int) data_get($audit, 'applied_by_user_id') !== (int) $candidate->user_id
            || $candidate->applied_target_type !== 'execution_request'
            || (int) $candidate->applied_target_id !== (int) $task->id
            || (int) $candidate->plan_id !== (int) $plan->id
            || (int) $candidate->task_id !== (int) $task->id
            || (int) $candidate->user_id !== (int) $plan->user_id
            || data_get($approved, 'source.type') !== 'companion_candidate'
            || (int) data_get($approved, 'source.id') !== (int) $candidate->id
            || (int) data_get($approved, 'source.thread_id') !== (int) $candidate->companion_thread_id
            || (int) data_get($approved, 'target_plan.id') !== (int) $plan->id
            || (int) data_get($approved, 'target_task.id') !== (int) $task->id
            || $instruction === ''
            || $instruction !== trim((string) ($normalized['instruction'] ?? ''))
            || $instruction !== trim((string) ($payload['instruction'] ?? ''))
            || (string) data_get($approved, 'actor_type') !== (string) ($normalized['actor_type'] ?? 'human_ai')
            || (string) data_get($approved, 'actor_type') !== (string) ($payload['actor_type'] ?? 'human_ai')
            || data_get($approved, 'available_minutes') !== ($normalized['available_minutes'] ?? null)
            || data_get($approved, 'available_minutes') !== ($payload['available_minutes'] ?? null)
            || ! $this->sourceIsCurrent($approved, $plan, $task, $request);

        if ($deny) {
            throw ValidationException::withMessages([
                'candidate' => '承認済みの実行内容または根拠を検証できません。新しい候補を作成してください。',
            ]);
        }

        $key = self::sessionKey($plan, $task);
        if ($request->session()->has($key)) {
            // Existing handoff may be from Inbox or a newer Companion.
            // Never reset its Packet, prompt or source on an old retry.
            return false;
        }

        $this->confirm(
            request: $request,
            plan: $plan,
            task: $task,
            source: $approved['source'],
            instruction: $instruction,
            actorType: (string) data_get($approved, 'actor_type'),
            availableMinutes: data_get($approved, 'available_minutes'),
            publishAfterCommit: true,
            onlyIfSessionMissing: true,
        );

        return true;
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

        // A human-confirmed Developer Brief is still a projection of
        // revocable Task/GitHub evidence and Plan membership. Bind the
        // session handoff to the live, actor-specific context generation.
        $context = $this->contexts->freshSnapshot($request, $plan, $task);
        $sourceFingerprint = (string) ($context['context_fingerprint'] ?? '');
        if ($sourceFingerprint === '') {
            throw ValidationException::withMessages([
                'development_handoff' => '現在のDevelopment Contextを確認できませんでした。',
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
                'brief_hash' => hash('sha256', mb_substr($copyText, 0, 6000)),
                'context_fingerprint' => $sourceFingerprint,
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
        bool $publishAfterCommit = false,
        bool $onlyIfSessionMissing = false,
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
        $sessionKey = self::sessionKey($plan, $task);
        $sessionValue = [
            'context_fingerprint' => (string) $context['context_fingerprint'],
            'actor_type' => $actorType,
            'available_minutes' => $availableMinutes,
            'execution_request' => $executionRequest,
            'packet' => null,
            'handoff_prompt' => null,
        ];

        // A confirmed Companion candidate is still pending in the database
        // until the enclosing approval transaction commits. Publishing its
        // session-scoped Execution Request here can leak an uncommitted
        // approval through deadlock retries or a later exception.
        $publish = static function () use (
            $request, $sessionKey, $sessionValue, $onlyIfSessionMissing,
        ): void {
            if (! $onlyIfSessionMissing || ! $request->session()->has($sessionKey)) {
                $request->session()->put($sessionKey, $sessionValue);
            }
        };

        if ($publishAfterCommit) {
            if (DB::transactionLevel() === 0) {
                throw new LogicException('Companion Execution handoff requires a database transaction.');
            }

            // Laravel discards this callback when any enclosing transaction
            // rolls back, including a retried deadlock attempt. Only the
            // successful committed attempt may publish to the session.
            DB::afterCommit($publish);
        } else {
            // Human-authored Inbox and Development Brief handoffs have no
            // Companion Candidate approval transaction and keep their
            // existing synchronous session behavior.
            $publish();
        }

        return $executionRequest;
    }

    /**
     * A prior human approval is not an ongoing authorization to reuse a
     * Companion-generated instruction whose underlying GitHub Evidence
     * disappeared or changed. A confirmed Development Implementation Brief
     * is also derived from revocable GitHub/Task/Owner context, unlike an
     * independently authored Inbox request.
     *
     * @param array<string,mixed>|null $executionRequest
     */
    public function sourceIsCurrent(
        ?array $executionRequest,
        Plan $plan,
        Task $task,
        ?Request $request = null,
    ): bool {
        $sourceType = data_get($executionRequest, 'source.type');
        if ($sourceType === 'development_implementation_brief') {
            $expected = data_get($executionRequest, 'source.context_fingerprint');
            $briefHash = data_get($executionRequest, 'source.brief_hash');
            $instruction = trim((string) data_get($executionRequest, 'instruction'));
            if ($request === null
                || (int) $task->plan_id !== (int) $plan->id
                || (int) data_get($executionRequest, 'target_plan.id') !== (int) $plan->id
                || (int) data_get($executionRequest, 'target_task.id') !== (int) $task->id
                || ! is_string($expected) || $expected === ''
                || ! is_string($briefHash) || $briefHash === ''
                || $instruction === '' || ! hash_equals($briefHash, hash('sha256', $instruction))
            ) {
                return false;
            }

            try {
                $live = $this->contexts->freshSnapshot($request, $plan, $task);
                $current = (string) ($live['context_fingerprint'] ?? '');

                return $current !== '' && hash_equals($expected, $current);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($sourceType !== 'companion_candidate') {
            return true;
        }

        $candidateId = (int) data_get($executionRequest, 'source.id', 0);
        if ($candidateId <= 0 || (int) $task->plan_id !== (int) $plan->id
            || (int) data_get($executionRequest, 'target_plan.id') !== (int) $plan->id
            || (int) data_get($executionRequest, 'target_task.id') !== (int) $task->id) {
            return false;
        }

        $candidate = CompanionMutationCandidate::query()->find($candidateId);
        if (! $candidate || $candidate->type !== 'prepare_execution_request'
            || $candidate->status !== CompanionMutationCandidate::STATUS_APPLIED
            || (int) $candidate->user_id !== (int) $plan->user_id
            || (int) $candidate->plan_id !== (int) $plan->id
            || (int) $candidate->task_id !== (int) $task->id
            || (int) data_get($executionRequest, 'source.thread_id')
                !== (int) $candidate->companion_thread_id
            || trim((string) data_get($executionRequest, 'instruction'))
                !== trim((string) data_get($candidate->payload, 'instruction'))) {
            return false;
        }

        return $this->derivedSources->isCurrent(
            $plan, data_get($candidate->metadata, 'source_provenance'),
        );
    }

    public static function sessionKey(Plan $plan, Task $task): string
    {
        return 'execution_orchestration.'.$plan->id.'.'.$task->id;
    }
}
