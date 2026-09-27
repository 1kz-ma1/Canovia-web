<?php

namespace App\Services;

use App\Models\CompanionMutationCandidate;
use App\Models\FutureMemo;
use App\Models\GoalContextFact;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanionMutationApplyService
{
    public function __construct(
        private readonly PlanOwnershipService $ownership,
        private readonly PlanActivityService $activity,
        private readonly GoalContextService $goalContexts,
        private readonly FutureMemoService $futureMemos,
    ) {}

    /**
     * Human-facing preview of what the server will actually consider.
     *
     * @return array{
     *   changes:array<string,mixed>,
     *   blocked_fields:array<int,string>,
     *   target_label:string
     * }
     */
    public function reviewPreview(CompanionMutationCandidate $candidate): array
    {
        $raw = is_array($candidate->payload) ? $candidate->payload : [];
        $allowed = match ($candidate->type) {
            'create_task' => [
                'title', 'description', 'estimated_minutes', 'priority',
                'activation_cost', 'next_action_note',
            ],
            'update_task' => [
                'title', 'description', 'estimated_minutes', 'remaining_minutes',
                'priority', 'activation_cost', 'next_action_note', 'status',
            ],
            'update_plan' => [
                'title', 'description', 'category', 'priority', 'priority_mode',
                'start_date', 'deadline',
            ],
            'record_goal_fact' => [
                'type', 'key', 'label', 'value', 'measurement', 'importance',
            ],
            'create_future_memo' => [
                'kind', 'category', 'content', 'use_for_ai',
            ],
            'create_inbox_item' => [
                'title', 'content',
            ],
            default => [],
        ];

        $changes = array_intersect_key($raw, array_flip($allowed));

        // Completion/progress remains an execution/evidence decision, not a Companion mutation.
        if (
            $candidate->type === 'update_task'
            && array_key_exists('status', $changes)
            && ! in_array((string) $changes['status'], ['todo', 'doing'], true)
        ) {
            unset($changes['status']);
        }

        $blocked = collect(array_keys($raw))
            ->reject(fn (string $key) => array_key_exists($key, $changes))
            ->values()
            ->all();

        return [
            'changes' => $changes,
            'blocked_fields' => $blocked,
            'target_label' => match ($candidate->type) {
                'create_task' => $candidate->plan?->title ? 'Plan: '.$candidate->plan->title : 'Plan未選択',
                'update_task' => $candidate->task?->title ? 'Task: '.$candidate->task->title : 'Task未選択',
                'update_plan', 'record_goal_fact' => $candidate->plan?->title ? 'Plan: '.$candidate->plan->title : 'Plan未選択',
                'create_future_memo' => '未来メモ',
                'create_inbox_item' => $candidate->plan?->title ? 'Inbox / '.$candidate->plan->title : 'Inbox',
                default => 'Canovia',
            },
        ];
    }

    /**
     * @return array{message:string,target_type:string,target_id:int,already_applied:bool}
     */
    public function apply(
        Request $request,
        CompanionMutationCandidate $candidate,
        string $requestId,
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

        return DB::transaction(function () use ($request, $candidate, $requestId, $user) {
            /** @var CompanionMutationCandidate $locked */
            $locked = CompanionMutationCandidate::query()
                ->with(['plan', 'task'])
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless((int) $locked->user_id === (int) $user->id, 404);

            if ($locked->status === CompanionMutationCandidate::STATUS_APPLIED) {
                return [
                    'message' => 'この変更候補はすでに反映済みです。',
                    'target_type' => (string) ($locked->applied_target_type ?: 'unknown'),
                    'target_id' => (int) ($locked->applied_target_id ?: 0),
                    'already_applied' => true,
                ];
            }

            if ($locked->status !== CompanionMutationCandidate::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'candidate' => 'この変更候補は現在反映できません。',
                ]);
            }

            $usedBy = CompanionMutationCandidate::query()
                ->where('apply_request_id', $requestId)
                ->whereKeyNot($locked->id)
                ->first();

            if ($usedBy) {
                throw ValidationException::withMessages([
                    'candidate' => 'この反映リクエストは別の変更候補で使用済みです。',
                ]);
            }

            $preview = $this->reviewPreview($locked);
            if ($preview['changes'] === []) {
                throw ValidationException::withMessages([
                    'candidate' => '安全に反映できる変更項目がありません。内容を確認して見送るか、Companionへ修正を依頼してください。',
                ]);
            }

            $locked->forceFill(['apply_request_id' => $requestId])->save();

            $result = match ($locked->type) {
                'create_task' => $this->applyCreateTask($request, $locked, $preview['changes']),
                'update_task' => $this->applyUpdateTask($request, $locked, $preview['changes']),
                'update_plan' => $this->applyUpdatePlan($request, $locked, $preview['changes']),
                'record_goal_fact' => $this->applyGoalFact($request, $locked, $preview['changes']),
                'create_future_memo' => $this->applyFutureMemo($request, $locked, $preview['changes']),
                'create_inbox_item' => $this->applyInboxItem($request, $locked, $preview['changes']),
                default => throw ValidationException::withMessages([
                    'candidate' => 'この変更候補の種類にはまだ対応していません。',
                ]),
            };

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['apply_audit'] = [
                'apply_request_id' => $requestId,
                'applied_by_user_id' => (int) $user->id,
                'applied_at' => now()->toIso8601String(),
                'normalized_payload' => $preview['changes'],
                'blocked_fields' => $preview['blocked_fields'],
                'before' => $result['before'],
                'after' => $result['after'],
            ];

            $locked->update([
                'status' => CompanionMutationCandidate::STATUS_APPLIED,
                'metadata' => $metadata,
                'applied_target_type' => $result['target_type'],
                'applied_target_id' => $result['target_id'],
                'reviewed_at' => now(),
                'applied_at' => now(),
            ]);

            return [
                'message' => $result['message'],
                'target_type' => $result['target_type'],
                'target_id' => $result['target_id'],
                'already_applied' => false,
            ];
        }, 3);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyCreateTask(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $plan = $this->requirePlan($candidate);
        $this->ownership->authorizeEdit($request, $plan);

        $data = Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:5'],
            'activation_cost' => ['nullable', 'integer', 'min:1', 'max:5'],
            'next_action_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $estimated = (int) ($data['estimated_minutes'] ?? 0);
        $sortOrder = ((int) $plan->tasks()->max('sort_order')) + 1;

        $task = Task::query()->create([
            'plan_id' => (int) $plan->id,
            'title' => trim((string) $data['title']),
            'description' => $this->nullableTrim($data['description'] ?? null),
            'estimated_minutes' => $estimated,
            'remaining_minutes' => $estimated,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => (int) ($data['priority'] ?? 3),
            'activation_cost' => (int) ($data['activation_cost'] ?? 3),
            'next_action_note' => $this->nullableTrim($data['next_action_note'] ?? null),
            'sort_order' => $sortOrder,
        ]);

        $this->activity->record($plan, $request->user(), 'task_created', 'task', (int) $task->id, [
            'task_title' => $task->title,
            'source' => 'canovia_companion',
            'companion_candidate_id' => (int) $candidate->id,
        ]);

        return [
            'message' => 'Task「'.$task->title.'」を追加しました。',
            'target_type' => 'task',
            'target_id' => (int) $task->id,
            'before' => null,
            'after' => $this->taskSnapshot($task),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyUpdateTask(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $task = $this->requireTask($candidate);
        $task->loadMissing('plan');
        $this->ownership->authorizeTask($request, $task);

        $data = Validator::make($payload, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'estimated_minutes' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'remaining_minutes' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'activation_cost' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'next_action_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['todo', 'doing'])],
        ])->validate();

        $before = $this->taskSnapshot($task);
        $changes = [];

        foreach (['title', 'estimated_minutes', 'remaining_minutes', 'priority', 'activation_cost', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $key === 'title'
                    ? trim((string) $data[$key])
                    : $data[$key];
            }
        }
        foreach (['description', 'next_action_note'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $this->nullableTrim($data[$key]);
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['candidate' => 'Taskへ反映できる変更がありません。']);
        }

        $task->update($changes);
        $task->refresh();

        $this->activity->record($task->plan, $request->user(), 'task_updated', 'task', (int) $task->id, [
            'task_title' => $task->title,
            'source' => 'canovia_companion',
            'companion_candidate_id' => (int) $candidate->id,
            'changed_fields' => array_keys($changes),
        ]);

        return [
            'message' => 'Task「'.$task->title.'」へ変更を反映しました。',
            'target_type' => 'task',
            'target_id' => (int) $task->id,
            'before' => $before,
            'after' => $this->taskSnapshot($task),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyUpdatePlan(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $plan = $this->requirePlan($candidate);
        $this->ownership->authorizePlan($request, $plan);

        $data = Validator::make($payload, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'priority_mode' => ['sometimes', Rule::in(['auto', 'manual'])],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'deadline' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $startDate = array_key_exists('start_date', $data)
            ? $data['start_date']
            : $plan->start_date?->format('Y-m-d');
        $deadline = array_key_exists('deadline', $data)
            ? $data['deadline']
            : $plan->deadline?->format('Y-m-d');

        if ($startDate && $deadline && Carbon::parse($deadline)->lt(Carbon::parse($startDate))) {
            throw ValidationException::withMessages([
                'candidate' => '期限は開始日以降にしてください。',
            ]);
        }

        $before = $this->planSnapshot($plan);
        $previousTitle = (string) $plan->title;
        $changes = [];

        foreach (['title', 'priority', 'priority_mode', 'start_date', 'deadline'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $key === 'title'
                    ? trim((string) $data[$key])
                    : $data[$key];
            }
        }
        foreach (['description', 'category'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $this->nullableTrim($data[$key]);
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['candidate' => 'Planへ反映できる変更がありません。']);
        }

        $plan->update($changes);
        $plan->refresh();

        if ((string) $plan->title !== $previousTitle) {
            $this->goalContexts->syncPlanTitle($plan, $previousTitle);
        }

        $this->activity->record($plan, $request->user(), 'plan_updated', 'plan', (int) $plan->id, [
            'source' => 'canovia_companion',
            'companion_candidate_id' => (int) $candidate->id,
            'changed_fields' => array_keys($changes),
        ]);

        return [
            'message' => 'Plan「'.$plan->title.'」へ変更を反映しました。',
            'target_type' => 'plan',
            'target_id' => (int) $plan->id,
            'before' => $before,
            'after' => $this->planSnapshot($plan),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyGoalFact(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $plan = $this->requirePlan($candidate);
        $this->ownership->authorizePlan($request, $plan);

        $data = Validator::make($payload, [
            'type' => ['required', Rule::in(['current_state', 'signal', 'constraint', 'driver'])],
            'key' => ['nullable', 'string', 'max:120'],
            'label' => ['required', 'string', 'max:255'],
            'value' => ['required'],
            'measurement' => ['nullable', 'boolean'],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
        ])->validate();

        $context = $plan->goalContext ?: $this->goalContexts->ensureForPlan($plan, $plan->user_id);
        $before = $this->goalContextSnapshot($context);

        $fact = $this->goalContexts->recordFact(
            $context,
            type: (string) $data['type'],
            label: trim((string) $data['label']),
            value: $data['value'],
            source: 'native_ai',
            state: 'confirmed',
            key: filled($data['key'] ?? null) ? trim((string) $data['key']) : null,
            confidence: 1,
            importance: (int) ($data['importance'] ?? 3),
            metadata: [
                'origin' => 'canovia_companion_human_confirmed',
                'measurement' => (bool) ($data['measurement'] ?? false),
                'companion_candidate_id' => (int) $candidate->id,
                'confirmed_by_user_id' => (int) $request->user()->id,
            ],
        );

        if ((string) $data['type'] === 'current_state') {
            $summary = $this->factText($data['value']);
            if ($summary !== '') {
                $context->update(['current_state_summary' => mb_substr($summary, 0, 2000)]);
                $context = $this->goalContexts->recalculate($context);
            }
        } else {
            $context = $this->goalContexts->recalculate($context);
        }

        return [
            'message' => 'Goal Contextへ「'.$fact->label.'」を確認済みFactとして反映しました。',
            'target_type' => 'goal_context_fact',
            'target_id' => (int) $fact->id,
            'before' => $before,
            'after' => $this->goalContextSnapshot($context),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyFutureMemo(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $data = Validator::make($payload, [
            'kind' => ['nullable', Rule::in(array_keys(FutureMemo::KINDS))],
            'category' => ['nullable', Rule::in(array_keys(FutureMemo::CATEGORIES))],
            'content' => ['required', 'string', 'max:2000'],
            'use_for_ai' => ['nullable', 'boolean'],
        ])->validate();

        $memo = $this->futureMemos->create($request, [
            'kind' => $data['kind'] ?? 'interest',
            'category' => $data['category'] ?? 'other',
            'content' => trim((string) $data['content']),
            'use_for_ai' => (bool) ($data['use_for_ai'] ?? true),
        ]);

        return [
            'message' => '未来メモへ追加しました。',
            'target_type' => 'future_memo',
            'target_id' => (int) $memo->id,
            'before' => null,
            'after' => [
                'id' => (int) $memo->id,
                'kind' => $memo->kind,
                'category' => $memo->category,
                'content' => $memo->content,
                'use_for_ai' => (bool) $memo->use_for_ai,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyInboxItem(
        Request $request,
        CompanionMutationCandidate $candidate,
        array $payload,
    ): array {
        $data = Validator::make($payload, [
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
        ])->validate();

        $plan = $candidate->plan;
        if ($plan) {
            $this->ownership->authorizeEdit($request, $plan);
        }

        $content = trim((string) $data['content']);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = mb_substr(Str::squish($content), 0, 80);
        }

        $item = InboxItem::query()->create([
            'user_id' => (int) $request->user()->id,
            'actor_token' => null,
            'plan_id' => $plan?->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => $title ?: 'Companionからのメモ',
            'content' => $content,
            'metadata' => [
                'origin' => 'canovia_companion_human_confirmed',
                'companion_candidate_id' => (int) $candidate->id,
            ],
        ]);

        return [
            'message' => 'Inboxへ追加しました。整理先はあとから決められます。',
            'target_type' => 'inbox_item',
            'target_id' => (int) $item->id,
            'before' => null,
            'after' => [
                'id' => (int) $item->id,
                'plan_id' => $item->plan_id,
                'title' => $item->title,
                'status' => $item->status,
            ],
        ];
    }

    private function requirePlan(CompanionMutationCandidate $candidate): Plan
    {
        $plan = $candidate->plan;
        if (! $plan) {
            throw ValidationException::withMessages([
                'candidate' => 'この変更候補には対象Planがありません。',
            ]);
        }

        return $plan;
    }

    private function requireTask(CompanionMutationCandidate $candidate): Task
    {
        $task = $candidate->task;
        $plan = $candidate->plan;

        if (! $task || ! $plan || (int) $task->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages([
                'candidate' => 'この変更候補には有効な対象Taskがありません。',
            ]);
        }

        return $task;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function factText(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            foreach (['text', 'value', 'label'] as $key) {
                if (filled($value[$key] ?? null)) {
                    return trim((string) $value[$key]);
                }
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function taskSnapshot(Task $task): array
    {
        return [
            'id' => (int) $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'estimated_minutes' => (int) $task->estimated_minutes,
            'remaining_minutes' => (int) $task->remaining_minutes,
            'progress_percent' => (int) $task->progress_percent,
            'status' => $task->status,
            'priority' => (int) $task->priority,
            'activation_cost' => (int) $task->activation_cost,
            'next_action_note' => $task->next_action_note,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function planSnapshot(Plan $plan): array
    {
        return [
            'id' => (int) $plan->id,
            'title' => $plan->title,
            'description' => $plan->description,
            'category' => $plan->category,
            'priority' => (int) $plan->priority,
            'priority_mode' => $plan->priority_mode,
            'start_date' => $plan->start_date?->format('Y-m-d'),
            'deadline' => $plan->deadline?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function goalContextSnapshot($context): array
    {
        $context = $context->fresh(['facts']);

        return [
            'id' => (int) $context->id,
            'current_state_summary' => $context->current_state_summary,
            'readiness_score' => (int) $context->readiness_score,
            'readiness_state' => $context->readiness_state,
            'confirmed_fact_count' => $context->facts->where('state', 'confirmed')->count(),
        ];
    }
}
