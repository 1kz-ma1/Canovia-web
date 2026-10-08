<?php
namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\WorkLog;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlanActionDraftController extends Controller
{
    public function index(Request $request, Plan $plan, PlanOwnershipService $ownership)
    {
        $ownership->authorizePlan($request, $plan);
        $drafts = PlanActionDraft::where('plan_id', $plan->id)->latest('id')->limit(20)->get();
        $workLogs = $plan->workLogs()
            ->where(fn ($q) => $q->where('outcome', '!=', '')->orWhere('memo', '!=', ''))
            ->latest('id')->limit(15)->get();
        $requestId = (string) Str::uuid();
        return view('plans.action_drafts', compact('plan', 'drafts', 'workLogs', 'requestId'));
    }

    public function store(Request $request, Plan $plan, PlanOwnershipService $ownership)
    {
        $ownership->authorizePlan($request, $plan);
        $input = $request->validate([
            'request_id' => ['required', 'uuid'],
            'source_work_log_id' => ['nullable', 'integer'],
            'completed_action' => ['required_without:source_work_log_id', 'nullable', 'string', 'max:255'],
            'observed_outcome' => ['required_without:source_work_log_id', 'nullable', 'string', 'max:2000'],
        ]);
        $existing = PlanActionDraft::where('plan_id', $plan->id)->where('request_id', $input['request_id'])->first();
        if ($existing) return redirect()->route('plans.action_drafts.index', $plan);

        $kind = 'self_report';
        $sourceId = null;
        $action = trim((string) ($input['completed_action'] ?? ''));
        $outcome = trim((string) ($input['observed_outcome'] ?? ''));
        if (! empty($input['source_work_log_id'])) {
            $log = WorkLog::where('plan_id', $plan->id)->findOrFail($input['source_work_log_id']);
            $kind = 'work_log';
            $sourceId = $log->id;
            $action = trim((string) ($log->task_title_snapshot ?: $log->task?->title));
            $outcome = trim((string) ($log->outcome ?: $log->memo));
        }
        if ($action === '' || $outcome === '') {
            throw ValidationException::withMessages([
                'observed_outcome' => '実際に行ったことと結果の両方が必要です。',
            ]);
        }
        // V58.71 is a transparent suggestion, NOT inferred mastery or an AI-authored plan.
        $candidate = '「'.mb_substr($action, 0, 115).'」の結果を確認し、次に試す作業を1つ決める';
        PlanActionDraft::firstOrCreate(
            ['plan_id' => $plan->id, 'request_id' => $input['request_id']],
            ['source_work_log_id' => $sourceId, 'source_kind' => $kind,
             'completed_action' => mb_substr($action, 0, 255),
             'observed_outcome' => $outcome, 'suggested_next_action' => $candidate,
             'status' => PlanActionDraft::PROPOSED],
        );
        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '行動案を作成しました。承認するまでTaskは変更されません。');
    }

    public function update(Request $request, Plan $plan, PlanActionDraft $draft, PlanOwnershipService $ownership)
    {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        abort_unless($draft->status === PlanActionDraft::PROPOSED, 409);
        $validated = $request->validate(['suggested_next_action' => ['required', 'string', 'max:255']]);
        $draft->update(['suggested_next_action' => trim($validated['suggested_next_action'])]);
        return redirect()->route('plans.action_drafts.index', $plan);
    }

    public function accept(Request $request, Plan $plan, PlanActionDraft $draft, PlanOwnershipService $ownership, PlanActivityService $activity)
    {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        $task = DB::transaction(function () use ($plan, $draft) {
            $locked = PlanActionDraft::where('plan_id', $plan->id)->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PlanActionDraft::ACCEPTED) return null;
            abort_unless($locked->status === PlanActionDraft::PROPOSED, 409);
            $task = $plan->tasks()->create([
                'title' => $locked->suggested_next_action,
                'description' => '本人が行動記録を確認し、計画案として承認した新しいTaskです。',
                'estimated_minutes' => 0, 'remaining_minutes' => 0,
                'progress_percent' => 0, 'status' => 'todo',
                'priority' => 3, 'activation_cost' => 3, 'sort_order' => 0,
            ]);
            $locked->update(['status' => PlanActionDraft::ACCEPTED,
                'accepted_task_id' => $task->id, 'accepted_at' => now()]);
            return $task;
        });
        if ($task) $activity->record($plan, $request->user(), 'task_created', 'task', (int) $task->id, ['task_title' => $task->title]);
        return redirect()->route('plans.action_drafts.index', $plan);
    }

    public function dismiss(Request $request, Plan $plan, PlanActionDraft $draft, PlanOwnershipService $ownership)
    {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        abort_if($draft->status === PlanActionDraft::ACCEPTED, 409);
        if ($draft->status === PlanActionDraft::PROPOSED) {
            $draft->update(['status' => PlanActionDraft::DISMISSED]);
        }
        return redirect()->route('plans.action_drafts.index', $plan);
    }

    private function withinPlan(Plan $plan, PlanActionDraft $draft): void
    {
        abort_unless((int) $plan->id === (int) $draft->plan_id, 404);
    }
}
