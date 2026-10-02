<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanAdjustment;
use App\Models\Task;
use App\Models\WorkLog;
use App\Services\AchievementProjectionService;
use App\Services\PlanProgressService;
use App\Services\PlanTimelineService;
use Carbon\Carbon;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    private const SESSION_KEY = 'pace_keeper.chat_draft';

    private const FLOWS = [
        'work_log',
        'policy_change',
        'ai_context',
        'task',
        'plan',
        'review',
        'status',
    ];

    private const CHANGE_TYPES = [
        'abandon_direction',
        'new_idea',
        'priority_change',
        'scope_change',
        'deadline_change',
        'task_obsolete',
        'other',
    ];

    private const CONTEXT_PURPOSES = [
        'next_action',
        'problems',
        'deadline',
        'task_structure',
        'progress',
        'free',
    ];

    public function index(Request $request, PlanProgressService $progressService)
    {
        $ownedPlans = $this->ownedPlans($request, [
            'tasks',
            'workLogs',
            'adjustments' => fn ($query) => $query->latest('applied_at')->limit(10),
        ]);
        $draft = $request->session()->get(self::SESSION_KEY);

        if ($draft && ! in_array($draft['flow'] ?? null, self::FLOWS, true)) {
            $request->session()->forget(self::SESSION_KEY);
            $draft = null;
        }

        $planItems = $ownedPlans
            ->map(fn (Plan $plan) => [
                'plan' => $plan,
                'progress' => $progressService->calculate($plan),
            ]);

        $inProgressTasks = $ownedPlans
            ->flatMap(fn (Plan $plan) => $plan->tasks
                ->where('status', 'doing')
                ->map(fn (Task $task) => ['plan' => $plan, 'task' => $task]))
            ->values();

        $recentWorkLogs = WorkLog::with(['plan', 'task'])
            ->whereIn('plan_id', $ownedPlans->pluck('id'))
            ->latest('worked_on')
            ->latest('id')
            ->take(5)
            ->get();

        $selectedPlan = null;
        $selectedTask = null;

        if ($draft && ! empty($draft['answers']['plan_id'])) {
            $selectedPlan = $ownedPlans->firstWhere('id', (int) $draft['answers']['plan_id']);

            if ($selectedPlan && array_key_exists('task_id', $draft['answers']) && $draft['answers']['task_id']) {
                $selectedTask = $selectedPlan->tasks->firstWhere('id', (int) $draft['answers']['task_id']);
            }
        }

        return view('chat.index', [
            'ownedPlans' => $ownedPlans,
            'planItems' => $planItems,
            'inProgressTasks' => $inProgressTasks,
            'recentWorkLogs' => $recentWorkLogs,
            'draft' => $draft,
            'selectedPlan' => $selectedPlan,
            'selectedTask' => $selectedTask,
            'changeTypeLabels' => $this->changeTypeLabels(),
            'contextPurposeLabels' => $this->contextPurposeLabels(),
        ]);
    }

    public function achievements(Request $request, AchievementProjectionService $achievements)
    {
        $plans = $this->ownedPlans($request, [
            'tasks',
            'workLogs',
            'adjustments',
        ]);

        $completedPlans = $plans
            ->filter(fn (Plan $plan) => $achievements->isCompleted($plan))
            ->sortByDesc(fn (Plan $plan) => $achievements->completedAt($plan)?->timestamp ?? 0)
            ->values()
            ->map(fn (Plan $plan, int $index) => $achievements->project($plan, $index));

        return view('achievements.index', [
            'completedPlans' => $completedPlans,
        ]);
    }

    public function achievement(
        Request $request,
        Plan $plan,
        AchievementProjectionService $achievements,
        PlanTimelineService $timelineService
    ) {
        $plan = $this->findOwnedPlan($request, $plan->id, [
            'tasks',
            'workLogs.task',
            'adjustments',
        ]);

        if (! $achievements->isCompleted($plan)) {
            abort(404);
        }

        $achievement = $achievements->project($plan);
        $completedAt = $achievement['completed_at'];
        $totalMinutes = $achievement['actual_minutes'];
        $doneTasks = $achievement['completed_tasks'];
        $adjustmentCount = $achievement['adjustment_count'];
        $durationDays = $completedAt
            ? max(1, (int) floor($plan->start_date->startOfDay()->diffInDays($completedAt->copy()->startOfDay())) + 1)
            : null;

        $praise = $adjustmentCount > 0
            ? "最初の計画に固執せず、実績に合わせて{$adjustmentCount}回見直しながら最後まで進めました。{$plan->title}の達成、おめでとう！"
            : "日々の積み重ねを最後まで成果につなげました。{$plan->title}の達成、おめでとう！";

        return view('achievements.show', [
            'plan' => $plan,
            'progress' => $achievement['progress'],
            'timeline' => $timelineService->build($plan)->take(20),
            'completedAt' => $completedAt,
            'totalMinutes' => $totalMinutes,
            'doneTasks' => $doneTasks,
            'adjustmentCount' => $adjustmentCount,
            'durationDays' => $durationDays,
            'praise' => $praise,
        ]);
    }

    public function start(Request $request, string $flow)
    {
        if (! in_array($flow, self::FLOWS, true)) {
            abort(404);
        }

        $answers = [];
        $step = $this->firstStep($flow);

        if ($flow === 'review') {
            // UI上は「計画を更新」に一本化する。
            // result_recording は実績記録と構造変更の両方を扱えるため、内部flowとして継続利用する。
            $answers['review_flow'] = 'result_recording';
        }

        if ($request->filled('plan_id')) {
            $plan = $this->findOwnedPlan($request, (int) $request->input('plan_id'), ['tasks']);
            $answers['plan_id'] = $plan->id;

            $step = match ($flow) {
                'task' => 'details',
                'policy_change' => 'change_type',
                'ai_context' => 'context_mode',
                default => $step,
            };

            if ($flow === 'review') {
                $request->session()->forget(self::SESSION_KEY);

                return redirect()->route('plans.review_assistant.show', [
                    'plan' => $plan,
                    'task_id' => $request->input('task_id'),
                    'flow' => $answers['review_flow'] ?? 'result_recording',
                ]);
            }
        }

        if ($flow === 'work_log' && $request->filled('task_id')) {
            if (empty($answers['plan_id'])) {
                throw ValidationException::withMessages([
                    'plan_id' => 'タスクを指定する場合は計画も指定してください。',
                ]);
            }

            $task = Task::query()
                ->where('plan_id', $answers['plan_id'])
                ->whereKey((int) $request->input('task_id'))
                ->firstOrFail();

            if (in_array($task->status, ['done', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'task_id' => '完了または中止済みのタスクには実績を追加できません。',
                ]);
            }

            $answers['task_id'] = $task->id;
        }

        $request->session()->put(self::SESSION_KEY, [
            'flow' => $flow,
            'step' => $step,
            'answers' => $answers,
        ]);

        return redirect()->route('chat.index');
    }

    public function answer(Request $request, PlanProgressService $progressService)
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! $draft) {
            return redirect()->route('chat.index');
        }

        $flow = $draft['flow'];
        $step = $draft['step'];
        $answers = $draft['answers'] ?? [];

        [$values, $nextStep, $redirect] = match ($flow) {
            'work_log' => $this->answerWorkLog($request, $step, $answers),
            'policy_change' => $this->answerPolicyChange($request, $step, $answers, $progressService),
            'ai_context' => $this->answerAiContext($request, $step, $answers, $progressService),
            'task' => $this->answerTask($request, $step),
            'plan' => $this->answerPlan($request, $step),
            'review' => $this->answerReview($request, $step, $answers),
            default => throw ValidationException::withMessages([
                'chat' => 'この操作では回答を受け付けていません。',
            ]),
        };

        if ($redirect) {
            $request->session()->forget(self::SESSION_KEY);

            return $redirect;
        }

        $request->session()->put(self::SESSION_KEY, [
            'flow' => $flow,
            'step' => $nextStep,
            'answers' => array_merge($answers, $values),
        ]);

        return redirect()->route('chat.index');
    }

    public function confirm(Request $request)
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! $draft || ($draft['step'] ?? null) !== 'confirm') {
            return redirect()->route('chat.index');
        }

        $flow = $draft['flow'];
        $answers = $draft['answers'] ?? [];
        $result = null;

        DB::transaction(function () use ($request, $flow, $answers, &$result) {
            if ($flow === 'work_log') {
                $plan = $this->findOwnedPlan($request, (int) $answers['plan_id'], ['tasks']);
                $task = null;

                if (! empty($answers['task_id'])) {
                    $task = $plan->tasks->firstWhere('id', (int) $answers['task_id']);

                    if (! $task || in_array($task->status, ['done', 'cancelled'], true)) {
                        throw ValidationException::withMessages([
                            'chat' => '選択したタスクの状態が変更されています。最初からやり直してください。',
                        ]);
                    }
                }

                WorkLog::create([
                    'plan_id' => $plan->id,
                    'task_id' => $task?->id,
                    'task_title_snapshot' => $task?->title,
                    'worked_on' => $answers['worked_on'],
                    'actual_minutes' => (int) $answers['actual_minutes'],
                    'progress_delta_percent' => (int) ($answers['progress_delta_percent'] ?? 0),
                    'progress_before_percent' => $task?->progress_percent,
                    'progress_after_percent' => $task
                        ? min(100, $task->progress_percent + (int) ($answers['progress_delta_percent'] ?? 0))
                        : null,
                    'remaining_minutes_before' => $task?->remaining_minutes,
                    'remaining_minutes_after' => $task
                        ? max((int) $task->remaining_minutes - (int) $answers['actual_minutes'], 0)
                        : null,
                    'difficulty' => $answers['difficulty'] ?? null,
                    'memo' => $answers['memo'] ?: null,
                    'outcome' => $answers['memo'] ?: null,
                ]);

                if ($task) {
                    $newProgress = min(100, $task->progress_percent + (int) ($answers['progress_delta_percent'] ?? 0));
                    $newStatus = $this->resolveTaskStatus($newProgress, $task->status, (int) $answers['actual_minutes']);

                    $task->update([
                        'progress_percent' => $newProgress,
                        'remaining_minutes' => $newProgress >= 100
                            ? 0
                            : max((int) $task->remaining_minutes - (int) $answers['actual_minutes'], 0),
                        'status' => $newStatus,
                    ]);
                }

                $result = [
                    'message' => '進捗報告を登録しました。方針にも影響がある場合は、続けて方針変更を報告できます。',
                    'label' => '計画を確認',
                    'url' => route('plans.show', $plan),
                ];
            }

            if ($flow === 'task') {
                $plan = $this->findOwnedPlan($request, (int) $answers['plan_id']);
                $sortOrder = (int) $plan->tasks()->max('sort_order') + 1;

                $task = Task::create([
                    'plan_id' => $plan->id,
                    'title' => $answers['title'],
                    'description' => $answers['description'] ?: null,
                    'estimated_minutes' => (int) $answers['estimated_minutes'],
                    'remaining_minutes' => (int) $answers['estimated_minutes'],
                    'progress_percent' => 0,
                    'status' => 'todo',
                    'priority' => (int) $answers['priority'],
                    'activation_cost' => (int) ($answers['activation_cost'] ?? 3),
                    'sort_order' => $sortOrder,
                ]);

                $result = [
                    'message' => '新しいタスクを追加しました。',
                    'label' => 'タスクを確認',
                    'url' => route('plans.show', $task->plan_id),
                ];
            }

            if ($flow === 'plan') {
                $ownerToken = Str::random(64);
                $plan = Plan::create([
                    'user_id' => $request->user()?->id,
                    'owner_token' => $ownerToken,
                    'public_slug' => Str::uuid()->toString(),
                    'title' => $answers['title'],
                    'description' => $answers['description'] ?: null,
                    'category' => $answers['category'] ?: null,
                    'start_date' => $answers['start_date'],
                    'deadline' => $answers['deadline'],
                    'is_public' => (bool) $answers['is_public'],
                ]);

                if (! $request->user()) {
                    cookie()->queue(
                        'pace_keeper_owner_token_' . $plan->id,
                        $ownerToken,
                        60 * 24 * 365,
                        '/',
                        null,
                        app()->environment('production') || $request->isSecure(),
                        true,
                        false,
                        'lax'
                    );
                }

                $result = [
                    'message' => '新しい計画を作成しました。AIへ計画全体を共有するプロンプトもチャットから生成できます。',
                    'label' => '計画を開く',
                    'url' => route('plans.show', $plan),
                ];
            }
        });

        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('chat.index')
            ->with('success', $result['message'] ?? '操作を完了しました。')
            ->with('chat_result', $result);
    }

    public function markContextExported(Request $request)
    {
        $draft = $request->session()->get(self::SESSION_KEY);

        if (! $draft || ($draft['flow'] ?? null) !== 'ai_context' || ($draft['step'] ?? null) !== 'prompt') {
            return redirect()->route('chat.index');
        }

        $planId = (int) ($draft['answers']['plan_id'] ?? 0);
        $plan = $this->findOwnedPlan($request, $planId);
        $exportedAt = now();

        $plan->update(['last_ai_context_exported_at' => $exportedAt]);

        $draft['answers']['exported_at'] = $exportedAt->toIso8601String();
        $request->session()->put(self::SESSION_KEY, $draft);

        return redirect()
            ->route('chat.index')
            ->with('success', 'プロンプトを共有済みとして記録しました。次回はこの時点以降の差分だけを生成できます。');
    }

    public function reset(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('chat.index');
    }

    private function answerWorkLog(Request $request, string $step, array $answers): array
    {
        if ($step === 'activity') {
            $validated = $request->validate([
                'memo' => ['required', 'string', 'max:5000'],
            ]);

            return [['memo' => trim($validated['memo'])], 'worked_on', null];
        }

        if ($step === 'worked_on') {
            $validated = $request->validate([
                'worked_on' => ['required', 'date'],
            ]);

            return [['worked_on' => $validated['worked_on']], 'minutes', null];
        }

        if ($step === 'minutes') {
            $validated = $request->validate([
                'minutes_choice' => ['required', 'in:15,30,60,90,custom'],
                'custom_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            ]);

            $minutes = $validated['minutes_choice'] === 'custom'
                ? ($validated['custom_minutes'] ?? null)
                : (int) $validated['minutes_choice'];

            if (! $minutes) {
                throw ValidationException::withMessages([
                    'custom_minutes' => '作業時間を入力してください。',
                ]);
            }

            $nextStep = empty($answers['plan_id'])
                ? 'plan'
                : (array_key_exists('task_id', $answers) ? 'result' : 'task');

            return [['actual_minutes' => (int) $minutes], $nextStep, null];
        }

        if ($step === 'plan') {
            $validated = $request->validate(['plan_id' => ['required', 'integer']]);
            $this->findOwnedPlan($request, (int) $validated['plan_id']);

            return [['plan_id' => (int) $validated['plan_id']], 'task', null];
        }

        if ($step === 'task') {
            $validated = $request->validate([
                'task_id' => ['nullable', 'string'],
            ]);

            $taskId = ($validated['task_id'] ?? null) === 'none'
                ? null
                : (int) ($validated['task_id'] ?? 0);

            if ($taskId) {
                $plan = $this->findOwnedPlan($request, (int) $answers['plan_id']);
                $exists = $plan->tasks()
                    ->whereKey($taskId)
                    ->whereNotIn('status', ['done', 'cancelled'])
                    ->exists();

                if (! $exists) {
                    throw ValidationException::withMessages([
                        'task_id' => '選択したタスクはこの計画で利用できません。',
                    ]);
                }
            }

            return [['task_id' => $taskId], 'result', null];
        }

        if ($step === 'result') {
            $validated = $request->validate([
                'progress_delta_percent' => ['required', 'integer', 'min:0', 'max:100'],
                'difficulty' => ['required', Rule::in(['easy', 'normal', 'hard'])],
            ]);

            if (empty($answers['task_id'])) {
                $validated['progress_delta_percent'] = 0;
            }

            return [$validated, 'confirm', null];
        }

        throw ValidationException::withMessages(['chat' => '進捗報告の入力段階が正しくありません。']);
    }

    private function answerPolicyChange(
        Request $request,
        string $step,
        array $answers,
        PlanProgressService $progressService
    ): array {
        if ($step === 'plan') {
            $validated = $request->validate(['plan_id' => ['required', 'integer']]);
            $this->findOwnedPlan($request, (int) $validated['plan_id']);

            return [['plan_id' => (int) $validated['plan_id']], 'change_type', null];
        }

        if ($step === 'change_type') {
            $validated = $request->validate([
                'change_type' => ['required', Rule::in(self::CHANGE_TYPES)],
            ]);

            return [$validated, 'change_summary', null];
        }

        if ($step === 'change_summary') {
            $validated = $request->validate([
                'before_state' => ['nullable', 'string', 'max:5000'],
                'after_state' => ['required', 'string', 'max:5000'],
            ]);

            return [[
                'before_state' => trim((string) ($validated['before_state'] ?? '')),
                'after_state' => trim($validated['after_state']),
            ], 'reason', null];
        }

        if ($step === 'reason') {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:5000'],
                'evidence' => ['nullable', 'string', 'max:5000'],
            ]);

            return [[
                'reason' => trim($validated['reason']),
                'evidence' => trim((string) ($validated['evidence'] ?? '')),
            ], 'impact', null];
        }

        if ($step === 'impact') {
            $validated = $request->validate([
                'affected_scope' => ['nullable', 'string', 'max:5000'],
                'deadline_effect' => ['required', Rule::in(['none', 'review', 'change'])],
                'completion_condition_changed' => ['required', 'boolean'],
            ]);

            return [[
                'affected_scope' => trim((string) ($validated['affected_scope'] ?? '')),
                'deadline_effect' => $validated['deadline_effect'],
                'completion_condition_changed' => (bool) $validated['completion_condition_changed'],
            ], 'constraints', null];
        }

        if ($step === 'constraints') {
            $validated = $request->validate([
                'must_keep' => ['nullable', 'string', 'max:5000'],
                'ai_request' => ['nullable', 'string', 'max:5000'],
            ]);

            $completedAnswers = array_merge($answers, [
                'must_keep' => trim((string) ($validated['must_keep'] ?? '')),
                'ai_request' => trim((string) ($validated['ai_request'] ?? '')),
            ]);

            $plan = $this->findOwnedPlan($request, (int) $completedAnswers['plan_id'], [
                'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id')->limit(15),
                'adjustments' => fn ($query) => $query->latest('applied_at')->limit(10),
            ]);
            $prompt = $this->buildPolicyChangePrompt(
                $plan,
                $progressService->calculate($plan),
                $completedAnswers
            );

            $reviewDraft = [
                'task_id' => null,
                'worked_on' => now()->toDateString(),
                'actual_minutes' => null,
                'difficulty' => null,
                'activity_summary' => '方針変更の報告：' . $completedAnswers['after_state'],
                'discoveries' => $this->policyChangeDiscoveryText($completedAnswers),
                'desired_outcome' => $completedAnswers['ai_request'] !== ''
                    ? $completedAnswers['ai_request']
                    : '現在の計画との差分を評価し、計画概要・タスク・進捗を現実に合わせて再構成してほしい。',
                'prompt' => $prompt,
                'source' => 'chat_policy_change',
            ];

            $request->session()->put('plan_review_drafts.' . $plan->id, $reviewDraft);
            $request->session()->forget('plan_review_proposals.' . $plan->id);

            return [[
                'must_keep' => $completedAnswers['must_keep'],
                'ai_request' => $completedAnswers['ai_request'],
                'prompt' => $prompt,
            ], 'prompt', null];
        }

        throw ValidationException::withMessages(['chat' => '方針変更報告の入力段階が正しくありません。']);
    }

    private function answerAiContext(
        Request $request,
        string $step,
        array $answers,
        PlanProgressService $progressService
    ): array {
        if ($step === 'plan') {
            $validated = $request->validate(['plan_id' => ['required', 'integer']]);
            $this->findOwnedPlan($request, (int) $validated['plan_id']);

            return [['plan_id' => (int) $validated['plan_id']], 'context_mode', null];
        }

        if ($step === 'context_mode') {
            $validated = $request->validate([
                'context_mode' => ['required', Rule::in(['full', 'diff'])],
            ]);

            return [$validated, 'purpose', null];
        }

        if ($step === 'purpose') {
            $validated = $request->validate([
                'purpose' => ['required', Rule::in(self::CONTEXT_PURPOSES)],
            ]);

            return [$validated, 'question', null];
        }

        if ($step === 'question') {
            $validated = $request->validate([
                'question' => ['nullable', 'string', 'max:5000'],
            ]);

            $completedAnswers = array_merge($answers, [
                'question' => trim((string) ($validated['question'] ?? '')),
            ]);

            $plan = $this->findOwnedPlan($request, (int) $completedAnswers['plan_id'], [
                'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'workLogs' => fn ($query) => $query->with('task')->latest('worked_on')->latest('id')->limit(30),
                'adjustments' => fn ($query) => $query->latest('applied_at')->limit(20),
            ]);

            $effectiveMode = $completedAnswers['context_mode'];

            if ($effectiveMode === 'diff' && ! $plan->last_ai_context_exported_at) {
                $effectiveMode = 'full';
            }

            $prompt = $this->buildAiContextPrompt(
                $plan,
                $progressService->calculate($plan),
                $completedAnswers,
                $effectiveMode
            );

            return [[
                'question' => $completedAnswers['question'],
                'effective_context_mode' => $effectiveMode,
                'prompt' => $prompt,
            ], 'prompt', null];
        }

        throw ValidationException::withMessages(['chat' => 'AI共有プロンプトの入力段階が正しくありません。']);
    }

    private function answerTask(Request $request, string $step): array
    {
        if ($step === 'plan') {
            $validated = $request->validate(['plan_id' => ['required', 'integer']]);
            $this->findOwnedPlan($request, (int) $validated['plan_id']);

            return [['plan_id' => (int) $validated['plan_id']], 'details', null];
        }

        if ($step === 'details') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
            ]);

            return [[
                'title' => $validated['title'],
                'description' => $validated['description'] ?? '',
            ], 'estimate', null];
        }

        if ($step === 'estimate') {
            $validated = $request->validate([
                'minutes_choice' => ['required', 'in:30,60,120,240,custom'],
                'custom_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ]);

            $minutes = $validated['minutes_choice'] === 'custom'
                ? ($validated['custom_minutes'] ?? null)
                : (int) $validated['minutes_choice'];

            if ($minutes === null) {
                throw ValidationException::withMessages([
                    'custom_minutes' => '想定時間を入力してください。',
                ]);
            }

            return [['estimated_minutes' => (int) $minutes], 'settings', null];
        }

        if ($step === 'settings') {
            $validated = $request->validate([
                'priority' => ['required', 'integer', 'min:1', 'max:5'],
                'activation_cost' => ['required', 'integer', 'min:1', 'max:5'],
            ]);

            return [$validated, 'confirm', null];
        }

        throw ValidationException::withMessages(['chat' => 'タスク追加の入力段階が正しくありません。']);
    }

    private function answerPlan(Request $request, string $step): array
    {
        if ($step === 'goal') {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
            ]);

            return [[
                'title' => $validated['title'],
                'description' => $validated['description'] ?? '',
            ], 'category', null];
        }

        if ($step === 'category') {
            $validated = $request->validate([
                'category' => ['nullable', 'string', 'max:100'],
            ]);

            return [['category' => $validated['category'] ?? ''], 'dates', null];
        }

        if ($step === 'dates') {
            $validated = $request->validate([
                'start_date' => ['required', 'date'],
                'deadline' => ['required', 'date', 'after_or_equal:start_date'],
            ]);

            return [$validated, 'visibility', null];
        }

        if ($step === 'visibility') {
            $validated = $request->validate([
                'is_public' => ['required', 'boolean'],
            ]);

            return [['is_public' => (bool) $validated['is_public']], 'confirm', null];
        }

        throw ValidationException::withMessages(['chat' => '計画作成の入力段階が正しくありません。']);
    }

    private function answerReview(Request $request, string $step, array $answers): array
    {
        if ($step !== 'plan') {
            throw ValidationException::withMessages(['chat' => '計画見直しの入力段階が正しくありません。']);
        }

        $validated = $request->validate(['plan_id' => ['required', 'integer']]);
        $plan = $this->findOwnedPlan($request, (int) $validated['plan_id']);

        return [[], 'complete', redirect()->route('plans.review_assistant.show', [
            'plan' => $plan,
            'flow' => $answers['review_flow'] ?? 'result_recording',
        ])];
    }

    private function buildPolicyChangePrompt(Plan $plan, array $progress, array $answers): string
    {
        $taskLines = $this->taskContextLines($plan->tasks);
        $logLines = $this->workLogContextLines($plan->workLogs);
        $adjustmentLines = $this->adjustmentContextLines($plan->adjustments);
        $changeType = $this->changeTypeLabels()[$answers['change_type']] ?? $answers['change_type'];
        $before = $answers['before_state'] !== '' ? $answers['before_state'] : '明確な変更前方針は未入力';
        $evidence = $answers['evidence'] !== '' ? $answers['evidence'] : '特になし';
        $affectedScope = $answers['affected_scope'] !== '' ? $answers['affected_scope'] : 'AIに影響範囲の特定を依頼する';
        $mustKeep = $answers['must_keep'] !== '' ? $answers['must_keep'] : '特になし';
        $aiRequest = $answers['ai_request'] !== ''
            ? $answers['ai_request']
            : '変更後の方針に合わせて、計画概要・タスク構成・優先度・想定時間・進捗率を再評価してほしい。';
        $deadlineEffect = match ($answers['deadline_effect']) {
            'none' => '期限への影響なし',
            'review' => '期限を見直す必要があるか判断してほしい',
            'change' => '期限変更が必要',
            default => '未指定',
        };
        $completionChanged = $answers['completion_condition_changed']
            ? '変更あり。新しい完成条件が十分に明示されていない場合は、確定前に質問すること'
            : '変更なし';
        $description = $plan->description ?: '未設定';
        $category = $plan->category ?: '未設定';
        $deadlineText = $plan->deadline?->format('Y-m-d') ?? '未設定';
        $expectedProgressText = $progress['expected_progress_percent'] === null ? '未設定' : $progress['expected_progress_percent'] . '%';
        $remainingDaysText = $progress['remaining_days'] === null ? '未設定' : $progress['remaining_days'] . '日';
        $targetTitleJson = json_encode($plan->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $targetCategoryJson = json_encode($plan->category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
あなたは、現実の変化に合わせて長期計画を再構成する進捗管理アシスタントです。
最初の計画を守ること自体を目的にせず、今回の最新報告を起点に、次に実行しやすい計画へ更新してください。

【情報の位置づけと優先順位】
このプロンプトには、Canoviaへ登録されている情報と、今回ユーザーが申告した最新状況の両方が含まれます。
登録済みの概要・タスク・進捗計算は、現実の最新方針に追従していない可能性があります。
矛盾がある場合は、次の順に信頼してください。

1. 今回の方針変更報告と、追加質問に対するユーザーの回答
2. 日時が新しい、適用済みの方針変更履歴
3. 最近の作業ログと、そこで確認された実際の成果・検証結果
4. Canoviaへ登録されている計画概要・タスク
5. 登録済みタスクを基準に算出された進捗率・残り時間・状態

登録情報と最新報告が矛盾する場合、登録情報を正しい前提として補完せず、最新報告を優先してください。

【今回の最新方針変更報告：最優先】
変更種別: {$changeType}
変更前:
{$before}

変更後:
{$answers['after_state']}

変更理由:
{$answers['reason']}

判断材料・検証結果:
{$evidence}

影響しそうな範囲:
{$affectedScope}

期限への影響: {$deadlineEffect}
完成条件の変更: {$completionChanged}
維持したい条件:
{$mustKeep}

AIに依頼したいこと:
{$aiRequest}

【Canovia登録上の計画：変更前スナップショットの可能性あり】
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

※上記の数値は登録済みタスク構成を前提とした暫定値です。方針・完成条件・タスク構成が変わる場合、そのまま事実として扱わず、再編後の構成から評価し直してください。

【登録済みタスク】
{$taskLines}

【最近の作業ログ】
{$logLines}

【適用済みの方針変更履歴】
{$adjustmentLines}

【判断手順】
- まず、最新報告と登録情報の間にある矛盾・未確定事項を確認する
- 方針変更後の目的、ゲームループ、完成条件などが不明確で、タスク再編に影響する場合は、JSONを出力せず最大5個まで質問する
- 十分な情報がある場合は、関連する既存タスクを「維持・更新・統合・中止」の観点で評価する
- 登録済みの全タスク（statusがcancelled以外）を、keep_task・update_task・cancel_taskのいずれか1つで必ず評価する
- keep_taskはDB上の値を変えず、再編後も維持することを明示する確認操作として使う。reasonを必ず含める
- 登録済みタスクを残すこと自体を目的にせず、変更後の完成条件に必要な作業だけを残す
- restructure_planでは、update_planに変更後の方針を単独で理解できる完全なdescriptionを必ず含める
- 不要になったタスクは物理削除せず cancel_task を使う
- 必要になった作業は、具体的な達成条件と現実的な想定時間を持つ create_task として提案する
- 既存タスクを更新する場合は、上記の正しいタスクIDだけを使う
- 同じタスクに update_task と cancel_task を同時に出力しない
- 同じ項目に矛盾する複数操作を出力しない
- 現在値と同じ update_plan・update_task は出力しない
- 進捗率は、旧計画を守れた割合ではなく、変更後の完成条件に対する達成済み成果から再評価する
- 期限変更は必要性が明確な場合だけ提案する
- ユーザーが維持したい条件は変更しない
- 各操作の reason に、最新報告のどの事実を根拠にしたかを記載する
- 実作業時間が報告されていない場合、create_work_log を作らない
- 作業ログの progress_delta_percent と update_task の progress_percent を併用する場合、二重加算を起こさない
- 方針変更を反映する場合、計画概要やタスク構成の変更だけで終了せず、影響を受ける既存タスクと新規タスクについて、progress_percent・status・estimated_minutes・priorityを同じ回答内で再評価する
- 既存タスクを維持または更新する場合、変更後の達成条件に照らして現在の進捗率を再判定し、値を変える必要がある場合は同じupdate_taskに含める
- 旧タスクの成果を新しいタスクへ引き継げる場合、新規タスクを必ず0%から始めず、再利用できる成果をprogress_percentとstatusへ反映する
- 進捗率は投入時間ではなく、変更後の達成条件に対して確認済みの成果が占める割合として判断する
- 数値の根拠が不足している場合は推測で進捗率を決めず、JSONを出す前に質問する
- 計画全体の進捗率は操作として出力しない。タスク反映後にCanovia側で再計算する

【進捗再評価の必須条件】
今回の方針変更がタスク構成・完成条件・優先順位のいずれかへ影響する場合、次の処理を一度のJSONで完結させてください。

1. 古い計画概要や期限の更新
2. 不要タスクの中止
3. 必要タスクの追加・統合・変更
4. 影響を受けるタスクの進捗率・状態・想定時間・優先度の再評価
5. 既存成果を新しいタスクへ引き継ぐ場合の進捗反映

タスク再編後にユーザーが別途進捗率を手動修正しなくて済む状態を目標にしてください。
ただし、影響を受けない項目や現在値が妥当な項目について、同じ値の更新操作を作る必要はありません。

情報が不足している場合は、JSONを出力せず質問してください。
十分な情報を得た後は、説明文やMarkdownコードブロックを付けず、次の形式のJSONのみを出力してください。
以下は構造例であり、operations には実際に必要な操作だけを含めてください。

{
  "schema_version": "1.1",
  "action": "restructure_plan",
  "target_plan": {
    "id": {$plan->id},
    "title": {$targetTitleJson},
    "category": {$targetCategoryJson}
  },
  "summary": "方針変更の評価と計画再構成の要約",
  "operations": [
    {
      "type": "create_task",
      "client_ref": "new_task_1",
      "title": "変更後に必要な新しいタスク",
      "description": "完了を判定できる具体的な達成条件",
      "estimated_minutes": 120,
      "priority": 1,
      "progress_percent": 0,
      "status": "todo",
      "reason": "追加する理由"
    }
  ]
}

【操作仕様】
- schema_version は "1.1" にする
- action は必ず "restructure_plan" にする
- target_plan は上記のid・title・categoryを変更せずそのまま出力する
- type は update_plan、create_work_log、create_task、update_task、keep_task、cancel_task のいずれか
- update_plan は title、description、category、start_date、deadline、is_public のうち変更する項目だけを含める
- update_plan の description は差分追記ではなく、反映後の完全な概要文にする
- update_plan の start_date と deadline は YYYY-MM-DD
- create_work_log は実際の作業も記録すべき場合だけ使用する
- create_work_log の task_id と task_ref は省略可能。その場合は計画全体の作業ログになる
- worked_on は YYYY-MM-DD
- actual_minutes は1以上の整数
- progress_delta_percent と progress_percent は0～100
- difficulty は easy、normal、hard、stuck、または null
- priority は1～5
- status は todo、doing、done
- update_task は変更する項目だけを含める。ただし方針変更の影響を受けるタスクでは、progress_percent・status・estimated_minutes・priorityも同時に再評価し、変更が必要な項目を同じ操作へ含める
- create_task でprogress_percentを0より大きくする場合は、進捗の由来をprogress_originで示す
- progress_origin は existing_work（Canovia登録前から存在する成果）、inherited_task（登録済み旧タスクから引継ぎ）、new_work（今回の作業実績）のいずれか
- progress_originがinherited_taskの場合だけsource_task_idsを必須とし、引継ぎ元の登録済みタスクIDを配列で含める
- 0%より大きい進捗には、どの成果を根拠に判断したかをprogress_reasonへ具体的に含める
- 登録済みタスクが存在しない既存成果を登録する場合はprogress_originをexisting_workにし、source_task_idsは付けない
- keep_task は既存タスクを再編後もそのまま維持するときに使い、task_idとreasonを含める
- cancel_task はタスクを削除せず、状態を中止へ変更する
- 存在しないタスクIDを作らない
- 同じタスクに相反する操作を出力しない
- 不要な操作は出力しない
- 操作は60件以内
PROMPT;
    }

    private function buildAiContextPrompt(
        Plan $plan,
        array $progress,
        array $answers,
        string $effectiveMode
    ): string {
        $since = $plan->last_ai_context_exported_at;
        $isDiff = $effectiveMode === 'diff' && $since;

        $tasks = $isDiff
            ? $plan->tasks->filter(fn (Task $task) => $task->updated_at && $task->updated_at->gt($since))
            : $plan->tasks;

        $logs = $isDiff
            ? $plan->workLogs->filter(fn (WorkLog $log) => $log->created_at && $log->created_at->gt($since))
            : $plan->workLogs;

        $adjustments = $isDiff
            ? $plan->adjustments->filter(fn (PlanAdjustment $adjustment) => $adjustment->applied_at && $adjustment->applied_at->gt($since))
            : $plan->adjustments;

        $taskLines = $this->taskContextLines($tasks);
        $logLines = $this->workLogContextLines($logs);
        $adjustmentLines = $this->adjustmentContextLines($adjustments);
        $purpose = $this->contextPurposeLabels()[$answers['purpose']] ?? $answers['purpose'];
        $question = $answers['question'] !== '' ? $answers['question'] : 'まず現在の状況を整理し、次に考えるべきことを提案してください。';
        $description = $plan->description ?: '未設定';
        $category = $plan->category ?: '未設定';
        $deadlineText = $plan->deadline?->format('Y-m-d') ?? '未設定';
        $expectedProgressText = $progress['expected_progress_percent'] === null ? '未設定' : $progress['expected_progress_percent'] . '%';
        $remainingDaysText = $progress['remaining_days'] === null ? '未設定' : $progress['remaining_days'] . '日';
        $modeText = $isDiff
            ? '既に計画全体を共有済みの同じAIチャットへ追加する、前回共有後の差分情報'
            : '新しいAIチャットへ貼る、計画全体の登録情報と最新相談';
        $sinceText = $isDiff
            ? $since->format('Y-m-d H:i')
            : '初回共有または全体再共有';
        $taskSectionLabel = $this->contextSectionLabel($isDiff, 'タスク');
        $logSectionLabel = $this->contextSectionLabel($isDiff, '作業ログ');
        $adjustmentSectionLabel = $this->contextSectionLabel($isDiff, '方針変更履歴');
        $diffNotice = $isDiff
            ? 'この差分に表示されない既存タスク・ログ・方針変更は、削除されたという意味ではなく、前回共有後に更新されていないため省略されています。一方、物理削除されたタスクや作業ログは差分だけでは表現できません。削除や大規模な再編を行った後は、差分ではなく計画全体を再共有してください。この会話に前回までの全体コンテキストがない場合は、推測で補わず、計画全体の再共有をユーザーへ依頼してください。'
            : 'この共有にはCanovia上の全体スナップショットが含まれます。ただし、登録情報が現実の最新方針に追従していない可能性があります。';
        $targetPlanJson = json_encode(
            [
                'id' => $plan->id,
                'title' => $plan->title,
                'category' => $plan->category,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return <<<PROMPT
以下は、Canoviaへ登録されている計画情報と、今回ユーザーが申告した最新の相談内容をまとめたコンテキストです。
あなたは、この計画について継続的に相談を受けるアシスタントとして振る舞ってください。

【共有形式】
{$modeText}
前回共有時点: {$sinceText}

{$diffNotice}

【情報の位置づけと優先順位】
Canoviaの登録情報は、現在のデータベース上のスナップショットです。現実の方針変更がまだ反映されておらず、概要・タスク・進捗計算が古い場合があります。
矛盾がある場合は、次の順に信頼してください。

1. 今回のユーザーからの相談と、追加質問に対する最新回答
2. 日時が新しい、適用済みの方針変更履歴
3. 最近の作業ログと、そこで確認された実際の成果・検証結果
4. Canoviaへ登録されている計画概要・タスク
5. 登録済みタスクを基準に算出された進捗率・残り時間・状態

古い会話内容と今回の情報が矛盾する場合も、同じ優先順位に従ってください。
登録情報と最新相談が矛盾するときは、登録情報を正しい前提として無理に整合させないでください。

【今回の最新相談：最優先】
相談目的: {$purpose}
ユーザーからの相談:
{$question}

【Canovia登録上の計画】
計画ID: {$plan->id}
タイトル: {$plan->title}
カテゴリ: {$category}
期間: {$plan->start_date->format('Y-m-d')} ～ {$deadlineText}
登録済み概要・方針:
{$description}

【登録タスク基準の暫定評価】
進捗率: {$progress['weighted_progress_percent']}%
期待進捗率: {$expectedProgressText}
残り日数: {$remainingDaysText}
残り作業時間: {$progress['remaining_minutes_by_progress']}分
1日必要時間: {$progress['daily_required_minutes']}分
状態: {$progress['status']}

※上記の評価は登録済みタスクを前提とした暫定値です。計画概要・完成条件・タスク構成が現状と合っていない場合、進捗率や必要時間を確定的な事実として扱わないでください。

【{$taskSectionLabel}】
{$taskLines}

【{$logSectionLabel}】
{$logLines}

【{$adjustmentSectionLabel}】
{$adjustmentLines}

【Canovia反映用の固定情報】
以下の情報は、この会話で相談内容が固まったあと、ユーザーがCanovia反映用JSONの出力を明示的に依頼した場合に使用してください。
今回の回答ではまだJSONを出力せず、自然な会話を続けてください。

schema_version: 1.1
target_plan:
{$targetPlanJson}

【将来、Canovia反映用JSONを求められた場合】
- 最上位には schema_version、action、target_plan、summary、operations を必ず含める
- schema_version は "1.1" のまま変更しない
- target_plan は上記の id・title・category を省略・変更せず、そのまま出力する
- action は、タスク追加だけなら "append_tasks"、作業実績・進捗更新なら "update_progress"、概要やタスク構成の見直しなら "restructure_plan" にする
- append_tasksでもtasks配列だけの旧形式は使わず、operations内のcreate_taskとして出力する
- restructure_planではupdate_planを含め、現在の全アクティブタスクをkeep_task・update_task・cancel_taskのいずれかで明示的に評価する
- create_taskのprogress_percentを0より大きくする場合はprogress_originとprogress_reasonを含める
- progress_originはexisting_work、inherited_task、new_workのいずれかとし、inherited_taskの場合だけsource_task_idsを含める
- JSON以外の文章やMarkdownコードフェンスを付けない

【回答方針】
- 最初に、最新相談と登録情報の間に重要な矛盾があるか確認する
- 矛盾が計画の目的・ゲームループ・完成条件・タスク構成に影響する場合は、確定案を出す前に不足情報を最大5問まで質問する
- 確認済みの事実、推測、提案を混同しない
- 最初の計画を守ることより、現在の目的に対して有効な次の行動を重視する
- タスクや概要が現状と合っていない可能性を考慮する
- 差分共有では、表示されていない項目を「存在しない」「削除済み」と解釈しない
- 登録タスクが古い場合、暫定評価を根拠に期限超過や遅れを断定しない
- 問題点を指摘する場合は、理由と影響範囲も示す
- 計画変更が必要なら、維持・変更・統合・中止すべき内容を区別して提案する
- ユーザーがCanovia反映用JSONを明示的に求めるまでは、自然な会話を続ける
PROMPT;
    }

    private function taskContextLines(Collection $tasks): string
    {
        $lines = collect($tasks->all())->map(function (Task $task) {
            return sprintf(
                '- ID:%d | %s | 状態:%s | 進捗:%d%% | 想定:%d分 | 優先度:%d | 更新:%s | 説明:%s',
                $task->id,
                $task->title,
                $task->status,
                $task->progress_percent,
                $task->estimated_minutes,
                $task->priority,
                $task->updated_at?->format('Y-m-d H:i') ?? '不明',
                $task->description ?: 'なし'
            );
        })->implode("\n");

        return $lines !== '' ? $lines : '- 該当するタスクはありません';
    }

    private function workLogContextLines(Collection $logs): string
    {
        $lines = collect($logs->all())->map(function (WorkLog $workLog) {
            return sprintf(
                '- %s | タスク:%s | %d分 | 進捗増加:%d%% | 記録:%s | メモ:%s',
                Carbon::parse($workLog->worked_on)->format('Y-m-d'),
                $workLog->task?->title ?? '計画全体',
                $workLog->actual_minutes,
                $workLog->progress_delta_percent,
                $workLog->created_at?->format('Y-m-d H:i') ?? '不明',
                $workLog->memo ?: 'なし'
            );
        })->implode("\n");

        return $lines !== '' ? $lines : '- 該当する作業ログはありません';
    }

    private function adjustmentContextLines(Collection $adjustments): string
    {
        $lines = collect($adjustments->all())->map(function (PlanAdjustment $adjustment) {
            return sprintf(
                '- %s | %s',
                $adjustment->applied_at?->format('Y-m-d H:i') ?? '日時不明',
                $adjustment->summary ?: '計画変更を適用'
            );
        })->implode("\n");

        return $lines !== '' ? $lines : '- 該当する方針変更履歴はありません';
    }

    private function policyChangeDiscoveryText(array $answers): string
    {
        return implode("\n\n", array_filter([
            '変更前: ' . ($answers['before_state'] !== '' ? $answers['before_state'] : '未入力'),
            '変更後: ' . $answers['after_state'],
            '理由: ' . $answers['reason'],
            $answers['evidence'] !== '' ? '判断材料: ' . $answers['evidence'] : null,
            $answers['affected_scope'] !== '' ? '影響範囲: ' . $answers['affected_scope'] : null,
            $answers['must_keep'] !== '' ? '維持条件: ' . $answers['must_keep'] : null,
        ]));
    }

    private function contextSectionLabel(bool $isDiff, string $label): string
    {
        return $isDiff ? '前回共有後に変化した' . $label : '現在の' . $label;
    }

    private function changeTypeLabels(): array
    {
        return [
            'abandon_direction' => '当初の方針をやめた',
            'new_idea' => '新しいアイデアが生まれた',
            'priority_change' => '優先順位が変わった',
            'scope_change' => '完成範囲・スコープが変わった',
            'deadline_change' => '期限を見直したい',
            'task_obsolete' => '既存タスクが不要になった',
            'other' => 'その他の変更',
        ];
    }

    private function contextPurposeLabels(): array
    {
        return [
            'next_action' => '次に取り組むべき作業を相談する',
            'problems' => '現在の計画の問題点を確認する',
            'deadline' => '期限内に終わるか評価する',
            'task_structure' => 'タスク構成を見直す',
            'progress' => '現在の進捗を評価する',
            'free' => '自由に相談する',
        ];
    }

    private function firstStep(string $flow): string
    {
        return match ($flow) {
            'work_log' => 'activity',
            'policy_change', 'ai_context', 'task', 'review' => 'plan',
            'plan' => 'goal',
            'status' => 'show',
            default => 'menu',
        };
    }

    private function ownedPlans(Request $request, array $with = []): Collection
    {
        return app(PlanOwnershipService::class)->ownedPlans($request, $with);
    }

    private function findOwnedPlan(Request $request, int $planId, array $with = []): Plan
    {
        $plan = Plan::with($with)->findOrFail($planId);
        app(PlanOwnershipService::class)->authorizePlan($request, $plan);

        return $plan;
    }


    private function resolveTaskStatus(int $progress, string $currentStatus, int $actualMinutes): string
    {
        if ($progress >= 100) {
            return 'done';
        }

        if ($progress > 0 || $actualMinutes > 0 || $currentStatus === 'doing') {
            return 'doing';
        }

        return 'todo';
    }
}
