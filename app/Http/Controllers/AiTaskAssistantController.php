<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanAdjustment;
use App\Models\Task;
use App\Services\AiJsonInputNormalizer;
use App\Services\PlanGenerationJsonCompatibilityService;
use App\Services\PlanProgressService;
use App\Services\PlanOwnershipService;
use App\Services\FutureMemoService;
use App\Services\BehaviorIdentityService;
use App\Services\GoalContextService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiTaskAssistantController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        FutureMemoService $futureMemoService,
        BehaviorIdentityService $identity,
        GoalContextService $goalContexts,
    ) {
        $this->authorizePlanOwner($plan);

        $plan->load(['tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        $title = json_encode($plan->title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $category = json_encode($plan->category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $deadline = $plan->deadline?->format('Y-m-d') ?? '未設定';
        $futureMemos = $futureMemoService->all($request, true);
        $futureMemoContext = $futureMemoService->promptContext($request);
        $goalContext = $goalContexts->ensureForPlan(
            $plan,
            $request->user()?->id,
            $request->user() ? null : $identity->resolve($request),
        );
        $goalContextSnapshot = $goalContexts->snapshot($goalContext);
        $goalContextPrompt = $goalContexts->promptContext($goalContext);

        $prompt = <<<PROMPT
あなたはCanoviaの計画生成アシスタントです。目標を実行可能なタスクへ分解してください。

最重要ルール:
- GOAL CONTEXTのCONFIRMED FACTSは確認済みなので、同じ質問を繰り返さない
- UNCONFIRMED HINTSは事実として断定しない
- KNOWN UNKNOWNSや重要な不足情報を推測で埋めない
- 追加確認が必要なら、一度に質問を並べず「計画への影響が最も大きい質問」を1つずつ優先する
- 現在地が不足したままでも、仮の進め方を示すことはできる
- 現在地を知らないと次の方針を決められない場合は、無理に長期Taskを作らず「測る・観察する・記録する」Measurement Taskを初期Taskに含める
- 時間を使った事実そのものをProgressの証拠にしない

{$goalContextPrompt}

対象計画:
- ID: {$plan->id}
- タイトル: {$plan->title}
- 概要: {$plan->description}
- 期間: {$plan->start_date->format('Y-m-d')} ～ {$deadline}

【CANOVIA MEMORY：本人が明示した将来意図・関心・価値】
{$futureMemoContext}

Memoryは本人が明示した内容だけを保持した参考Contextです。目標や優先順位を勝手に決めつけず、今回の計画と関係する内容だけを使ってください。

Goal ContextのReadinessがLOW/MEDIUMでも、確認できている範囲から仮Planを作れます。重要なUnknownが残る場合は、AIの想像で長期計画を埋めるのではなく、そのUnknownを解消するMeasurement Taskを優先してください。

最終回答は説明やMarkdownを付けず、次のJSON 2.0だけにしてください。
{
  "schema_version": "2.0",
  "flow": "plan_generation",
  "target_plan": {"id": {$plan->id}, "title": {$title}, "category": {$category}},
  "summary": "生成した計画の要約",
  "operations": [
    {
      "type": "update_plan",
      "deadline": "YYYY-MM-DD"
    },
    {
      "type": "add_task",
      "client_ref": "task_1",
      "title": "具体的なタスク名",
      "description": "完了を判定できる条件",
      "estimated_minutes": 120,
      "remaining_minutes": 120,
      "progress_percent": 0,
      "progress_reason": "未着手",
      "status": "todo",
      "priority": 1,
      "activation_cost": 2
    },
    {
      "type": "reorder_tasks",
      "items": [{"task_ref": "task_1"}],
      "reason": "依存関係と優先順位に基づく実行順"
    }
  ]
}

期限が未設定でも、それだけを理由に計画生成を止めないでください。期限が計画構造を大きく変える場合だけ優先質問にできます。会話で期限が決まった場合だけupdate_planを含め、まだ決めない場合はupdate_planを省略してください。
進捗率は最新の完成条件に対する絶対値、remaining_minutesは今後実際に必要な時間として別々に判断してください。
priorityは必ず1～5で、1が最優先、5が低優先です。6以上を実行順の番号として使わないでください。実行順はreorder_tasksのitemsで表現してください。
activation_costは1～5で、難易度ではなく「そのTaskを始めるまでの心理的・準備的な重さ」を推定してください。1はすぐ始められ、5はかなり準備や集中が必要です。

【出力直前チェック（必須）】
1. schema_versionが"2.0"か
2. flowが"plan_generation"か
3. target_plan.idが{$plan->id}、titleが「{$plan->title}」のままか
4. operationsが1～60件の配列か
5. add_taskのclient_refが重複していないか
6. priorityとactivation_costがすべて1～5か
7. reorder_tasksを出す場合、全add_taskのclient_refを重複なく1回ずつ含んでいるか
8. 説明文・Markdown・コードフェンス・コメント・末尾カンマを付けず、有効なJSONだけを返しているか
PROMPT;

        return view('plans.ai_task_assistant', compact(
            'plan',
            'prompt',
            'futureMemos',
            'goalContext',
            'goalContextSnapshot',
        ));
    }

    public function import(Request $request, Plan $plan, PlanProgressService $progressService)
    {
        $this->authorizePlanOwner($plan);

        $validated = $request->validate(['tasks_json' => ['required', 'string', 'max:100000']]);

        try {
            $json = app(AiJsonInputNormalizer::class)->normalize($validated['tasks_json']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'tasks_json' => $exception->getMessage(),
            ]);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([
                'tasks_json' => 'AIの回答から計画データを読み取れませんでした。下の修正依頼をAIへ送り、返ってきたJSONを貼り直してください。',
            ]);
        }

        $incomingTarget = $decoded['target_plan'] ?? null;
        $incomingPlanId = is_array($incomingTarget) ? ($incomingTarget['id'] ?? null) : null;
        if ($incomingPlanId !== null && $incomingPlanId !== '' && (int) $incomingPlanId !== (int) $plan->id) {
            throw ValidationException::withMessages([
                'tasks_json' => '別の計画ID向けの回答です。現在の計画IDは '.$plan->id.' です。下の修正依頼をAIへ送り、target_plan.idだけでなく内容全体がこの計画向けか確認してください。',
            ]);
        }

        $compatibility = app(PlanGenerationJsonCompatibilityService::class)->adapt($plan, $decoded);
        $decoded = $compatibility['decoded'];
        $normalizationNotes = $compatibility['notes'];
        $json = json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        $requestHash = hash('sha256', 'plan_generation|'.$plan->id.'|'.$json);

        // A successful import can be retried by Safari/PWA when the redirect is
        // not rendered. Treat the normalized JSON as the idempotency key so the
        // same plan payload never creates a second copy of every task.
        if (PlanAdjustment::query()
            ->where('plan_id', $plan->id)
            ->where('request_hash', $requestHash)
            ->exists()) {
            return redirect()->route('plans.show', $plan)
                ->with('success', 'この初期計画はすでに反映済みです。重複登録せず、既存の計画を開きました。');
        }

        $operations = $decoded['operations'] ?? null;

        if (! is_array($operations) || $operations === [] || count($operations) > 60) {
            throw ValidationException::withMessages(['tasks_json' => 'operationsは1～60件の配列にしてください。']);
        }

        $taskOperations = collect($operations)->filter(fn ($operation) => ($operation['type'] ?? null) === 'add_task')->values();
        $unsupported = collect($operations)->reject(fn ($operation) => in_array($operation['type'] ?? null, ['update_plan', 'add_task', 'reorder_tasks'], true));

        if ($taskOperations->isEmpty() || $unsupported->isNotEmpty()) {
            throw ValidationException::withMessages(['tasks_json' => '計画生成ではupdate_plan、add_task、reorder_tasksだけを使用できます。']);
        }

        $normalizedTasks = $taskOperations->map(function ($operation, $index) {
            $title = trim((string) ($operation['title'] ?? ''));
            $estimated = filter_var($operation['estimated_minutes'] ?? null, FILTER_VALIDATE_INT);
            $remaining = filter_var($operation['remaining_minutes'] ?? null, FILTER_VALIDATE_INT);
            $progress = filter_var($operation['progress_percent'] ?? 0, FILTER_VALIDATE_INT);
            $priority = filter_var($operation['priority'] ?? 3, FILTER_VALIDATE_INT);
            $activationCost = filter_var($operation['activation_cost'] ?? 3, FILTER_VALIDATE_INT);
            $status = $operation['status'] ?? 'todo';

            if ($title === '' || mb_strlen($title) > 255 || $estimated === false || $estimated < 0
                || $remaining === false || $remaining < 0 || $progress === false || $progress < 0 || $progress > 100
                || $priority === false || $priority < 1 || $priority > 5
                || $activationCost === false || $activationCost < 1 || $activationCost > 5
                || ! in_array($status, ['todo', 'doing', 'done'], true)) {
                throw ValidationException::withMessages(['tasks_json' => ($index + 1) . '件目のタスク内容が正しくありません。']);
            }

            return [
                'client_ref' => trim((string) ($operation['client_ref'] ?? '')),
                'title' => $title,
                'description' => trim((string) ($operation['description'] ?? '')),
                'estimated_minutes' => $estimated,
                'remaining_minutes' => $status === 'done' ? 0 : $remaining,
                'progress_percent' => $status === 'done' ? 100 : $progress,
                'progress_reason' => trim((string) ($operation['progress_reason'] ?? '')),
                'status' => $status,
                'priority' => $priority,
                'activation_cost' => $activationCost,
            ];
        });

        $refs = $normalizedTasks->pluck('client_ref')->filter();

        if ($refs->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['tasks_json' => 'add_taskのclient_refが重複しています。']);
        }

        $planUpdate = collect($operations)->firstWhere('type', 'update_plan');
        $normalizedDeadline = null;

        if ($planUpdate !== null) {
            $deadlineValue = trim((string) ($planUpdate['deadline'] ?? ''));
            if ($deadlineValue === '') {
                throw ValidationException::withMessages(['tasks_json' => 'update_planを使う場合はdeadlineを指定してください。']);
            }

            try {
                $normalizedDeadline = Carbon::parse($deadlineValue)->format('Y-m-d');
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['tasks_json' => 'update_planのdeadlineはYYYY-MM-DD形式にしてください。']);
            }

            if (Carbon::parse($normalizedDeadline)->lt($plan->start_date->copy()->startOfDay())) {
                throw ValidationException::withMessages(['tasks_json' => '期限は開始日以降にしてください。']);
            }
        }

        $reorder = collect($operations)->firstWhere('type', 'reorder_tasks');

        if ($reorder !== null) {
            $orderRefs = collect($reorder['items'] ?? [])->map(fn ($item) => is_array($item) ? ($item['task_ref'] ?? null) : null);

            if ($orderRefs->filter()->count() !== $normalizedTasks->count()
                || $orderRefs->unique()->count() !== $normalizedTasks->count()
                || $orderRefs->diff($refs)->isNotEmpty()
                || $refs->diff($orderRefs)->isNotEmpty()) {
                throw ValidationException::withMessages(['tasks_json' => 'reorder_tasksには、生成する全タスクのclient_refを重複なく指定してください。']);
            }

            $byRef = $normalizedTasks->keyBy('client_ref');
            $normalizedTasks = $orderRefs->map(fn ($ref) => $byRef->get($ref))->values();
        }

        $appliedNow = DB::transaction(function () use ($plan, $decoded, $json, $normalizedTasks, $normalizedDeadline, $progressService, $normalizationNotes, $requestHash) {
            // Serialize imports for the same Plan. This closes the race where two
            // identical POSTs arrive before either request has written its
            // PlanAdjustment row.
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (PlanAdjustment::query()
                ->where('plan_id', $plan->id)
                ->where('request_hash', $requestHash)
                ->exists()) {
                return false;
            }

            $plan->unsetRelation('tasks');
            $plan->unsetRelation('workLogs');
            $metricsBefore = $progressService->calculate($plan);
            $applied = [];

            if ($normalizedDeadline !== null) {
                $plan->update(['deadline' => $normalizedDeadline]);
                $applied[] = ['type' => 'update_plan', 'deadline' => $normalizedDeadline];
            }

            $sortOrder = (int) $plan->tasks()->max('sort_order');
            foreach ($normalizedTasks as $taskData) {
                $task = Task::create(array_merge($taskData, [
                    'plan_id' => $plan->id,
                    'sort_order' => ++$sortOrder,
                ]));
                $applied[] = array_merge(['type' => 'create_task', 'created_task_id' => $task->id], $taskData);
            }

            $plan->unsetRelation('tasks');
            $plan->unsetRelation('workLogs');

            PlanAdjustment::create([
                'plan_id' => $plan->id,
                'flow' => 'plan_generation',
                'request_hash' => $requestHash,
                'summary' => trim((string) ($decoded['summary'] ?? 'AIが初期計画を生成')),
                'user_input' => [
                    'normalization_notes' => $normalizationNotes,
                ],
                'prompt' => 'AI計画生成画面から読み込み',
                'response_json' => $json,
                'applied_operations' => $applied,
                'metrics_before' => $metricsBefore,
                'metrics_after' => $progressService->calculate($plan),
                'applied_at' => now(),
            ]);

            return true;
        });

        if (! $appliedNow) {
            return redirect()->route('plans.show', $plan)
                ->with('success', 'この初期計画はすでに反映済みです。重複登録せず、既存の計画を開きました。');
        }

        $message = 'AIが生成した初期タスクを登録しました。';
        if ($normalizationNotes !== []) {
            $message .= ' 形式の違いはCanovia側で'.count($normalizationNotes).'件調整しました。';
        }

        // Development-first onboarding should return to its State-first
        // workspace once the first tasks have actually been persisted.
        // Other categories keep the established Plan detail destination.
        if ($request->boolean('return_to_workspace')
            && app(\App\Services\PlanCategoryProfileService::class)->forPlan($plan)->key === 'development') {
            return redirect()
                ->route('workspace.development.index', ['plan_id' => $plan->id])
                ->with('success', $message);
        }

        return redirect()->route('plans.show', $plan)->with('success', $message);
    }

    private function authorizePlanOwner(Plan $plan): void
    {
        app(PlanOwnershipService::class)->authorizePlan(request(), $plan);
    }
}
