<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanAdjustment;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\WorkSession;
use App\Services\AiJsonInputNormalizer;
use App\Services\PlanProgressService;
use App\Services\PlanReviewJsonCompatibilityService;
use App\Services\PlanActivityService;
use Carbon\Carbon;
use App\Services\PlanOwnershipService;
use App\Services\RoadmapService;
use App\Services\FutureMemoService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanReviewAssistantController extends Controller
{
    public function show(Request $request, Plan $plan, PlanProgressService $progressService, FutureMemoService $futureMemoService)
    {
        $this->authorizePlanOwner($plan);

        $plan->load([
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id')->limit(10),
            'adjustments' => fn ($query) => $query->latest('applied_at')->limit(10),
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $draft = $request->session()->get($this->draftSessionKey($plan));
        $proposal = $request->session()->get($this->proposalSessionKey($plan));
        $flow = 'result_recording';
        $requestedWorkSessionId = $request->integer('work_session_id') ?: null;

        if ($requestedWorkSessionId && (int) ($draft['work_session_id'] ?? 0) !== $requestedWorkSessionId) {
            $request->session()->forget([
                $this->draftSessionKey($plan),
                $this->proposalSessionKey($plan),
            ]);
            $draft = null;
            $proposal = null;
        }

        $workSessionId = $requestedWorkSessionId ?: ($draft['work_session_id'] ?? null);
        $workSessionContext = $this->resolveWorkSessionContext($plan, $workSessionId);
        $workSessionLog = $workSessionContext
            ? WorkLog::query()->where('work_session_id', $workSessionContext->id)->first()
            : null;

        return view('plans.review_assistant', [
            'plan' => $plan,
            'progress' => $progressService->calculate($plan),
            'draft' => $draft,
            'proposal' => $proposal,
            'flow' => $flow,
            'workSessionContext' => $workSessionContext,
            'workSessionLog' => $workSessionLog,
            'futureMemos' => $futureMemoService->all($request, true),
        ]);
    }

    public function generatePrompt(Request $request, Plan $plan, PlanProgressService $progressService, FutureMemoService $futureMemoService)
    {
        $this->authorizePlanOwner($plan);

        $validated = $request->validate([
            'flow' => ['nullable', 'in:plan_update,result_recording'],
            'work_session_id' => ['nullable', 'integer', 'min:1'],
            'activity_summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $plan->load([
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id')->limit(10),
            'adjustments' => fn ($query) => $query->latest('applied_at')->limit(10),
            'availabilityRules',
            'availabilityOverrides',
        ]);

        $workSession = $this->resolveWorkSessionContext($plan, $validated['work_session_id'] ?? null);
        $workLog = $workSession
            ? WorkLog::query()->where('work_session_id', $workSession->id)->first()
            : null;
        $activitySummary = trim((string) ($validated['activity_summary'] ?? ''));

        $draft = [
            'flow' => 'result_recording',
            'source_context' => $workSession ? 'work_session' : 'manual',
            'work_session_id' => $workSession?->id,
            'task_id' => $workSession?->task_id,
            'worked_on' => $workSession ? ($workLog?->worked_on?->format('Y-m-d') ?? now()->toDateString()) : null,
            'actual_minutes' => $workLog?->actual_minutes,
            'difficulty' => $workLog?->difficulty,
            'activity_summary' => $activitySummary,
            'discoveries' => '',
            'desired_outcome' => '現在の会話とCanoviaの記録を使い、必要な計画更新を判断してほしい',
            'work_session_facts' => $workSession ? [
                'started_at' => $workSession->started_at?->toIso8601String(),
                'ended_at' => $workSession->ended_at?->toIso8601String(),
                'active_seconds' => $workSession->actual_seconds,
                'paused_seconds' => $workSession->paused_seconds,
                'intended_minutes' => $workSession->intended_minutes,
                'task_title' => $workSession->task?->title,
                'work_log_recorded' => $workLog !== null,
            ] : null,
        ];

        $draft['prompt'] = $this->buildPrompt(
            $plan,
            $progressService->calculate($plan),
            $draft,
            $futureMemoService->promptContext($request),
        );

        $request->session()->put($this->draftSessionKey($plan), $draft);
        $request->session()->forget($this->proposalSessionKey($plan));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'redirect' => route('plans.review_assistant.show', $plan),
                'message' => '外部AIに送るプロンプトを生成しました。',
            ]);
        }

        return redirect()
            ->route('plans.review_assistant.show', $plan)
            ->with('status', '外部AIに送るプロンプトを生成しました。');
    }

    public function preview(Request $request, Plan $plan)
    {
        $this->authorizePlanOwner($plan);

        $validated = $request->validate([
            'operations_json' => ['required', 'string', 'max:100000'],
        ]);

        $draft = $request->session()->get($this->draftSessionKey($plan));

        if (! $draft || empty($draft['prompt'])) {
            throw ValidationException::withMessages([
                'operations_json' => '先に作業内容を入力し、外部AI用プロンプトを生成してください。',
            ]);
        }

        try {
            [$jsonText, $decoded] = $this->decodeJsonDocument($validated['operations_json']);
            $decoded = app(PlanReviewJsonCompatibilityService::class)->adapt($plan, $decoded, $draft);
            $jsonText = json_encode(
                $decoded,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            $this->validateTargetPlanDescriptor($plan, $decoded);
            $action = $this->resolveJsonAction($decoded)
                ?? $this->inferPlanReviewAction($decoded);
            $operations = $this->normalizeDecodedOperations($plan, $decoded, allowTaskList: true, action: $action);

            $proposal = $this->buildProposal($plan, $decoded, $operations, $jsonText, $action);
        } catch (ValidationException $exception) {
            // Do not log the pasted JSON itself. The validation message and
            // plan context are enough to diagnose compatibility gaps safely.
            Log::warning('plan_review_preview_validation_failed', [
                'plan_id' => $plan->id,
                'work_session_id' => $draft['work_session_id'] ?? null,
                'errors' => $exception->errors(),
            ]);
            throw $exception;
        }
        $request->session()->put($this->proposalSessionKey($plan), $proposal);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'redirect' => route('plans.review_assistant.show', $plan),
            ]);
        }

        return redirect()
            ->route('plans.review_assistant.show', $plan)
            ->with('status', '変更内容を読み込みました。反映前に差分を確認してください。');
    }

    public function previewFromDashboard(Request $request)
    {
        $validated = $request->validate([
            'operations_json' => ['required', 'string', 'max:100000'],
        ]);

        [$jsonText, $decoded] = $this->decodeJsonDocument($validated['operations_json']);
        $plan = $this->resolveDashboardTargetPlan($request, $decoded, allowMissing: true);
        $action = $this->resolveJsonAction($decoded);

        if (! $plan || $action === null) {
            $this->storePendingDashboardImport($request, $plan, $decoded, $jsonText, $action);

            $missing = [];

            if (! $plan) {
                $missing[] = '対象計画';
            }

            if ($action === null) {
                $missing[] = '操作目的';
            }

            return redirect()
                ->route('home')
                ->with('status', implode('と', $missing) . 'を確認してください。選択後に安全性を検証します。');
        }

        return $this->storeDashboardProposal($request, $plan, $decoded, $jsonText, $action);
    }

    public function resolveLegacyFromDashboard(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'in:append_tasks,restructure_plan,update_progress'],
        ]);

        $pending = $request->session()->get('dashboard_ai_json.pending');

        if (! is_array($pending) || empty($pending['raw_json'])) {
            throw ValidationException::withMessages([
                'operations_json' => '確認対象のJSONが見つかりません。もう一度貼り付けてください。',
            ]);
        }

        $pendingPlanId = (int) ($pending['plan_id'] ?? 0);
        $selectedPlanId = (int) ($validated['plan_id'] ?? 0);
        $planId = $pendingPlanId > 0 ? $pendingPlanId : $selectedPlanId;

        if ($planId < 1) {
            throw ValidationException::withMessages([
                'plan_id' => 'このJSONを適用する計画を選択してください。',
            ]);
        }

        if ($pendingPlanId > 0 && $selectedPlanId > 0 && $selectedPlanId !== $pendingPlanId) {
            throw ValidationException::withMessages([
                'plan_id' => 'JSONから識別済みの対象計画と、選択された計画が一致しません。',
            ]);
        }

        $plan = Plan::find($planId);

        if (! $plan || ! $this->requestOwnsPlan($request, $plan)) {
            $request->session()->forget('dashboard_ai_json.pending');

            throw ValidationException::withMessages([
                'plan_id' => '対象計画が見つからないか、このブラウザから編集できません。',
            ]);
        }

        $decoded = json_decode((string) $pending['raw_json'], true);

        if (! is_array($decoded)) {
            $request->session()->forget('dashboard_ai_json.pending');

            throw ValidationException::withMessages([
                'operations_json' => '保存していたJSONを読み直せませんでした。もう一度貼り付けてください。',
            ]);
        }

        $descriptor = $this->extractTargetPlanDescriptor($decoded);
        $descriptorId = $descriptor['id'] ?? null;
        $descriptorTitle = $this->normalizeRequiredText($descriptor['title'] ?? null);

        if (($descriptorId === null || $descriptorId === '') && $descriptorTitle === null) {
            $decoded['target_plan'] = [
                'id' => $plan->id,
                'title' => $plan->title,
                'category' => $plan->category,
            ];
        } else {
            $this->validateTargetPlanDescriptor($plan, $decoded, required: true);
        }

        $existingAction = $this->resolveJsonAction($decoded);
        $selectedAction = $validated['action'] ?? null;

        if ($existingAction !== null && $selectedAction !== null && $existingAction !== $selectedAction) {
            throw ValidationException::withMessages([
                'action' => 'JSONに記載された操作目的と、選択された操作目的が一致しません。',
            ]);
        }

        $action = $existingAction ?? $selectedAction;

        if ($action === null) {
            throw ValidationException::withMessages([
                'action' => 'このJSONで行う操作目的を選択してください。',
            ]);
        }

        if (! array_key_exists('schema_version', $decoded) || trim((string) $decoded['schema_version']) === '') {
            $decoded['schema_version'] = '1.1';
        }

        $decoded['action'] = $action;
        $jsonText = json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return $this->storeDashboardProposal(
            $request,
            $plan,
            $decoded,
            $jsonText,
            $action
        );
    }

    public function resetFromDashboard(Request $request)
    {
        $planId = (int) $request->session()->get('dashboard_ai_json.plan_id', 0);
        $pendingPlanId = (int) data_get($request->session()->get('dashboard_ai_json.pending'), 'plan_id', 0);

        foreach (array_unique(array_filter([$planId, $pendingPlanId])) as $targetPlanId) {
            $request->session()->forget([
                'plan_review_drafts.' . $targetPlanId,
                'plan_review_proposals.' . $targetPlanId,
            ]);
        }

        $request->session()->forget([
            'dashboard_ai_json.plan_id',
            'dashboard_ai_json.pending',
        ]);

        return redirect()
            ->route('home')
            ->with('status', 'ダッシュボードのAI JSON読み込み内容を取り消しました。');
    }

    public function apply(Request $request, Plan $plan, PlanProgressService $progressService, PlanActivityService $activity)
    {
        $this->authorizePlanOwner($plan);

        $validated = $request->validate([
            'proposal_token' => ['required', 'string'],
            'selected_operations' => ['required', 'array', 'min:1'],
            'selected_operations.*' => ['integer', 'min:0'],
            'return_to' => ['nullable', 'in:dashboard,plan'],
        ]);

        $requestHash = hash('sha256', 'plan_review_apply|'.$plan->id.'|'.$validated['proposal_token']);

        // A retry after a successful apply may arrive after the proposal has
        // already been removed from the session. Check the durable adjustment
        // first so that retry converges on the previous success.
        if (PlanAdjustment::query()
            ->where('plan_id', $plan->id)
            ->where('request_hash', $requestHash)
            ->exists()) {
            return ($validated['return_to'] ?? null) === 'dashboard'
                ? redirect()->route('home')->with('success', "#{$plan->id} {$plan->title} はすでに更新済みです。")
                : redirect()->route('plans.show', $plan)->with('success', 'この変更はすでに反映済みです。重複更新はしていません。');
        }

        $draft = $request->session()->get($this->draftSessionKey($plan));
        $proposal = $request->session()->get($this->proposalSessionKey($plan));

        if (! $draft || ! $proposal || ! hash_equals((string) $proposal['token'], $validated['proposal_token'])) {
            throw ValidationException::withMessages([
                'selected_operations' => '変更案の有効期限が切れています。JSONをもう一度読み込んでください。',
            ]);
        }

        if (! ($proposal['can_apply'] ?? true)) {
            throw ValidationException::withMessages([
                'selected_operations' => 'このJSONには未処理の既存タスクまたは不足情報があります。警告内容を修正してから再度読み込んでください。',
            ]);
        }

        $selectedIndexes = collect($validated['selected_operations'])
            ->map(fn ($index) => (int) $index)
            ->unique()
            ->sort()
            ->values();

        $operations = collect($proposal['operations']);

        if ($selectedIndexes->contains(fn ($index) => ! $operations->has($index))) {
            throw ValidationException::withMessages([
                'selected_operations' => '存在しない変更項目が選択されています。',
            ]);
        }

        if (($proposal['action'] ?? null) === 'restructure_plan') {
            $requiredIndexes = $operations->keys()->map(fn ($index) => (int) $index)->values();

            if ($selectedIndexes->count() !== $requiredIndexes->count()
                || $selectedIndexes->diff($requiredIndexes)->isNotEmpty()
                || $requiredIndexes->diff($selectedIndexes)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'selected_operations' => '計画再編は一部だけ反映できません。すべての操作をまとめて反映してください。',
                ]);
            }
        }

        $selectedOperations = $selectedIndexes
            ->map(fn ($index) => $operations->get($index))
            ->values()
            ->all();

        $this->validateSelectedTaskReferences($selectedOperations);

        $plan->loadMissing(['tasks', 'workLogs']);
        $metricsBefore = $progressService->calculate($plan);

        $appliedNow = DB::transaction(function () use ($plan, $draft, $proposal, $selectedOperations, $metricsBefore, $progressService, $requestHash) {
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (PlanAdjustment::query()
                ->where('plan_id', $plan->id)
                ->where('request_hash', $requestHash)
                ->exists()) {
                return false;
            }

            $createdTaskMap = [];
            $appliedOperations = [];
            $currentMaxSortOrder = (int) $plan->tasks()->max('sort_order');

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'update_plan') {
                    continue;
                }

                $changes = Arr::only($operation, [
                    'title',
                    'description',
                    'category',
                    'start_date',
                    'deadline',
                    'is_public',
                ]);
                $before = $plan->only(array_keys($changes));
                $plan->update($changes);

                $appliedOperations[] = array_merge($operation, ['before' => $before]);
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'update_availability') {
                    continue;
                }

                if ($operation['replace_weekly'] ?? true) {
                    $plan->availabilityRules()->delete();
                }

                foreach (($operation['weekly_schedule'] ?? []) as $dayOfWeek => $item) {
                    $plan->availabilityRules()->updateOrCreate(
                        ['day_of_week' => (int) $dayOfWeek],
                        [
                            'available_minutes' => (int) ($item['available_minutes'] ?? 0),
                            'is_optional' => (bool) ($item['is_optional'] ?? false),
                        ]
                    );
                }

                foreach (($operation['overrides'] ?? []) as $item) {
                    $plan->availabilityOverrides()->updateOrCreate(
                        ['date' => $item['date']],
                        [
                            'available_minutes' => (int) $item['available_minutes'],
                            'note' => $item['note'] ?: null,
                        ]
                    );
                }

                $appliedOperations[] = $operation;
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'create_task') {
                    continue;
                }

                $currentMaxSortOrder++;

                $task = Task::create([
                    'plan_id' => $plan->id,
                    'title' => $operation['title'],
                    'description' => $operation['description'] ?: null,
                    'estimated_minutes' => $operation['estimated_minutes'],
                    'remaining_minutes' => $operation['remaining_minutes'],
                    'progress_percent' => $operation['progress_percent'],
                    'progress_reason' => $operation['progress_reason'] ?: null,
                    'next_action_note' => ($operation['next_action_note'] ?? '') ?: null,
                    'status' => $operation['status'],
                    'priority' => $operation['priority'],
                    'activation_cost' => $operation['activation_cost'] ?? 3,
                    'continuation_of_task_id' => count($operation['source_task_ids'] ?? []) === 1 ? (int) $operation['source_task_ids'][0] : null,
                    'lineage_source_task_ids' => ! empty($operation['source_task_ids']) ? array_values(array_map('intval', $operation['source_task_ids'])) : null,
                    'lineage_source_snapshots' => ! empty($operation['source_task_snapshots']) ? $operation['source_task_snapshots'] : null,
                    'sort_order' => $currentMaxSortOrder,
                ]);

                if (! empty($operation['client_ref'])) {
                    $createdTaskMap[$operation['client_ref']] = $task;
                }

                $appliedOperations[] = array_merge($operation, ['created_task_id' => $task->id]);
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'create_work_log') {
                    continue;
                }

                $task = null;

                if (! empty($operation['task_id'])) {
                    $task = $plan->tasks()->whereKey($operation['task_id'])->lockForUpdate()->firstOrFail();
                } elseif (! empty($operation['task_ref'])) {
                    $task = $createdTaskMap[$operation['task_ref']] ?? null;
                }

                $progressBefore = $task?->progress_percent;
                $remainingBefore = $task?->remaining_minutes;
                $progressAfter = $operation['progress_after_percent'] ?? $progressBefore;
                $remainingAfter = $operation['remaining_minutes_after'] ?? $remainingBefore;

                $workLog = WorkLog::create([
                    'plan_id' => $plan->id,
                    'task_id' => $task?->id,
                    'task_title_snapshot' => $task?->title,
                    'worked_on' => $operation['worked_on'],
                    'actual_minutes' => $operation['actual_minutes'],
                    'progress_delta_percent' => 0,
                    'progress_before_percent' => $progressBefore,
                    'progress_after_percent' => $progressAfter,
                    'remaining_minutes_before' => $remainingBefore,
                    'remaining_minutes_after' => $remainingAfter,
                    'difficulty' => $operation['difficulty'] ?: null,
                    'memo' => $operation['memo'] ?: null,
                    'outcome' => $operation['outcome'] ?: null,
                ]);

                if ($task && $operation['progress_after_percent'] !== null) {
                    $task->update([
                        'progress_percent' => $progressAfter,
                        'remaining_minutes' => $remainingAfter,
                        'progress_reason' => $operation['progress_reason'] ?: $task->progress_reason,
                        'status' => $this->resolveTaskStatus($progressAfter),
                    ]);
                }

                $appliedOperations[] = array_merge($operation, [
                    'created_work_log_id' => $workLog->id,
                    'resolved_task_id' => $task?->id,
                ]);
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'update_task') {
                    continue;
                }

                $task = $plan->tasks()->whereKey($operation['task_id'])->lockForUpdate()->firstOrFail();
                $changes = Arr::only($operation, [
                    'title',
                    'description',
                    'estimated_minutes',
                    'remaining_minutes',
                    'progress_percent',
                    'progress_reason',
                    'next_action_note',
                    'status',
                    'priority',
                    'activation_cost',
                ]);

                if (($changes['status'] ?? null) === 'done' && ! array_key_exists('progress_percent', $changes)) {
                    $changes['progress_percent'] = 100;
                }

                if (($changes['progress_percent'] ?? null) === 100 && ! array_key_exists('status', $changes)) {
                    $changes['status'] = 'done';
                }

                if (($changes['status'] ?? null) === 'done' || ($changes['progress_percent'] ?? null) === 100) {
                    $changes['remaining_minutes'] = 0;
                }

                $before = $task->only(array_keys($changes));
                $task->update($changes);

                $appliedOperations[] = array_merge($operation, ['before' => $before]);
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'cancel_task') {
                    continue;
                }

                $task = $plan->tasks()->whereKey($operation['task_id'])->lockForUpdate()->firstOrFail();
                $beforeStatus = $task->status;
                $task->update(['status' => 'cancelled']);

                $appliedOperations[] = array_merge($operation, ['before_status' => $beforeStatus]);
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'reorder_tasks') {
                    continue;
                }

                foreach ($operation['items'] as $sortOrder => $item) {
                    $task = ! empty($item['task_id'])
                        ? $plan->tasks()->whereKey($item['task_id'])->lockForUpdate()->firstOrFail()
                        : ($createdTaskMap[$item['task_ref']] ?? null);

                    if (! $task) {
                        throw ValidationException::withMessages([
                            'selected_operations' => '後続タスク再編で参照された新規タスクを解決できません。',
                        ]);
                    }

                    $task->update(['sort_order' => $sortOrder + 1]);
                }

                $appliedOperations[] = $operation;
            }

            foreach ($selectedOperations as $operation) {
                if ($operation['type'] !== 'keep_task') {
                    continue;
                }

                $appliedOperations[] = $operation;
            }

            $plan->unsetRelation('tasks');
            $plan->unsetRelation('workLogs');
            $plan->unsetRelation('availabilityRules');
            $plan->unsetRelation('availabilityOverrides');
            $metricsAfter = $progressService->calculate($plan);

            PlanAdjustment::create([
                'plan_id' => $plan->id,
                'flow' => $proposal['flow'] ?? 'plan_update',
                'request_hash' => $requestHash,
                'summary' => $proposal['summary'],
                'user_input' => Arr::except($draft, ['prompt']),
                'prompt' => $draft['prompt'],
                'response_json' => $proposal['raw_json'],
                'applied_operations' => $appliedOperations,
                'metrics_before' => $metricsBefore,
                'metrics_after' => $metricsAfter,
                'applied_at' => now(),
            ]);

            return true;
        });

        if (! $appliedNow) {
            $request->session()->forget([
                $this->draftSessionKey($plan),
                $this->proposalSessionKey($plan),
            ]);

            return ($validated['return_to'] ?? null) === 'dashboard'
                ? redirect()->route('home')->with('success', "#{$plan->id} {$plan->title} はすでに更新済みです。")
                : redirect()->route('plans.show', $plan)->with('success', 'この変更はすでに反映済みです。重複更新はしていません。');
        }

        if (! empty($draft['work_session_id'])) {
            WorkSession::query()
                ->where('plan_id', $plan->id)
                ->whereKey((int) $draft['work_session_id'])
                ->update(['needs_plan_update' => false, 'plan_updated_at' => now()]);
        }

        $activity->record($plan, $request->user(), 'plan_ai_updated', 'plan', (int) $plan->id, [
            'operation_count' => count($selectedOperations),
        ]);

        $request->session()->forget([
            $this->draftSessionKey($plan),
            $this->proposalSessionKey($plan),
        ]);

        if (($validated['return_to'] ?? null) === 'dashboard') {
            $request->session()->forget('dashboard_ai_json.plan_id');

            return redirect()
                ->route('home')
                ->with('success', "#{$plan->id} {$plan->title} に、選択したAI提案を反映しました。");
        }

        return redirect()
            ->route('plans.show', $plan)
            ->with('success', '選択した計画・作業ログ・タスク・進捗変更を反映しました。');
    }

    public function reset(Request $request, Plan $plan)
    {
        $this->authorizePlanOwner($plan);
        $workSessionId = $request->integer('work_session_id') ?: null;

        if ($workSessionId) {
            $this->resolveWorkSessionContext($plan, $workSessionId);
        }

        $request->session()->forget([
            $this->draftSessionKey($plan),
            $this->proposalSessionKey($plan),
        ]);

        $redirect = route('plans.review_assistant.show', array_filter([
            'plan' => $plan,
            'work_session_id' => $workSessionId,
        ]));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'redirect' => $redirect,
                'message' => '入力中の見直し内容をリセットしました。',
            ]);
        }

        return redirect($redirect)
            ->with('status', '入力中の見直し内容をリセットしました。');
    }

    private function buildPrompt(Plan $plan, array $progress, array $draft, string $futureMemoContext): string
    {
        $taskLines = $plan->tasks->toBase()->map(function (Task $task) {
            return sprintf(
                '- ID:%d | %s | 状態:%s | 絶対進捗:%d%% | 総想定:%d分 | 残り:%d分 | 優先度:%d | 次回再開:%s | 系譜元:%s | 進捗根拠:%s | 説明:%s',
                $task->id,
                $task->title,
                $task->status,
                $task->progress_percent,
                $task->estimated_minutes,
                $task->remaining_minutes ?? max((int) round($task->estimated_minutes * (100 - $task->progress_percent) / 100), 0),
                $task->priority,
                $task->next_action_note ?: '未記録',
                $task->lineage_source_task_ids ? implode(',', $task->lineage_source_task_ids) : ($task->continuation_of_task_id ?: 'なし'),
                $task->progress_reason ?: '未記録',
                $task->description ?: 'なし'
            );
        })->implode("\n");

        $taskLines = $taskLines !== '' ? $taskLines : '- 現在タスクはありません';

        $recentLogLines = $plan->workLogs->toBase()->map(function (WorkLog $workLog) {
            return sprintf(
                '- %s | タスク:%s | %d分 | 現在地:%s | 結果:%s',
                Carbon::parse($workLog->worked_on)->format('Y-m-d'),
                $workLog->task?->title ?? '計画全体',
                $workLog->actual_minutes,
                $workLog->progress_after_percent !== null ? $workLog->progress_after_percent . '%' : '未評価',
                $workLog->outcome ?: ($workLog->memo ?: 'なし')
            );
        })->implode("\n");

        $recentLogLines = $recentLogLines !== '' ? $recentLogLines : '- 作業ログはありません';

        $adjustmentLines = $plan->adjustments->toBase()->map(function (PlanAdjustment $adjustment) {
            return sprintf(
                '- %s | %s',
                $adjustment->applied_at?->format('Y-m-d H:i') ?? '日時不明',
                $adjustment->summary ?: '計画変更を適用'
            );
        })->implode("\n");

        $adjustmentLines = $adjustmentLines !== '' ? $adjustmentLines : '- 適用済みの方針変更履歴はありません';

        $dayLabels = [0 => '日', 1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土'];
        $availabilityLines = $plan->availabilityRules->sortBy('day_of_week')->map(function ($rule) use ($dayLabels) {
            $optional = $rule->is_optional ? '（余裕がある場合）' : '';
            return '- ' . ($dayLabels[$rule->day_of_week] ?? $rule->day_of_week) . '曜: ' . $rule->available_minutes . '分' . $optional;
        })->implode("\n");
        $overrideLines = $plan->availabilityOverrides->sortBy('date')->take(20)->map(function ($override) {
            return '- ' . $override->date->format('Y-m-d') . ': ' . $override->available_minutes . '分' . ($override->note ? ' / ' . $override->note : '');
        })->implode("\n");
        $availabilityText = ($availabilityLines !== '' ? $availabilityLines : '- 通常週の作業可能時間は未設定')
            . "\n例外日:\n"
            . ($overrideLines !== '' ? $overrideLines : '- 例外日は未設定');

        $selectedTask = ! empty($draft['task_id'])
            ? $plan->tasks->firstWhere('id', (int) $draft['task_id'])
            : null;
        $isWorkSessionContext = ($draft['source_context'] ?? null) === 'work_session';
        $selectedTaskText = $selectedTask
            ? "ID:{$selectedTask->id} {$selectedTask->title}（Canoviaが今回のWorkSessionから特定した対象Task）"
            : '特定タスクなし。既存タスクへ無理に紐付けず、計画全体の更新として判断する';

        $difficultyLabels = [
            'easy' => '順調',
            'normal' => '普通',
            'hard' => '苦戦',
            'stuck' => '詰まっている',
        ];

        $difficultyCode = $draft['difficulty'] ?? null;
        $difficulty = $difficultyLabels[$difficultyCode ?? ''] ?? '未指定';
        $actualMinutesValue = isset($draft['actual_minutes']) && $draft['actual_minutes'] !== null
            ? (int) $draft['actual_minutes']
            : null;
        $actualMinutesText = $actualMinutesValue !== null ? $actualMinutesValue . '分' : 'Canovia側では未記録';
        $workedOnText = ! empty($draft['worked_on']) ? $draft['worked_on'] : '未指定（AIが現在の会話・ユーザーの説明から必要に応じて判断）';
        $activitySummary = trim((string) ($draft['activity_summary'] ?? ''));
        $activitySummaryText = $activitySummary !== ''
            ? $activitySummary
            : ($isWorkSessionContext
                ? '追加説明はまだありません。今回のWorkSessionの事実はCanoviaが記録済みです。現在の会話に作業内容があれば再利用し、なければ今回どこまで進んだか・何が分かったかを自由形式で一度だけ尋ねてください。'
                : '今回の報告はまだ入力されていません。現在の会話にこの計画の最新実績・発見・予定変更があればそれを再利用し、なければ最初に「今回この計画について何がありましたか？」と自由形式で尋ねてください。');
        $discoveries = trim((string) ($draft['discoveries'] ?? '')) ?: '追加情報なし';
        $desiredOutcome = trim((string) ($draft['desired_outcome'] ?? '')) ?: '現在の会話とCanoviaの記録を使い、必要な計画更新を判断してほしい';
        $description = $plan->description ?: '未設定';
        $category = $plan->category ?: '未設定';
        $deadlineText = $plan->deadline?->format('Y-m-d') ?? '未設定';
        $expectedProgressText = $progress['expected_progress_percent'] === null ? '未設定' : $progress['expected_progress_percent'] . '%';
        $remainingDaysText = $progress['remaining_days'] === null ? '未設定' : $progress['remaining_days'] . '日';
        $targetTitleJson = json_encode($plan->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $targetCategoryJson = json_encode($plan->category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $flow = 'result_recording';
        $flowInstruction = '今回は「進捗管理」です。今回の実績・発見・予定との違い・方針変更をひとまとまりの最新事実として扱い、必要な実績記録、絶対進捗、残り時間、計画概要、優先順位、後続タスクを同時に更新してください。ユーザーに「進捗報告」か「方針変更」かを分類させる必要はありません。';
        $sessionFacts = $draft['work_session_facts'] ?? null;
        $sessionFactsText = 'なし。通常の計画更新として、現在の会話から最新の出来事を把握してください。';
        if ($isWorkSessionContext && is_array($sessionFacts)) {
            $activeMinutes = $actualMinutesValue ?? max(1, (int) ceil(((int) ($sessionFacts['active_seconds'] ?? 0)) / 60));
            $pausedMinutes = max(0, (int) floor(((int) ($sessionFacts['paused_seconds'] ?? 0)) / 60));
            $intendedMinutes = $sessionFacts['intended_minutes'] ?? null;
            $intendedText = $intendedMinutes ? $intendedMinutes . '分' : '未指定';
            $sessionFactsText = <<<TEXT
WorkSession ID: {$draft['work_session_id']}
対象Task: {$selectedTaskText}
開始: {$sessionFacts['started_at']}
終了: {$sessionFacts['ended_at']}
実作業時間: {$activeMinutes}分
一時停止時間: {$pausedMinutes}分
開始時の目安時間: {$intendedText}
WorkLog保存済み: はい
TEXT;
        }

        if ($isWorkSessionContext && $selectedTask) {
            $exampleOperation = <<<JSON
    {
      "type": "revise_task",
      "task_id": {$selectedTask->id},
      "progress_percent": 65,
      "remaining_minutes": 180,
      "status": "doing",
      "estimated_minutes": {$selectedTask->estimated_minutes},
      "priority": {$selectedTask->priority},
      "progress_reason": "今回のWorkSessionとユーザーの説明から確認できた現在地",
      "reason": "保存済みWorkLogを重複させず、Taskの現在地だけを更新するため"
    }
JSON;
        } elseif ($exampleTask = $plan->tasks->first()) {
            $exampleRemaining = $exampleTask->remaining_minutes
                ?? max((int) round($exampleTask->estimated_minutes * (100 - $exampleTask->progress_percent) / 100), 0);
            $exampleOperation = <<<JSON
    {
      "type": "revise_task",
      "task_id": {$exampleTask->id},
      "progress_percent": {$exampleTask->progress_percent},
      "remaining_minutes": {$exampleRemaining},
      "status": "{$exampleTask->status}",
      "estimated_minutes": {$exampleTask->estimated_minutes},
      "priority": {$exampleTask->priority},
      "progress_reason": "実際の確認済み成果に応じて必要な値へ置き換える",
      "reason": "構造例。変更不要ならこの操作自体を出力しない"
    }
JSON;
        } else {
            $exampleOperation = <<<JSON
    {
      "type": "add_task",
      "client_ref": "new_task_1",
      "title": "必要になったTask",
      "description": "完了を判定できる具体的な達成条件",
      "estimated_minutes": 60,
      "remaining_minutes": 60,
      "priority": 1,
      "progress_percent": 0,
      "status": "todo",
      "reason": "構造例。必要な場合だけ追加する"
    }
JSON;
        }

        return <<<PROMPT
あなたは、現実の作業結果に合わせて計画を更新する進捗管理アシスタントです。
最初の計画を守ること自体を目的にせず、今回の実績・発見・状況変化を最優先の事実として、次に実行しやすい計画へ見直してください。

{$flowInstruction}

このプロンプトが既存の会話の途中で提示された場合は、その会話ですでにユーザー本人から共有され、確定している情報も判断材料として使用してください。プロンプト本文に同じ事実が再掲されていないことだけを理由に、既知の情報を聞き直してはいけません。

【情報の位置づけと優先順位】
このプロンプトには、Canoviaへ登録されている情報と、今回ユーザーが申告した最新実績の両方が含まれます。
登録済みの概要・タスク・進捗計算は、現実の最新方針に追従していない可能性があります。
矛盾がある場合は、次の順に信頼してください。

1. 今回の実績・状況変化と、追加質問に対するユーザーの回答
2. 日時が新しい、適用済みの方針変更履歴
3. 最近の作業ログと、そこで確認された実際の成果
4. Canoviaへ登録されている計画概要・タスク
5. 登録済みタスクを基準に算出された進捗率・残り時間・状態

【CANOVIA MEMORY：本人が明示した将来意図・関心・価値】
{$futureMemoContext}

Memoryは本人が明示した内容だけを保持した参考Contextです。今回の計画と関係する内容だけを使い、本人の意思を上書きしたり別の目標へ勝手に誘導したりしないでください。

【今回の最新実績・状況変化：最優先】
作業日: {$workedOnText}
関連先の候補: {$selectedTaskText}
作業時間: {$actualMinutesText}
難易度: {$difficulty}

ユーザーから先に渡された補足:
{$activitySummaryText}

【Canoviaが自動取得した今回のWorkSession事実】
{$sessionFactsText}

分かったこと・予定との違い:
{$discoveries}

今回AIに判断してほしいこと:
{$desiredOutcome}

【Canovia登録上の計画：古いスナップショットの可能性あり】
計画ID: {$plan->id}
タイトル: {$plan->title}
カテゴリ: {$category}
期間: {$plan->start_date->format('Y-m-d')} ～ {$deadlineText}
登録済み概要:
{$description}

【登録タスク基準の暫定評価】
進捗率: {$progress['weighted_progress_percent']}%
期待進捗率: {$expectedProgressText}
残り日数: {$remainingDaysText}
1日必要時間: {$progress['daily_required_minutes']}分
状態: {$progress['status']}

※上記の評価は登録済みタスク構成を前提とした暫定値です。最新報告により方針・完成条件・タスク構成が変わる場合、そのまま事実として扱わず再評価してください。

【作業可能時間（Canovia登録値）】
{$availabilityText}

※曜日・休暇・授業日などの現実の制約が今回の会話で変わった場合は、必要に応じてupdate_availabilityを提案してください。毎日作業できる前提にしないでください。

【登録済みタスク】
{$taskLines}

【最近の作業ログ】
{$recentLogLines}

【適用済みの方針変更履歴】
{$adjustmentLines}

【判断方針】
- 最初に、今回の最新報告と登録情報の間に重要な矛盾があるか確認する
- 追加質問は、本当に操作内容・絶対進捗・タスク構成を左右し、現在の会話・今回の報告・登録情報・履歴から解決できない場合だけ行う。原則0〜2個、最大5個までにする
- 現在の会話ですでにユーザーが答えた事実、過去のやり取りで確定している正答率・弱点・確認結果などを再質問しない
- 情報が不足していても、その項目を更新しないことで安全に処理できる場合は質問せず、確実に判断できる操作だけをJSONで返す
- 作業時間が未指定であることだけを理由に質問しない。信頼できる時間情報がなければrecord_resultを省略し、計画・タスクの再評価だけ進めてよい
- 計画どおりに進まなかったことを、直ちに遅れや失敗と判断しない
- 計画外の調査、試作、方向転換、失敗から得た知見も成果として評価する
- ユーザーが選択した関連タスクは候補であり、最新実績と合わない場合は無理に紐付けない
- 特定タスクが選択されていない場合、一覧の先頭タスクなどへ便宜的に紐付けない
- 既存タスクへ当てはめられない作業は、task_id を省略した計画全体の作業ログとして記録する
- 既存の概要やタスクが古い場合は、最新状況に合わせて update_plan・revise_task・keep_task・archive_task・add_task を提案する
- 計画再編では、update_planに変更後の方針を単独で理解できる完全なdescriptionを必ず含める
- 計画再編では、登録済みの全タスク（statusがcancelled以外）をkeep_task・revise_task・archive_taskのいずれか1つで必ず評価する
- keep_taskはDB上の値を変えず、再編後も維持することを明示する確認操作として使い、reasonを必ず含める
- タスクを物理削除せず、不要な場合は archive_task を使用する
- 既存タスクを指定するときは、上記の正しいタスクIDだけを使う
- 同じタスクに revise_task と archive_task を同時に出力しない
- 現在値と同じ変更や、根拠のない操作は出力しない
- 進捗率は、実際に完了した成果と最新の完成条件を根拠に設定する
- 作業時間が未指定または0分の場合、原則としてactual_minutesを推測しない。ただしユーザーが明示的に推定を依頼し、現在の会話や既存ログから合理的な根拠がある場合は推定してよい。その場合はreasonへ推定根拠を含める
- 最近の作業ログに今回と同一の実績がすでに記録されている場合、record_resultを重複作成しない。既存ログを成果根拠としてタスクや計画だけ再評価する
- 「Canoviaが自動取得した今回のWorkSession事実」でWorkLog保存済みが「はい」の場合、そのWorkSessionの作業時間はすでにCanoviaへ保存済みである。同じ作業をrecord_resultで再登録してはいけない。今回の作業がTask進捗・残り時間・後続Task・計画方針へ与える意味だけを更新する
- WorkSession事実だけでは何を達成したか判断できず、現在の会話にも説明がない場合は、「今回どこまで進みましたか？分かったことや次に残ったこともあれば教えてください」のような自由形式の質問を原則1回だけ行う。完了/継続/区切り/詰まりの4択をユーザーに要求しない
- record_result を複数に分ける場合、actual_minutes の合計を今回報告された作業時間と一致させる
- 結果記録は相対加算を使わず、record_resultのprogress_after_percentとremaining_minutes_afterで作業後の現在地を絶対値として返す
- 今回の報告が方針・完成条件・タスク構成へ影響する場合、計画やタスクの変更だけで終わらず、影響を受ける既存タスクと新規タスクについてprogress_percent・status・estimated_minutes・priorityを同じ回答内で再評価する
- 既存タスクを維持・更新する場合、最新の達成条件に対する現在の進捗率を再判定し、変更が必要な値を同じrevise_taskへ含める
- 旧タスクの成果を新規タスクへ引き継げる場合、add_taskを必ず0%にせず、再利用できる成果をprogress_percentとstatusへ反映する
- 1つの既存タスクの中で「完了した部分」と「今後やる部分」が明確に分かれた場合は、元タスクを曖昧な途中状態のまま残さない。原則として元タスクをrevise_taskで『今後やる残り』へ具体化し、すでに終わった部分はadd_taskでstatus=doneの独立タスクとして保存してよい。分割タスクにはprogress_origin=inherited_taskとsource_task_idsを使い、履歴関係を残す
- 作業終了時に次回の再開地点が分かった場合は、対象タスクのnext_action_noteを『次に画面を開いた瞬間に再開できる具体的な一文』へ更新する
- ロードマップは作業実績から育つ前提とし、粗すぎるタスクは分解、過剰に細かい場合は必要に応じて統合・整理し、次のActionが曖昧にならないようにする
- タスク分割時は、元タスクと新規タスクのestimated_minutes / remaining_minutesを二重計上しない。分割後の合計残り時間が現実の残作業量と一致するよう再評価する
- 進捗率は投入時間ではなく、最新の達成条件に対して確認済みの成果が占める割合で判断する
- 進捗率を根拠付きで判断できないタスクは、更新を省略できるなら質問しない。計画再編上その値の確定が不可欠な場合だけ追加質問する
- 計画全体の進捗率は直接出力せず、タスク更新後にCanovia側で再計算させる

【進捗再評価の必須条件】
方針変更やタスク再編を伴う提案では、概要更新・タスク追加・タスク中止だけを返して終了しないでください。
影響を受けるタスクの進捗率、状態、想定時間、優先度まで同じJSONで更新し、ユーザーが後から手動で進捗を合わせ直す必要がない状態を目標にしてください。
影響を受けない項目や現在値が妥当な項目について、同じ値の更新操作を作る必要はありません。

現在の会話・今回の報告・登録情報・履歴を使っても、安全な操作を決められない本質的な不足がある場合だけ質問してください。精度向上だけが目的の確認質問は不要です。質問が不要なら、説明文やMarkdownコードブロックを付けず、次の形式のJSONのみを出力してください。
以下は構造例であり、operations には実際に必要な操作だけを含めてください。

{
  "schema_version": "2.0",
  "flow": "{$flow}",
  "target_plan": {
    "id": {$plan->id},
    "title": {$targetTitleJson},
    "category": {$targetCategoryJson}
  },
  "summary": "今回の実績評価と計画見直しの要約",
  "operations": [
{$exampleOperation}
  ]
}

【操作仕様】
- schema_version は "2.0" にする
- flow は今回指定された "{$flow}" を変更しない
- target_plan は上記のid・title・categoryを変更せずそのまま出力する
- type は update_plan、update_availability、record_result、add_task、revise_task、keep_task、archive_task、reorder_tasks のいずれか
- update_plan は title、description、category、start_date、deadline、is_public のうち変更する項目だけを含める
- update_availability は weekly_schedule と overrides を使って作業可能時間を更新する。weekly_schedule は曜日ごとのavailable_minutes、overridesはdate・available_minutes・任意のnoteを含める
- 作業可能時間は細かな開始時刻ではなく、その日に確保できる合計分数として扱う
- update_plan の description は反映後の完全な概要文にする
- update_plan の start_date と deadline は YYYY-MM-DD
- record_result の task_id と task_ref はどちらも省略可能。その場合は計画全体の実績になる
- 新規作成タスクに結果を付ける場合、add_task に一意の client_ref を設定し、record_result の task_ref から参照する
- worked_on は YYYY-MM-DD
- actual_minutes は1以上の整数
- progress_percent と progress_after_percent は現在の完成条件に対する絶対値で0～100
- remaining_minutes と remaining_minutes_after は、進捗率からの機械計算ではなく今後実際に必要な時間を0以上の整数で見積もる
- difficulty は easy、normal、hard、stuck、または null
- priority は1～5
- activation_cost は開始ハードルを1～5で表す（省略時3）
- status は todo、doing、done
- revise_task は変更する項目だけを含める。ただし影響を受けるタスクでは、progress_percent・remaining_minutes・status・estimated_minutes・priority・progress_reasonを同時に再評価する
- add_task でprogress_percentを0より大きくする場合は、進捗の由来をprogress_originで示す
- progress_origin は existing_work（Canovia登録前から存在する成果）、inherited_task（登録済み旧タスクから引継ぎ）、new_work（今回の作業実績）のいずれか
- progress_originがinherited_taskの場合だけsource_task_idsを必須とし、引継ぎ元の登録済みタスクIDを配列で含める
- add_task / revise_task では、次回再開地点が具体化できる場合 next_action_note を含める
- 0%より大きい進捗には、どの成果を根拠に判断したかをprogress_reasonへ具体的に含める
- 登録済みタスクが存在しない既存成果を登録する場合はprogress_originをexisting_workにし、source_task_idsは付けない
- keep_task は既存タスクを再編後もそのまま維持するときに使い、task_idとreasonを含める
- archive_task はタスクを削除せず、状態を中止へ変更する
- reorder_tasks は items に task_id または task_ref を実行順で並べ、後続タスクを再編する
- 同じタスクに相反する操作を出力しない
- 操作は60件以内

【出力直前チェック（必須）】
1. schema_version が "2.0" か
2. flow が "{$flow}" のままか
3. target_plan.id が {$plan->id} のままか
4. 既存task_idは上の登録済みタスク一覧に実在するIDだけか
5. operationsが配列で、各要素にtypeがあるか
6. 文字列内の改行はJSONとして有効にエスケープされているか
7. 末尾カンマ・コメント・Markdownコードブロック・説明文が混ざっていないか
8. 最後にJSONとして構文解析できることを自分で確認してから返すこと
PROMPT;
    }

    private function normalizeOperation(Plan $plan, array $operation, int $index): array
    {
        $rowNumber = $index + 1;
        $operation = $this->canonicalizeOperationPayload($operation, $rowNumber);
        $type = $operation['type'] ?? null;

        if (! in_array($type, ['update_plan', 'update_availability', 'create_work_log', 'create_task', 'update_task', 'keep_task', 'cancel_task', 'reorder_tasks'], true)) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の操作種類が正しくありません。typeまたはoperationに対応する値を指定してください。",
            ]);
        }

        $normalized = match ($type) {
            'update_plan' => $this->normalizeUpdatePlanOperation($plan, $operation, $rowNumber),
            'update_availability' => $this->normalizeAvailabilityOperation($plan, $operation, $rowNumber),
            'create_work_log' => $this->normalizeWorkLogOperation($plan, $operation, $rowNumber),
            'create_task' => $this->normalizeCreateTaskOperation($plan, $operation, $rowNumber),
            'update_task' => $this->normalizeUpdateTaskOperation($plan, $operation, $rowNumber),
            'keep_task' => $this->normalizeKeepTaskOperation($plan, $operation, $rowNumber),
            'cancel_task' => $this->normalizeCancelTaskOperation($plan, $operation, $rowNumber),
            'reorder_tasks' => $this->normalizeReorderTasksOperation($plan, $operation, $rowNumber),
        };

        $normalized['normalization_notes'] = array_values(array_unique(array_filter(array_merge(
            is_array($operation['_normalization_notes'] ?? null) ? $operation['_normalization_notes'] : [],
            is_array($normalized['_normalization_notes'] ?? null) ? $normalized['_normalization_notes'] : []
        ))));
        unset($normalized['_normalization_notes']);

        return $normalized;
    }

    /**
     * 外部AIが返しやすい入れ子形式を、Canovia内部のフラット形式へ統一する。
     *
     * 対応例:
     * - operation -> type
     * - { operation: update_plan, plan: {...} }
     * - { operation: create_task, task: {...} }
     */
    private function canonicalizeOperationPayload(array $operation, int $rowNumber): array
    {
        $notes = [];
        $type = trim((string) ($operation['type'] ?? ''));
        $operationAlias = trim((string) ($operation['operation'] ?? ''));

        if ($type !== '' && $operationAlias !== '' && $type !== $operationAlias) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目でtypeとoperationの内容が一致していません。",
            ]);
        }

        if ($type === '' && $operationAlias !== '') {
            $type = $operationAlias;
            $notes[] = "operationキーをtypeとして自動変換しました。";
        }

        $typeAliases = [
            'record_result' => 'create_work_log',
            'add_task' => 'create_task',
            'revise_task' => 'update_task',
            'archive_task' => 'cancel_task',
            'update_schedule' => 'update_availability',
        ];

        if (isset($typeAliases[$type])) {
            $notes[] = "{$type}を{$typeAliases[$type]}として読み込みました。";
            $type = $typeAliases[$type];
        }

        $payloadKey = in_array($type, ['update_plan', 'update_availability'], true) ? 'plan' : 'task';

        if (isset($operation[$payloadKey])) {
            if (! is_array($operation[$payloadKey])) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の{$payloadKey}はオブジェクトにしてください。",
                ]);
            }

            $outer = Arr::except($operation, ['type', 'operation', 'plan', 'task', '_normalization_notes']);
            $operation = array_merge($operation[$payloadKey], $outer);
            $notes[] = "{$payloadKey}内の項目をCanovia形式へ自動変換しました。";
        } else {
            $operation = Arr::except($operation, ['operation']);
        }

        $operation['type'] = $type;
        $existingNotes = $operation['_normalization_notes'] ?? [];
        $operation['_normalization_notes'] = array_values(array_unique(array_merge(
            is_array($existingNotes) ? $existingNotes : [],
            $notes
        )));

        return $operation;
    }

    private function normalizeUpdatePlanOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $normalized = [
            'type' => 'update_plan',
            'reason' => trim((string) ($operation['reason'] ?? '')),
        ];

        if (array_key_exists('title', $operation)) {
            $title = trim((string) $operation['title']);

            if ($title === '' || mb_strlen($title) > 255) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後計画名が正しくありません。",
                ]);
            }

            $normalized['title'] = $title;
        }

        if (array_key_exists('description', $operation)) {
            $description = trim((string) $operation['description']);

            if (mb_strlen($description) > 20000) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後概要が長すぎます。",
                ]);
            }

            $normalized['description'] = $description;
        }

        if (array_key_exists('category', $operation)) {
            $category = trim((string) $operation['category']);

            if (mb_strlen($category) > 100) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後カテゴリが長すぎます。",
                ]);
            }

            $normalized['category'] = $category;
        }

        foreach (['start_date', 'deadline'] as $dateKey) {
            if (! array_key_exists($dateKey, $operation)) {
                continue;
            }

            if (! is_string($operation[$dateKey]) || ! strtotime($operation[$dateKey])) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の{$dateKey}が正しくありません。",
                ]);
            }

            $normalized[$dateKey] = Carbon::parse($operation[$dateKey])->format('Y-m-d');
        }

        if (array_key_exists('is_public', $operation)) {
            if (! is_bool($operation['is_public']) && ! in_array($operation['is_public'], [0, 1, '0', '1'], true)) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の公開設定が正しくありません。",
                ]);
            }

            $normalized['is_public'] = filter_var($operation['is_public'], FILTER_VALIDATE_BOOLEAN);
        }

        $changeKeys = array_intersect(array_keys($normalized), [
            'title', 'description', 'category', 'start_date', 'deadline', 'is_public',
        ]);

        if ($changeKeys === []) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のupdate_planに変更内容がありません。",
            ]);
        }

        $startDate = Carbon::parse($normalized['start_date'] ?? $plan->start_date);
        $deadlineValue = $normalized['deadline'] ?? $plan->deadline?->format('Y-m-d');
        $deadline = $deadlineValue ? Carbon::parse($deadlineValue) : null;

        if ($deadline && $deadline->lt($startDate)) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の期限は開始日以降にしてください。",
            ]);
        }

        $changes = collect($changeKeys)->map(function ($key) use ($normalized) {
            $labels = [
                'title' => '名称',
                'description' => '概要',
                'category' => 'カテゴリ',
                'start_date' => '開始日',
                'deadline' => '期限',
                'is_public' => '公開設定',
            ];
            $value = $normalized[$key];

            if ($key === 'is_public') {
                $value = $value ? '公開' : '非公開';
            }

            if ($key === 'description') {
                $value = Str::limit($value, 80);
            }

            return $labels[$key] . '→' . $value;
        })->implode(' / ');

        $normalized['display'] = '計画を更新：' . $changes;

        return $normalized;
    }

    private function normalizeAvailabilityOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $weekly = $operation['weekly_schedule'] ?? $operation['weekly'] ?? [];
        $overrides = $operation['overrides'] ?? [];

        if (! is_array($weekly) || ! is_array($overrides)) {
            throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目の作業可能時間設定が正しくありません。"]);
        }

        $dayMap = [
            'sunday' => 0, 'sun' => 0, '日' => 0,
            'monday' => 1, 'mon' => 1, '月' => 1,
            'tuesday' => 2, 'tue' => 2, '火' => 2,
            'wednesday' => 3, 'wed' => 3, '水' => 3,
            'thursday' => 4, 'thu' => 4, '木' => 4,
            'friday' => 5, 'fri' => 5, '金' => 5,
            'saturday' => 6, 'sat' => 6, '土' => 6,
        ];
        $normalizedWeekly = [];
        foreach ($weekly as $day => $value) {
            $dayKey = is_numeric($day) ? (int) $day : ($dayMap[mb_strtolower(trim((string) $day))] ?? null);
            if ($dayKey === null || $dayKey < 0 || $dayKey > 6) {
                throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目の曜日指定が正しくありません: {$day}"]);
            }
            $minutes = is_array($value) ? ($value['available_minutes'] ?? $value['minutes'] ?? null) : $value;
            $optional = is_array($value) ? (bool) ($value['is_optional'] ?? false) : false;
            if (! is_numeric($minutes) || (int) $minutes < 0 || (int) $minutes > 1440) {
                throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目の曜日ごとの作業可能時間は0〜1440分にしてください。"]);
            }
            $normalizedWeekly[$dayKey] = ['available_minutes' => (int) $minutes, 'is_optional' => $optional];
        }
        ksort($normalizedWeekly);

        $normalizedOverrides = [];
        foreach ($overrides as $item) {
            if (! is_array($item) || empty($item['date']) || ! strtotime((string) $item['date'])) {
                throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目の例外日設定が正しくありません。"]);
            }
            $minutes = $item['available_minutes'] ?? $item['minutes'] ?? null;
            if (! is_numeric($minutes) || (int) $minutes < 0 || (int) $minutes > 1440) {
                throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目の例外日の作業可能時間は0〜1440分にしてください。"]);
            }
            $normalizedOverrides[] = [
                'date' => Carbon::parse($item['date'])->format('Y-m-d'),
                'available_minutes' => (int) $minutes,
                'note' => Str::limit(trim((string) ($item['note'] ?? '')), 255, ''),
            ];
        }

        if ($normalizedWeekly === [] && $normalizedOverrides === []) {
            throw ValidationException::withMessages(['operations_json' => "{$rowNumber}件目のupdate_availabilityに変更内容がありません。"]);
        }

        return [
            'type' => 'update_availability',
            'weekly_schedule' => $normalizedWeekly,
            'overrides' => $normalizedOverrides,
            'replace_weekly' => (bool) ($operation['replace_weekly'] ?? true),
            'reason' => trim((string) ($operation['reason'] ?? '')),
            'display' => '作業可能時間を更新：通常週' . count($normalizedWeekly) . '曜日・例外' . count($normalizedOverrides) . '件',
        ];
    }

    private function normalizeWorkLogOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $taskId = isset($operation['task_id']) && $operation['task_id'] !== null
            ? (int) $operation['task_id']
            : null;
        $taskRef = trim((string) ($operation['task_ref'] ?? '')) ?: null;

        if ($taskId && ! $plan->tasks()->whereKey($taskId)->exists()) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の作業ログが存在しないタスクを参照しています。",
            ]);
        }

        if (empty($operation['worked_on']) || ! strtotime((string) $operation['worked_on'])) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の作業日が正しくありません。",
            ]);
        }

        if (! isset($operation['actual_minutes']) || ! is_numeric($operation['actual_minutes']) || (int) $operation['actual_minutes'] < 1) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の作業時間は1以上の整数にしてください。",
            ]);
        }

        $progressDelta = (int) ($operation['progress_delta_percent'] ?? 0);

        if ($progressDelta < 0 || $progressDelta > 100) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の進捗増加率は0～100にしてください。",
            ]);
        }

        $difficulty = $operation['difficulty'] ?? null;

        if ($difficulty !== null && ! in_array($difficulty, ['easy', 'normal', 'hard', 'stuck'], true)) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の難易度が正しくありません。",
            ]);
        }

        $taskTitle = $taskId ? $plan->tasks()->find($taskId)?->title : null;
        $targetLabel = $taskTitle ?: ($taskRef ? "新規タスク参照:{$taskRef}" : '計画全体');
        $progressAfter = null;
        $remainingAfter = null;

        if (array_key_exists('progress_after_percent', $operation)) {
            if ($taskId === null && $taskRef === null) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目でタスクの現在地を更新する場合はtask_idまたはtask_refを指定してください。",
                ]);
            }

            if (! is_numeric($operation['progress_after_percent'])) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の作業後進捗率は0～100の整数にしてください。",
                ]);
            }

            $progressAfter = (int) $operation['progress_after_percent'];

            if ($progressAfter < 0 || $progressAfter > 100) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の作業後進捗率は0～100にしてください。",
                ]);
            }

            if (! isset($operation['remaining_minutes_after']) || ! is_numeric($operation['remaining_minutes_after'])) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目で絶対進捗を更新する場合は、作業後の残り時間も指定してください。",
                ]);
            }

            $remainingAfter = (int) $operation['remaining_minutes_after'];

            if ($remainingAfter < 0) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の作業後残り時間は0以上にしてください。",
                ]);
            }

            if (trim((string) ($operation['progress_reason'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目で絶対進捗を更新する場合はprogress_reasonを指定してください。",
                ]);
            }
        }

        return [
            'type' => 'create_work_log',
            'task_id' => $taskId,
            'task_ref' => $taskRef,
            'worked_on' => Carbon::parse($operation['worked_on'])->format('Y-m-d'),
            'actual_minutes' => (int) $operation['actual_minutes'],
            'progress_delta_percent' => $progressDelta,
            'progress_after_percent' => $progressAfter,
            'remaining_minutes_after' => $remainingAfter,
            'progress_reason' => trim((string) ($operation['progress_reason'] ?? '')),
            'difficulty' => $difficulty,
            'memo' => trim((string) ($operation['memo'] ?? '')),
            'outcome' => trim((string) ($operation['outcome'] ?? $operation['memo'] ?? '')),
            'reason' => trim((string) ($operation['reason'] ?? '')),
            'display' => "結果を記録：{$targetLabel}・" . (int) $operation['actual_minutes'] . '分'
                . ($progressAfter !== null ? "・現在地{$progressAfter}%・残り{$remainingAfter}分" : ''),
        ];
    }

    private function normalizeCreateTaskOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $title = trim((string) ($operation['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の新規タスク名が空、または長すぎます。",
            ]);
        }

        if (! isset($operation['estimated_minutes']) || ! is_numeric($operation['estimated_minutes']) || (int) $operation['estimated_minutes'] < 0) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の想定時間が正しくありません。",
            ]);
        }

        $priority = (int) ($operation['priority'] ?? 3);
        $activationCost = (int) ($operation['activation_cost'] ?? 3);
        $progressPercent = (int) ($operation['progress_percent'] ?? 0);
        $status = $operation['status'] ?? 'todo';
        $clientRef = trim((string) ($operation['client_ref'] ?? '')) ?: null;
        $progressReason = trim((string) ($operation['progress_reason'] ?? ''));
        $progressOrigin = trim((string) ($operation['progress_origin'] ?? '')) ?: null;
        $nextActionNote = trim((string) ($operation['next_action_note'] ?? ''));
        $sourceTaskIds = [];
        $normalizationNotes = $operation['_normalization_notes'] ?? [];

        if (array_key_exists('source_task_ids', $operation)) {
            if (! is_array($operation['source_task_ids'])) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目のsource_task_idsは配列にしてください。",
                ]);
            }

            foreach ($operation['source_task_ids'] as $sourceTaskId) {
                if (! is_numeric($sourceTaskId)) {
                    throw ValidationException::withMessages([
                        'operations_json' => "{$rowNumber}件目のsource_task_idsに整数以外が含まれています。",
                    ]);
                }

                $sourceTaskIds[] = (int) $sourceTaskId;
            }

            $sourceTaskIds = array_values(array_unique($sourceTaskIds));

            $existingSourceTaskIds = $plan->tasks()
                ->whereIn('id', $sourceTaskIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($existingSourceTaskIds) !== count($sourceTaskIds)) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目のsource_task_idsに、この計画に存在しないタスクIDが含まれています。",
                ]);
            }
        }

        $sourceTaskSnapshots = $sourceTaskIds === []
            ? []
            : $plan->tasks()
                ->whereIn('id', $sourceTaskIds)
                ->get(['id', 'title'])
                ->sortBy(fn (Task $task) => array_search((int) $task->id, $sourceTaskIds, true))
                ->map(fn (Task $task) => ['id' => (int) $task->id, 'title' => $task->title])
                ->values()
                ->all();

        if ($priority < 1 || $priority > 5) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の優先度は1～5にしてください。",
            ]);
        }

        if ($activationCost < 1 || $activationCost > 5) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の開始ハードルは1～5にしてください。",
            ]);
        }

        if ($progressPercent < 0 || $progressPercent > 100) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の進捗率は0～100にしてください。",
            ]);
        }

        $remainingMinutes = array_key_exists('remaining_minutes', $operation)
            ? (int) $operation['remaining_minutes']
            : max((int) round((int) $operation['estimated_minutes'] * (100 - $progressPercent) / 100), 0);

        if ($remainingMinutes < 0) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の残り時間は0以上にしてください。",
            ]);
        }

        if ($status === 'done') {
            $remainingMinutes = 0;
        }

        if (! in_array($status, ['todo', 'doing', 'done'], true)) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の状態が正しくありません。",
            ]);
        }

        if (mb_strlen($nextActionNote) > 1000) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の次回再開地点が長すぎます。",
            ]);
        }

        if ($clientRef !== null && mb_strlen($clientRef) > 80) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のclient_refが長すぎます。",
            ]);
        }

        $allowedProgressOrigins = ['existing_work', 'inherited_task', 'new_work'];

        if ($progressOrigin !== null && ! in_array($progressOrigin, $allowedProgressOrigins, true)) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のprogress_originはexisting_work、inherited_task、new_workのいずれかにしてください。",
            ]);
        }

        if ($progressPercent > 0 && $progressOrigin === null) {
            $progressOrigin = $sourceTaskIds !== [] ? 'inherited_task' : 'existing_work';
            $normalizationNotes[] = $sourceTaskIds !== []
                ? "「{$title}」の進捗元を、source_task_idsに基づいて既存タスクからの引継ぎとして補完しました。"
                : "「{$title}」の進捗元を、Canovia登録前から存在する成果として補完しました。";
        }

        if ($progressPercent === 0) {
            $progressOrigin = $progressOrigin ?: null;
        }

        if ($progressOrigin === 'inherited_task' && $sourceTaskIds === []) {
            // 詳細な不足表示はanalyzeProposalでタスク名と一緒に行う。
        }

        if ($progressOrigin !== 'inherited_task' && $sourceTaskIds !== []) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のsource_task_idsを使う場合はprogress_originをinherited_taskにしてください。",
            ]);
        }

        if ($progressPercent > 0 && $progressReason === '' && $progressOrigin === 'existing_work') {
            $progressReason = 'Canovia登録前から存在する成果として外部AIが進捗を提案したため。反映前に成果内容を確認してください。';
            $normalizationNotes[] = "「{$title}」の進捗根拠が省略されていたため、登録前の既存成果として確認用の根拠文を補完しました。";
        }

        $sourceLabel = $sourceTaskIds !== []
            ? '・引継ぎ元 #' . implode(', #', $sourceTaskIds)
            : '';
        $originLabels = [
            'existing_work' => '登録前の既存成果',
            'inherited_task' => '既存タスクから引継ぎ',
            'new_work' => '新しい作業実績',
        ];
        $originLabel = $progressOrigin ? '・進捗元:' . ($originLabels[$progressOrigin] ?? $progressOrigin) : '';

        return [
            'type' => 'create_task',
            'client_ref' => $clientRef,
            'title' => $title,
            'description' => trim((string) ($operation['description'] ?? '')),
            'estimated_minutes' => (int) $operation['estimated_minutes'],
            'remaining_minutes' => $remainingMinutes,
            'priority' => $priority,
            'activation_cost' => $activationCost,
            'progress_percent' => $progressPercent,
            'status' => $status,
            'source_task_ids' => $sourceTaskIds,
            'source_task_snapshots' => $sourceTaskSnapshots,
            'progress_origin' => $progressOrigin,
            'progress_reason' => $progressReason,
            'next_action_note' => $nextActionNote,
            'reason' => trim((string) ($operation['reason'] ?? '')),
            '_normalization_notes' => array_values(array_unique(array_filter($normalizationNotes))),
            'display' => "タスクを追加：{$title}（総想定" . (int) $operation['estimated_minutes'] . "分・残り{$remainingMinutes}分・進捗{$progressPercent}%{$originLabel}{$sourceLabel}）",
        ];
    }

    private function normalizeUpdateTaskOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $task = $this->resolveExistingTask($plan, $operation, $rowNumber);
        $normalized = [
            'type' => 'update_task',
            'task_id' => $task->id,
            'reason' => trim((string) ($operation['reason'] ?? '')),
        ];

        if (array_key_exists('title', $operation)) {
            $title = trim((string) $operation['title']);

            if ($title === '' || mb_strlen($title) > 255) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後タスク名が正しくありません。",
                ]);
            }

            $normalized['title'] = $title;
        }

        if (array_key_exists('description', $operation)) {
            $normalized['description'] = trim((string) $operation['description']);
        }

        if (array_key_exists('estimated_minutes', $operation)) {
            if (! is_numeric($operation['estimated_minutes']) || (int) $operation['estimated_minutes'] < 0) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後想定時間が正しくありません。",
                ]);
            }

            $normalized['estimated_minutes'] = (int) $operation['estimated_minutes'];
        }

        if (array_key_exists('remaining_minutes', $operation)) {
            if (! is_numeric($operation['remaining_minutes']) || (int) $operation['remaining_minutes'] < 0) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後残り時間は0以上にしてください。",
                ]);
            }

            $normalized['remaining_minutes'] = (int) $operation['remaining_minutes'];
        }

        if (array_key_exists('progress_percent', $operation)) {
            $progressPercent = (int) $operation['progress_percent'];

            if ($progressPercent < 0 || $progressPercent > 100) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後進捗率は0～100にしてください。",
                ]);
            }

            $normalized['progress_percent'] = $progressPercent;
        }

        if (array_key_exists('progress_reason', $operation)) {
            $normalized['progress_reason'] = trim((string) $operation['progress_reason']);
        }

        if (array_key_exists('next_action_note', $operation)) {
            $nextActionNote = trim((string) $operation['next_action_note']);
            if (mb_strlen($nextActionNote) > 1000) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の次回再開地点が長すぎます。",
                ]);
            }
            $normalized['next_action_note'] = $nextActionNote;
        }

        if (array_key_exists('status', $operation)) {
            if (! in_array($operation['status'], ['todo', 'doing', 'done'], true)) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後状態が正しくありません。",
                ]);
            }

            $normalized['status'] = $operation['status'];
        }

        if (array_key_exists('priority', $operation)) {
            $priority = (int) $operation['priority'];

            if ($priority < 1 || $priority > 5) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後優先度は1～5にしてください。",
                ]);
            }

            $normalized['priority'] = $priority;
        }

        if (array_key_exists('activation_cost', $operation)) {
            $activationCost = (int) $operation['activation_cost'];
            if ($activationCost < 1 || $activationCost > 5) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の変更後開始ハードルは1～5にしてください。",
                ]);
            }
            $normalized['activation_cost'] = $activationCost;
        }

        $changeKeys = array_intersect(array_keys($normalized), [
            'title', 'description', 'estimated_minutes', 'remaining_minutes', 'progress_percent', 'progress_reason', 'next_action_note', 'status', 'priority', 'activation_cost',
        ]);

        if ($changeKeys === []) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のupdate_taskに変更内容がありません。",
            ]);
        }

        $changes = collect($changeKeys)->map(function ($key) use ($normalized) {
            $labels = [
                'title' => '名称',
                'description' => '説明',
                'estimated_minutes' => '想定時間',
                'remaining_minutes' => '残り時間',
                'progress_percent' => '進捗率',
                'progress_reason' => '進捗根拠',
                'next_action_note' => '次回再開地点',
                'status' => '状態',
                'priority' => '優先度',
                'activation_cost' => '開始ハードル',
            ];

            $value = $normalized[$key];
            $value = in_array($key, ['estimated_minutes', 'remaining_minutes'], true)
                ? $value . '分'
                : ($key === 'progress_percent' ? $value . '%' : $value);

            return $labels[$key] . '→' . $value;
        })->implode('、');

        $normalized['display'] = "タスクを更新：{$task->title}（{$changes}）";

        return $normalized;
    }

    private function normalizeKeepTaskOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $task = $this->resolveExistingTask($plan, $operation, $rowNumber);
        $reason = trim((string) ($operation['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目のkeep_taskには、維持する理由を含めてください。",
            ]);
        }

        return [
            'type' => 'keep_task',
            'task_id' => $task->id,
            'reason' => $reason,
            'display' => "タスクを維持：{$task->title}",
        ];
    }

    private function normalizeCancelTaskOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $task = $this->resolveExistingTask($plan, $operation, $rowNumber);
        $reason = trim((string) ($operation['reason'] ?? ''));

        return [
            'type' => 'cancel_task',
            'task_id' => $task->id,
            'reason' => $reason,
            'display' => "タスクを中止：{$task->title}",
        ];
    }

    private function normalizeReorderTasksOperation(Plan $plan, array $operation, int $rowNumber): array
    {
        $items = $operation['items'] ?? $operation['order'] ?? null;

        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の後続タスク順序が空です。",
            ]);
        }

        $normalizedItems = collect($items)->map(function ($item) use ($plan, $rowNumber) {
            if (is_numeric($item)) {
                $item = ['task_id' => (int) $item];
            }

            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の順序指定が正しくありません。",
                ]);
            }

            $taskId = isset($item['task_id']) ? (int) $item['task_id'] : null;
            $taskRef = trim((string) ($item['task_ref'] ?? '')) ?: null;

            if (($taskId === null) === ($taskRef === null)) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の順序指定にはtask_idまたはtask_refのどちらか一方が必要です。",
                ]);
            }

            if ($taskId !== null && ! $plan->tasks()->whereKey($taskId)->exists()) {
                throw ValidationException::withMessages([
                    'operations_json' => "{$rowNumber}件目の順序指定が存在しないタスクを参照しています。",
                ]);
            }

            return ['task_id' => $taskId, 'task_ref' => $taskRef];
        })->values();

        $keys = $normalizedItems->map(fn ($item) => $item['task_id'] !== null ? 'id:' . $item['task_id'] : 'ref:' . $item['task_ref']);

        if ($keys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目の後続タスク順序に重複があります。",
            ]);
        }

        return [
            'type' => 'reorder_tasks',
            'items' => $normalizedItems->all(),
            'reason' => trim((string) ($operation['reason'] ?? '')),
            'display' => '後続タスクを実行順に再編：' . $normalizedItems->count() . '件',
        ];
    }

    private function resolveExistingTask(Plan $plan, array $operation, int $rowNumber): Task
    {
        if (! isset($operation['task_id']) || ! is_numeric($operation['task_id'])) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目にtask_idがありません。",
            ]);
        }

        $task = $plan->tasks()->whereKey((int) $operation['task_id'])->first();

        if (! $task) {
            throw ValidationException::withMessages([
                'operations_json' => "{$rowNumber}件目が存在しないタスクを参照しています。",
            ]);
        }

        return $task;
    }

    private function validateTaskReferences(array $operations): void
    {
        $refs = collect($operations)
            ->where('type', 'create_task')
            ->pluck('client_ref')
            ->filter();

        if ($refs->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'operations_json' => 'create_task の client_ref が重複しています。',
            ]);
        }

        foreach ($operations as $operation) {
            if ($operation['type'] === 'create_work_log'
                && ! empty($operation['task_ref'])
                && ! $refs->contains($operation['task_ref'])) {
                throw ValidationException::withMessages([
                    'operations_json' => "作業ログの task_ref「{$operation['task_ref']}」に対応する新規タスクがありません。",
                ]);
            }


            if ($operation['type'] === 'reorder_tasks') {
                foreach ($operation['items'] as $item) {
                    if (! empty($item['task_ref']) && ! $refs->contains($item['task_ref'])) {
                        throw ValidationException::withMessages([
                            'operations_json' => "順序指定の task_ref「{$item['task_ref']}」に対応する新規タスクがありません。",
                        ]);
                    }
                }
            }
        }

        $taskDispositions = collect($operations)
            ->filter(fn (array $operation) => in_array($operation['type'], ['keep_task', 'update_task', 'cancel_task'], true))
            ->groupBy('task_id');

        $duplicatedTaskIds = $taskDispositions
            ->filter(fn ($group) => $group->count() > 1)
            ->keys();

        if ($duplicatedTaskIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'operations_json' => '同じ既存タスクへ複数の扱いが指定されています。対象タスクID: ' . $duplicatedTaskIds->implode(', '),
            ]);
        }
    }

    private function validateSelectedTaskReferences(array $selectedOperations): void
    {
        $selectedRefs = collect($selectedOperations)
            ->where('type', 'create_task')
            ->pluck('client_ref')
            ->filter();

        foreach ($selectedOperations as $operation) {
            if ($operation['type'] === 'create_work_log'
                && ! empty($operation['task_ref'])
                && ! $selectedRefs->contains($operation['task_ref'])) {
                throw ValidationException::withMessages([
                    'selected_operations' => "新規タスク参照「{$operation['task_ref']}」を使う作業ログを反映するには、対応するタスク追加も選択してください。",
                ]);
            }


            if ($operation['type'] === 'reorder_tasks') {
                foreach ($operation['items'] as $item) {
                    if (! empty($item['task_ref']) && ! $selectedRefs->contains($item['task_ref'])) {
                        throw ValidationException::withMessages([
                            'selected_operations' => "順序指定の新規タスク参照「{$item['task_ref']}」を反映するには、対応するタスク追加も選択してください。",
                        ]);
                    }
                }
            }
        }
    }

    private function decodeJsonDocument(string $text): array
    {
        try {
            // Use the same shared normalizer here as the request middleware.
            // This guarantees plan review does not fall back to its older,
            // stricter JSON extractor when a request reaches this method.
            $jsonText = app(AiJsonInputNormalizer::class)->normalize($text);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'operations_json' => $exception->getMessage(),
            ]);
        }

        $decoded = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            $errorMessage = json_last_error_msg();
            $lineNumber = $this->estimateJsonErrorLine($jsonText);
            $lineHint = $lineNumber !== null ? "（{$lineNumber}行目付近）" : '';

            throw ValidationException::withMessages([
                'operations_json' => "JSONの構文が正しくありません{$lineHint}。{$errorMessage}。意味上の検証へ進む前に、引用符・カンマ・キー名・重複項目を確認してください。",
            ]);
        }

        return [$jsonText, $decoded];
    }

    private function estimateJsonErrorLine(string $jsonText): ?int
    {
        // PHPのjson_decodeは位置を返さないため、明確に壊れやすい行を補助的に示す。
        $lines = preg_split('/\R/u', $jsonText) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^"[^"]+"\s*,?$/u', $trimmed)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*"\s*:/u', $trimmed)) {
                return $index + 1;
            }
        }

        return null;
    }

    private function normalizeDecodedOperations(
        Plan $plan,
        array $decoded,
        bool $allowTaskList = false,
        ?string $action = null
    ): array {
        $action ??= $this->resolveJsonAction($decoded);
        $hasOperations = isset($decoded['operations']) && is_array($decoded['operations']);
        $hasTasks = isset($decoded['tasks']) && is_array($decoded['tasks']);

        if ($hasOperations && $hasTasks) {
            throw ValidationException::withMessages([
                'operations_json' => 'operationsとtasksを同時に含めることはできません。どちらか一方にしてください。',
            ]);
        }

        if ($hasOperations) {
            $rawOperations = $decoded['operations'];
        } elseif ($allowTaskList && $hasTasks) {
            $rawOperations = collect($decoded['tasks'])
                ->map(function ($task) {
                    if (! is_array($task)) {
                        return $task;
                    }

                    return array_merge([
                        'type' => 'create_task',
                        'reason' => 'AIタスク追加支援から生成されたため',
                    ], $task);
                })
                ->all();
        } else {
            $message = $allowTaskList
                ? 'operations配列またはtasks配列が見つかりません。指定した形式で回答を貼り付けてください。'
                : 'operations配列が見つかりません。指定した形式で回答を貼り付けてください。';

            throw ValidationException::withMessages([
                'operations_json' => $message,
            ]);
        }

        if (count($rawOperations) === 0) {
            throw ValidationException::withMessages([
                'operations_json' => '反映候補の操作がありません。',
            ]);
        }

        if (count($rawOperations) > 60) {
            throw ValidationException::withMessages([
                'operations_json' => '一度に確認できる操作は60件までです。',
            ]);
        }

        $operations = [];

        foreach ($rawOperations as $index => $operation) {
            if (! is_array($operation)) {
                throw ValidationException::withMessages([
                    'operations_json' => ($index + 1) . '件目の操作形式が正しくありません。',
                ]);
            }

            $operations[] = $this->normalizeOperation($plan, $operation, $index);
        }

        $this->validateTaskReferences($operations);
        $this->validateActionCompatibility($action, $operations, $hasTasks);

        return $operations;
    }

    private function buildProposal(
        Plan $plan,
        array $decoded,
        array $operations,
        string $jsonText,
        string $action
    ): array {
        $analysis = $this->analyzeProposal($plan, $action, $operations);

        $roadmapService = app(RoadmapService::class);
        $roadmapBefore = $roadmapService->build($plan);
        $roadmapPreview = $roadmapService->project($plan, $operations);

        return [
            'token' => (string) Str::uuid(),
            'action' => $action,
            'action_label' => $this->actionLabels()[$action] ?? $action,
            'flow' => $this->resolveJsonFlow($decoded, $action),
            'summary' => trim((string) ($decoded['summary'] ?? '外部AIから計画変更案が返されました。')),
            'operations' => $operations,
            'raw_json' => $jsonText,
            'target_plan' => [
                'id' => $plan->id,
                'title' => $plan->title,
                'category' => $plan->category,
            ],
            'analysis' => $analysis,
            'roadmap_before' => $roadmapBefore,
            'roadmap_preview' => $roadmapPreview,
            'can_apply' => $analysis['blocking_issues'] === [],
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function resolveJsonAction(array $decoded): ?string
    {
        if (array_key_exists('flow', $decoded) && trim((string) $decoded['flow']) !== '') {
            $flow = trim((string) $decoded['flow']);
            $flowActions = [
                'plan_generation' => 'append_tasks',
                'plan_update' => 'restructure_plan',
                'result_recording' => 'update_progress',
            ];

            if (! isset($flowActions[$flow])) {
                throw ValidationException::withMessages([
                    'operations_json' => 'flowはplan_generation、plan_update、result_recordingのいずれかにしてください。',
                ]);
            }

            if (! array_key_exists('action', $decoded) || trim((string) $decoded['action']) === '') {
                if ($flow === 'result_recording') {
                    $structuralTypes = ['update_plan', 'keep_task', 'cancel_task', 'archive_task'];
                    $containsStructuralChange = collect($decoded['operations'] ?? [])
                        ->filter(fn ($operation) => is_array($operation))
                        ->map(fn ($operation) => $operation['type'] ?? $operation['operation'] ?? null)
                        ->contains(fn ($type) => in_array($type, $structuralTypes, true));

                    if ($containsStructuralChange) {
                        return 'restructure_plan';
                    }
                }

                return $flowActions[$flow];
            }
        }

        if (! array_key_exists('action', $decoded) || trim((string) $decoded['action']) === '') {
            return null;
        }

        $action = trim((string) $decoded['action']);
        $action = [
            'plan_generation' => 'append_tasks',
            'plan_update' => 'restructure_plan',
            'result_recording' => 'update_progress',
        ][$action] ?? $action;

        if (isset($flowActions, $flow) && $flowActions[$flow] !== $action) {
            throw ValidationException::withMessages([
                'operations_json' => 'flowとactionが矛盾しています。JSON 2.0ではactionを省略してください。',
            ]);
        }
        $allowedActions = array_keys($this->actionLabels());

        if (! in_array($action, $allowedActions, true)) {
            throw ValidationException::withMessages([
                'operations_json' => 'actionはappend_tasks、restructure_plan、update_progressのいずれかにしてください。JSON 2.0では代わりにflowを使用できます。',
            ]);
        }

        return $action;
    }

    private function resolveJsonFlow(array $decoded, string $action): string
    {
        $flow = trim((string) ($decoded['flow'] ?? ''));

        if (in_array($flow, ['plan_generation', 'plan_update', 'result_recording'], true)) {
            return $flow;
        }

        return match ($action) {
            'append_tasks' => 'plan_generation',
            'restructure_plan' => 'plan_update',
            default => 'result_recording',
        };
    }

    private function inferPlanReviewAction(array $decoded): string
    {
        $types = collect($decoded['operations'] ?? [])
            ->filter(fn ($operation) => is_array($operation))
            ->map(fn (array $operation) => $operation['type'] ?? $operation['operation'] ?? null)
            ->filter();

        if ($types->contains(fn ($type) => in_array($type, ['update_plan', 'keep_task', 'cancel_task'], true))) {
            return 'restructure_plan';
        }

        return 'update_progress';
    }

    private function validateActionCompatibility(?string $action, array $operations, bool $usedTaskList): void
    {
        if ($action === null) {
            throw ValidationException::withMessages([
                'operations_json' => 'JSONにactionがありません。操作目的を指定してください。',
            ]);
        }

        $types = collect($operations)->pluck('type')->unique()->values();

        if ($action === 'append_tasks') {
            $unsupported = $types->reject(fn ($type) => in_array($type, ['create_task', 'reorder_tasks'], true));

            if ($unsupported->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'operations_json' => 'append_tasksではcreate_taskとreorder_tasksだけを使用できます。計画概要や既存タスクも変更する場合はrestructure_planを使用してください。',
                ]);
            }
        }

        if ($action === 'update_progress') {
            $allowed = ['update_availability', 'create_work_log', 'create_task', 'update_task', 'reorder_tasks'];
            $unsupported = $types->reject(fn ($type) => in_array($type, $allowed, true));

            if ($unsupported->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'operations_json' => 'update_progressでは作業ログ・タスク追加・タスク進捗更新だけを扱えます。概要変更やタスク中止を含む場合はrestructure_planを使用してください。',
                ]);
            }

            if ($usedTaskList) {
                throw ValidationException::withMessages([
                    'operations_json' => 'update_progressではtasks配列を使用できません。operations配列で具体的な更新操作を指定してください。',
                ]);
            }
        }
    }

    private function analyzeProposal(Plan $plan, string $action, array $operations): array
    {
        $operationCollection = collect($operations);
        $activeTasks = $plan->tasks()
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get(['id', 'title', 'status'])
            ->toBase();
        $blockingIssues = [];
        $blockingIssueGroups = [];
        $warnings = [];
        $normalizationNotes = $operationCollection
            ->flatMap(fn (array $operation) => $operation['normalization_notes'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $addBlockingIssue = function (string $groupTitle, string $message) use (&$blockingIssues, &$blockingIssueGroups): void {
            $blockingIssues[] = $message;
            $blockingIssueGroups[$groupTitle] ??= [];
            $blockingIssueGroups[$groupTitle][] = $message;
        };

        $dispositionOperations = $operationCollection
            ->filter(fn (array $operation) => in_array($operation['type'], ['keep_task', 'update_task', 'cancel_task'], true));
        $handledTaskIds = $dispositionOperations
            ->pluck('task_id')
            ->map(fn ($id) => (int) $id)
            ->unique();
        $unhandledTasks = $activeTasks
            ->reject(fn (Task $task) => $handledTaskIds->contains($task->id))
            ->map(fn (Task $task) => ['id' => $task->id, 'title' => $task->title])
            ->values()
            ->all();

        $cancelledTaskIds = $operationCollection
            ->where('type', 'cancel_task')
            ->pluck('task_id')
            ->map(fn ($id) => (int) $id);

        $duplicateCreateTitles = $operationCollection
            ->where('type', 'create_task')
            ->filter(function (array $operation) use ($activeTasks, $cancelledTaskIds) {
                return $activeTasks->contains(function (Task $task) use ($operation, $cancelledTaskIds) {
                    return ! $cancelledTaskIds->contains($task->id)
                        && mb_strtolower(trim($task->title)) === mb_strtolower(trim($operation['title']));
                });
            })
            ->pluck('title')
            ->unique()
            ->values()
            ->all();

        if ($action === 'append_tasks') {
            $warnings[] = 'タスク追加として処理します。既存の計画概要と既存タスクは変更されません。';

            if ($duplicateCreateTitles !== []) {
                $warnings[] = '既存タスクと同名の追加候補があります: ' . implode('、', $duplicateCreateTitles);
            }
        }

        if ($action === 'update_progress') {
            $warnings[] = '進捗更新として処理します。計画概要と既存タスクの構成自体は維持されます。';
        }

        if ($action === 'restructure_plan') {
            $hasCompleteDescriptionUpdate = $operationCollection
                ->where('type', 'update_plan')
                ->contains(fn (array $operation) => trim((string) ($operation['description'] ?? '')) !== '');

            if (! $hasCompleteDescriptionUpdate) {
                $addBlockingIssue(
                    '計画情報の更新',
                    '変更後の方針を単独で理解できる完全なdescriptionを含むupdate_planがありません。'
                );
            }

            if ($unhandledTasks !== []) {
                $taskLabels = collect($unhandledTasks)
                    ->map(fn (array $task) => "#{$task['id']} {$task['title']}")
                    ->implode('、');
                $addBlockingIssue(
                    '既存タスクの扱い',
                    '維持・更新・中止の指定がない既存タスクがあります: ' . $taskLabels
                );
            }

            if ($duplicateCreateTitles !== []) {
                $addBlockingIssue(
                    '重複するタスク',
                    '中止されない既存タスクと同名のcreate_taskがあります。既存タスクをkeep_taskまたはupdate_taskで扱ってください: ' . implode('、', $duplicateCreateTitles)
                );
            }

            foreach ($operationCollection->where('type', 'create_task') as $operation) {
                if (($operation['progress_percent'] ?? 0) <= 0) {
                    continue;
                }

                $taskTitle = (string) ($operation['title'] ?? '名称未設定のタスク');
                $progressOrigin = $operation['progress_origin'] ?? null;
                $groupTitle = '新規タスク「' . $taskTitle . '」';

                if ($progressOrigin === 'inherited_task' && empty($operation['source_task_ids'])) {
                    $addBlockingIssue(
                        $groupTitle,
                        '既存タスクから進捗を引き継ぐため、source_task_idsを指定してください。'
                    );
                }

                if (trim((string) ($operation['progress_reason'] ?? '')) === '') {
                    $addBlockingIssue(
                        $groupTitle,
                        '0%より大きい進捗を登録する根拠としてprogress_reasonを指定してください。'
                    );
                }
            }
        }

        $operationCounts = collect(['update_plan', 'update_availability', 'create_work_log', 'create_task', 'update_task', 'keep_task', 'cancel_task', 'reorder_tasks'])
            ->mapWithKeys(fn ($type) => [$type => $operationCollection->where('type', $type)->count()])
            ->all();

        $groupedIssues = collect($blockingIssueGroups)
            ->map(fn (array $items, string $title) => [
                'title' => $title,
                'items' => array_values(array_unique($items)),
            ])
            ->values()
            ->all();

        return [
            'active_task_count' => $activeTasks->count(),
            'handled_task_count' => $handledTaskIds->count(),
            'unhandled_tasks' => $unhandledTasks,
            'operation_counts' => $operationCounts,
            'blocking_issues' => array_values(array_unique($blockingIssues)),
            'blocking_issue_groups' => $groupedIssues,
            'warnings' => array_values(array_unique($warnings)),
            'normalization_notes' => $normalizationNotes,
            'atomic_apply' => $action === 'restructure_plan',
        ];
    }

    private function actionLabels(): array
    {
        return [
            'append_tasks' => '既存計画へのタスク追加',
            'restructure_plan' => '計画全体の再編',
            'update_progress' => '作業実績・進捗の更新',
        ];
    }

    private function storePendingDashboardImport(
        Request $request,
        ?Plan $plan,
        array $decoded,
        string $jsonText,
        ?string $action
    ): void {
        $previousPlanId = (int) $request->session()->get('dashboard_ai_json.plan_id', 0);
        $currentPlanId = $plan?->id ?? 0;

        if ($previousPlanId > 0 && $previousPlanId !== $currentPlanId) {
            $request->session()->forget([
                'plan_review_drafts.' . $previousPlanId,
                'plan_review_proposals.' . $previousPlanId,
            ]);
        }

        if ($plan) {
            $request->session()->forget($this->proposalSessionKey($plan));
            $request->session()->put('dashboard_ai_json.plan_id', $plan->id);
        } else {
            $request->session()->forget('dashboard_ai_json.plan_id');
        }

        $request->session()->put('dashboard_ai_json.pending', [
            'plan_id' => $plan?->id,
            'plan_title' => $plan?->title,
            'plan_category' => $plan?->category,
            'needs_plan_selection' => ! $plan,
            'needs_action_selection' => $action === null,
            'action' => $action,
            'action_label' => $action ? ($this->actionLabels()[$action] ?? $action) : null,
            'summary' => trim((string) ($decoded['summary'] ?? '対象計画または操作目的の確認が必要なAI JSON')),
            'has_tasks' => isset($decoded['tasks']) && is_array($decoded['tasks']),
            'has_operations' => isset($decoded['operations']) && is_array($decoded['operations']),
            'raw_json' => $jsonText,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function storeDashboardProposal(
        Request $request,
        Plan $plan,
        array $decoded,
        string $jsonText,
        string $action
    ) {
        $operations = $this->normalizeDecodedOperations(
            $plan,
            $decoded,
            allowTaskList: true,
            action: $action
        );

        $existingDraft = $request->session()->get($this->draftSessionKey($plan));
        $targetPlan = [
            'id' => $plan->id,
            'title' => $plan->title,
            'category' => $plan->category,
        ];

        if (is_array($existingDraft) && ! empty($existingDraft['prompt'])) {
            $draft = array_merge($existingDraft, [
                'import_channel' => 'dashboard_json_import',
                'target_plan' => $targetPlan,
                'imported_at' => now()->toIso8601String(),
            ]);
        } else {
            $draft = [
                'source' => 'dashboard_json_import',
                'task_id' => null,
                'worked_on' => now()->toDateString(),
                'actual_minutes' => null,
                'difficulty' => null,
                'activity_summary' => 'ダッシュボード共通AI JSON入力から変更案を読み込み',
                'discoveries' => '',
                'desired_outcome' => '対象計画と操作目的を検証し、安全に反映する',
                'prompt' => sprintf(
                    '[ダッシュボード共通AI JSON入力] action=%s / 対象計画 #%d %s。外部AIとの会話で生成されたJSONを直接読み込みました。',
                    $action,
                    $plan->id,
                    $plan->title
                ),
                'target_plan' => $targetPlan,
                'imported_at' => now()->toIso8601String(),
            ];
        }

        $proposal = $this->buildProposal($plan, $decoded, $operations, $jsonText, $action);
        $previousPlanId = (int) $request->session()->get('dashboard_ai_json.plan_id', 0);

        if ($previousPlanId > 0 && $previousPlanId !== $plan->id) {
            $request->session()->forget([
                'plan_review_drafts.' . $previousPlanId,
                'plan_review_proposals.' . $previousPlanId,
            ]);
        }

        $request->session()->put($this->draftSessionKey($plan), $draft);
        $request->session()->put($this->proposalSessionKey($plan), $proposal);
        $request->session()->put('dashboard_ai_json.plan_id', $plan->id);
        $request->session()->forget('dashboard_ai_json.pending');

        $message = $proposal['can_apply']
            ? '対象計画と操作目的を識別し、変更内容を読み込みました。'
            : 'JSONを読み込みましたが、安全に反映するための不足情報があります。警告内容を確認してください。';

        return redirect()
            ->route('home')
            ->with('status', $message);
    }

    private function resolveDashboardTargetPlan(
        Request $request,
        array $decoded,
        bool $allowMissing = false
    ): ?Plan {
        $descriptor = $this->extractTargetPlanDescriptor($decoded);
        $planId = $descriptor['id'] ?? null;
        $title = $this->normalizeRequiredText($descriptor['title'] ?? null);
        $hasCategory = array_key_exists('category', $descriptor);
        $category = $this->normalizeOptionalText($descriptor['category'] ?? null);

        if (($planId === null || $planId === '') && $title === null) {
            if ($allowMissing) {
                return null;
            }

            throw ValidationException::withMessages([
                'operations_json' => '対象計画を識別できません。target_plan.idまたはtarget_plan.titleをJSONへ含めてください。',
            ]);
        }

        if ($planId !== null && $planId !== '') {
            if (! is_numeric($planId) || (int) $planId < 1) {
                throw ValidationException::withMessages([
                    'operations_json' => 'target_plan.idは1以上の整数にしてください。',
                ]);
            }

            $plan = Plan::find((int) $planId);

            if (! $plan || ! $this->requestOwnsPlan($request, $plan)) {
                throw ValidationException::withMessages([
                    'operations_json' => '対象計画が見つからないか、このブラウザから編集できない計画です。',
                ]);
            }

            $this->validateTargetPlanDescriptor($plan, $decoded, required: true);

            return $plan;
        }

        $candidates = Plan::where('title', $title)
            ->get()
            ->filter(fn (Plan $plan) => $this->requestOwnsPlan($request, $plan));

        if ($hasCategory) {
            $candidates = $candidates->filter(
                fn (Plan $plan) => $this->normalizeOptionalText($plan->category) === $category
            );
        }

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'operations_json' => '計画名とカテゴリに一致する編集可能な計画が見つかりません。計画IDを含めると確実です。',
            ]);
        }

        if ($candidates->count() > 1) {
            $ids = $candidates->pluck('id')->implode(', ');

            throw ValidationException::withMessages([
                'operations_json' => "同名の計画が複数あります。target_plan.idを指定してください。候補ID: {$ids}",
            ]);
        }

        $plan = $candidates->first();
        $this->validateTargetPlanDescriptor($plan, $decoded, required: true);

        return $plan;
    }

    private function validateTargetPlanDescriptor(Plan $plan, array $decoded, bool $required = false): void
    {
        $descriptor = $this->extractTargetPlanDescriptor($decoded);

        if ($descriptor === []) {
            if ($required) {
                throw ValidationException::withMessages([
                    'operations_json' => 'target_planがありません。対象計画のIDまたは計画名を含めてください。',
                ]);
            }

            return;
        }

        $hasId = array_key_exists('id', $descriptor) && $descriptor['id'] !== null && $descriptor['id'] !== '';
        $title = $this->normalizeRequiredText($descriptor['title'] ?? null);

        if ($required && ! $hasId && $title === null) {
            throw ValidationException::withMessages([
                'operations_json' => 'target_planにはidまたはtitleが必要です。categoryだけでは対象計画を識別できません。',
            ]);
        }

        if ($hasId && (! is_numeric($descriptor['id']) || (int) $descriptor['id'] !== $plan->id)) {
            throw ValidationException::withMessages([
                'operations_json' => "JSONの対象計画IDと、読み込み先の計画IDが一致しません。JSON: {$descriptor['id']} / 読み込み先: {$plan->id}",
            ]);
        }

        if ($title !== null && $title !== trim($plan->title)) {
            throw ValidationException::withMessages([
                'operations_json' => "JSONの計画名「{$title}」と、対象計画「{$plan->title}」が一致しません。",
            ]);
        }

        if (array_key_exists('category', $descriptor)) {
            $jsonCategory = $this->normalizeOptionalText($descriptor['category']);
            $planCategory = $this->normalizeOptionalText($plan->category);

            if ($jsonCategory !== $planCategory) {
                $jsonLabel = $jsonCategory ?? '未設定';
                $planLabel = $planCategory ?? '未設定';

                throw ValidationException::withMessages([
                    'operations_json' => "JSONのカテゴリ「{$jsonLabel}」と、対象計画のカテゴリ「{$planLabel}」が一致しません。",
                ]);
            }
        }
    }

    private function extractTargetPlanDescriptor(array $decoded): array
    {
        if (array_key_exists('target_plan', $decoded)) {
            if (! is_array($decoded['target_plan'])) {
                throw ValidationException::withMessages([
                    'operations_json' => 'target_planはオブジェクト形式にしてください。',
                ]);
            }

            $target = $decoded['target_plan'];

            $descriptor = [
                'id' => $target['id'] ?? $target['plan_id'] ?? null,
                'title' => $target['title']
                    ?? $target['plan_title']
                    ?? $target['name']
                    ?? $target['plan_name']
                    ?? null,
            ];

            if (array_key_exists('category', $target) || array_key_exists('plan_category', $target)) {
                $descriptor['category'] = $target['category'] ?? $target['plan_category'];
            }

            return $descriptor;
        }

        $hasTopLevelTarget = array_key_exists('plan_id', $decoded)
            || array_key_exists('plan_title', $decoded)
            || array_key_exists('plan_name', $decoded)
            || array_key_exists('category', $decoded)
            || array_key_exists('plan_category', $decoded);

        if (! $hasTopLevelTarget) {
            return [];
        }

        $descriptor = [
            'id' => $decoded['plan_id'] ?? null,
            'title' => $decoded['plan_title'] ?? $decoded['plan_name'] ?? null,
        ];

        if (array_key_exists('category', $decoded) || array_key_exists('plan_category', $decoded)) {
            $descriptor['category'] = $decoded['category'] ?? $decoded['plan_category'];
        }

        return $descriptor;
    }

    private function requestOwnsPlan(Request $request, Plan $plan): bool
    {
        return app(PlanOwnershipService::class)->owns($request, $plan);
    }

    private function normalizeRequiredText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = $this->normalizeRequiredText($value);

        return in_array($normalized, [null, '未設定'], true) ? null : $normalized;
    }

    private function extractJson(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/```\s*(.*?)\s*```/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }

    private function resolveTaskStatus(int $progressPercent): string
    {
        if ($progressPercent >= 100) {
            return 'done';
        }

        if ($progressPercent > 0) {
            return 'doing';
        }

        return 'todo';
    }

    private function resolveWorkSessionContext(Plan $plan, mixed $workSessionId): ?WorkSession
    {
        if ($workSessionId === null || $workSessionId === '') {
            return null;
        }

        $workSession = WorkSession::query()
            ->with(['task', 'plan'])
            ->where('plan_id', $plan->id)
            ->whereKey((int) $workSessionId)
            ->firstOrFail();

        if ($workSession->status !== 'completed') {
            throw ValidationException::withMessages([
                'work_session_id' => '終了済みの作業セッションだけ計画更新へ引き継げます。',
            ]);
        }

        return $workSession;
    }

    private function draftSessionKey(Plan $plan): string
    {
        return 'plan_review_drafts.' . $plan->id;
    }

    private function proposalSessionKey(Plan $plan): string
    {
        return 'plan_review_proposals.' . $plan->id;
    }

    private function authorizePlanOwner(Plan $plan): void
    {
        app(PlanOwnershipService::class)->authorizePlan(request(), $plan);
    }
}
