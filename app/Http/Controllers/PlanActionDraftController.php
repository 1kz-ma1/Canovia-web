<?php
namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanActionDraftRevision;
use App\Models\PlanActionDraftStep;
use App\Models\WorkLog;
use App\Services\PlanActivityService;
use App\Services\PlanActionDraftEvidenceService;
use App\Services\PlanActionDraftTypedEvidenceService;
use App\Services\PlanActionDraftStepService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlanActionDraftController extends Controller
{
    public function index(Request $request, Plan $plan, PlanOwnershipService $ownership, PlanActionDraftTypedEvidenceService $typed, PlanActionDraftStepService $stepService)
    {
        $ownership->authorizePlan($request, $plan);
        $drafts = PlanActionDraft::with(['revisions', 'steps'])->where('plan_id', $plan->id)->latest('id')->limit(20)->get();
        $workLogs = $plan->workLogs()
            ->where(fn ($q) => $q->where('outcome', '!=', '')->orWhere('memo', '!=', ''))
            ->latest('id')->limit(15)->get();
        $requestId = (string) Str::uuid();
        $multiRequestId = (string) Str::uuid();
        $observedRequestId = (string) Str::uuid();
        $typedEvidence = $typed->available($plan);
        return view('plans.action_drafts', compact('plan', 'drafts', 'workLogs', 'requestId', 'multiRequestId', 'observedRequestId', 'typedEvidence', 'stepService'));
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

    public function accept(
        Request $request,
        Plan $plan,
        PlanActionDraft $draft,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        PlanActionDraftStepService $stepService,
    ) {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);

        // Older single-task submissions remain compatible. For a bundle the
        // owner must explicitly review the exact steps that will be accepted.
        $validated = $request->validate([
            'accept_mode' => ['sometimes', 'in:single,bundle'],
            'steps_fingerprint' => ['sometimes', 'string', 'size:64'],
        ]);

        DB::transaction(function () use ($plan, $draft, $request, $activity, $stepService, $validated) {
            $locked = PlanActionDraft::where('plan_id', $plan->id)
                ->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PlanActionDraft::ACCEPTED) return;
            abort_unless($locked->status === PlanActionDraft::PROPOSED, 409);

            $steps = $locked->steps()->get();
            $isBundle = $steps->isNotEmpty();
            $mode = $validated['accept_mode'] ?? 'single';
            abort_unless($mode === ($isBundle ? 'bundle' : 'single'), 409);

            if ($isBundle) {
                abort_unless($steps->count() === PlanActionDraftStepService::STEP_COUNT, 409);
                abort_unless($steps->every(fn ($step) =>
                    (int) $step->evidence_revision === (int) $locked->revision_no
                    && trim((string) $step->title) !== ''
                ), 409);
                abort_unless(isset($validated['steps_fingerprint'])
                    && hash_equals($stepService->fingerprint($steps), $validated['steps_fingerprint']), 409);
            }

            $proposedTitles = $isBundle
                ? $steps->pluck('title')->all()
                : [$locked->suggested_next_action];
            $previousTask = null;
            $firstTask = null;
            $baseOrder = (int) $plan->tasks()->max('sort_order');

            foreach ($proposedTitles as $position => $title) {
                $task = $plan->tasks()->create([
                    'title' => $title,
                    'description' => '本人が行動記録を確認し、計画案として承認した新しいTaskです。',
                    'estimated_minutes' => 0, 'remaining_minutes' => 0,
                    'progress_percent' => 0, 'status' => 'todo',
                    'priority' => 3, 'activation_cost' => 3, 'sort_order' => $baseOrder + $position + 1,
                    'depends_on_task_id' => $previousTask?->id,
                ]);

                if ($previousTask) {
                    // One canonical same-Plan dependency per sequential step.
                    $task->prerequisites()->attach($previousTask->id);
                }

                if ($isBundle) {
                    $steps[$position]->update(['accepted_task_id' => $task->id]);
                }

                $activity->record($plan, $request->user(), 'task_created',
                    'task', (int) $task->id, ['task_title' => $task->title, 'action_draft_id' => $locked->id]);

                $firstTask ??= $task;
                $previousTask = $task;
            }

            $locked->update([
                'status' => PlanActionDraft::ACCEPTED,
                'accepted_task_id' => $firstTask->id,
                'accepted_at' => now(),
            ]);
        });

        return redirect()->route('plans.action_drafts.index', $plan);
    }


    /**
     * Opt-in generation of a 3-step proposal. No Task is touched.
     * An existing same-revision bundle is kept unless the owner explicitly
     * requests rebuilding (which discards their edited step titles).
     */
    public function prepareSteps(
        Request $request,
        Plan $plan,
        PlanActionDraft $draft,
        PlanOwnershipService $ownership,
        PlanActionDraftStepService $stepService,
    ) {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        $validated = $request->validate(['rebuild' => ['sometimes', 'boolean']]);

        DB::transaction(function () use ($plan, $draft, $validated, $stepService) {
            $locked = PlanActionDraft::where('plan_id', $plan->id)
                ->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === PlanActionDraft::PROPOSED, 409);
            $existing = $locked->steps()->get();

            if ($existing->isNotEmpty() && ! ($validated['rebuild'] ?? false)) {
                // Stale bundles are never silently discarded.
                abort_unless($existing->every(fn ($step) =>
                    (int) $step->evidence_revision === (int) $locked->revision_no), 409);
                return;
            }

            PlanActionDraftStep::where('plan_action_draft_id', $locked->id)->delete();
            foreach ($stepService->suggest($plan, $locked) as $index => $title) {
                $locked->steps()->create([
                    'sort_order' => $index + 1,
                    'evidence_revision' => (int) $locked->revision_no,
                    'title' => $title,
                ]);
            }
        });

        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '3段階の未承認ステップ案を用意しました。タイトルを確認・編集してから承認してください。');
    }

    /** Update all titles as one revision-bound form, never canonical Tasks. */
    public function updateSteps(
        Request $request,
        Plan $plan,
        PlanActionDraft $draft,
        PlanOwnershipService $ownership,
    ) {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        $validated = $request->validate([
            'evidence_revision' => ['required', 'integer', 'min:1'],
            'step_titles' => ['required', 'array', 'size:3'],
            'step_titles.*' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($plan, $draft, $validated) {
            $locked = PlanActionDraft::where('plan_id', $plan->id)
                ->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === PlanActionDraft::PROPOSED, 409);
            abort_unless((int) $locked->revision_no === (int) $validated['evidence_revision'], 409);

            $steps = $locked->steps()->get();
            abort_unless($steps->count() === PlanActionDraftStepService::STEP_COUNT, 409);
            abort_unless($steps->every(fn ($step) =>
                (int) $step->evidence_revision === (int) $locked->revision_no), 409);

            $actual = $steps->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $provided = array_map('intval', array_keys($validated['step_titles']));
            sort($provided);
            abort_unless($provided === $actual, 409);

            foreach ($steps as $step) {
                $title = trim((string) $validated['step_titles'][$step->id]);
                if ($title === '') {
                    throw ValidationException::withMessages(['step_titles' => '各ステップの作業名が必要です。']);
                }
                $step->update(['title' => $title]);
            }
        });

        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '3段階のステップ案を更新しました。まだTaskには追加していません。');
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


    /**
     * Combine only explicitly selected, same-Plan WorkLogs. Existing one-source
     * creation remains unchanged for compatibility with V58.71 clients.
     */
    public function compose(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanActionDraftEvidenceService $evidence,
    ) {
        $ownership->authorizePlan($request, $plan);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'work_log_ids' => ['required', 'array', 'min:2', 'max:5'],
            'work_log_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        // Retried POST must not create a second row or rewrite prior selections.
        if (PlanActionDraft::where('plan_id', $plan->id)
            ->where('request_id', $validated['request_id'])->exists()) {
            return redirect()->route('plans.action_drafts.index', $plan);
        }

        $sources = $evidence->snapshotsForLogs($plan, $validated['work_log_ids']);
        $latest = $sources[count($sources) - 1];
        PlanActionDraft::firstOrCreate([
            'plan_id' => $plan->id,
            'request_id' => $validated['request_id'],
        ], [
            'source_work_log_id' => $latest['work_log_id'],
            'source_kind' => 'multi_work_log',
            'completed_action' => $latest['action'],
            'observed_outcome' => $latest['outcome'],
            'suggested_next_action' => $evidence->suggest($sources),
            'status' => PlanActionDraft::PROPOSED,
            'evidence_snapshots' => $sources,
        ]);

        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '複数の実績に基づく提案を作りました。まだTaskは変わりません。');
    }


    /**
     * One or more owner-selected typed observations, optionally paired with
     * WorkLogs. No new Task and no background inference are triggered.
     */
    public function composeObserved(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanActionDraftEvidenceService $evidence,
        PlanActionDraftTypedEvidenceService $typed,
    ) {
        $ownership->authorizePlan($request, $plan);
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'task_evidence_ids' => ['required', 'array', 'min:1', 'max:5'],
            'task_evidence_ids.*' => ['required', 'integer', 'distinct'],
            'work_log_ids' => ['sometimes', 'array', 'max:4'],
            'work_log_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        if (PlanActionDraft::where('plan_id', $plan->id)
            ->where('request_id', $validated['request_id'])->exists()) {
            return redirect()->route('plans.action_drafts.index', $plan);
        }

        $typedSources = $typed->snapshots($plan, $validated['task_evidence_ids']);
        $logs = empty($validated['work_log_ids'])
            ? [] : $evidence->snapshotsForLogs($plan, $validated['work_log_ids']);
        $sources = array_merge($logs, $typedSources);
        if (count($sources) > PlanActionDraftEvidenceService::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'task_evidence_ids' => '根拠として選べる記録は合計5件までです。',
            ]);
        }

        $latest = $sources[count($sources) - 1];
        PlanActionDraft::firstOrCreate(
            ['plan_id' => $plan->id, 'request_id' => $validated['request_id']],
            [
                'source_kind' => $evidence->kindForSources($sources),
                'source_work_log_id' => $latest['kind'] === 'work_log' ? $latest['work_log_id'] : null,
                'completed_action' => $latest['action'],
                'observed_outcome' => $latest['outcome'],
                'suggested_next_action' => $typed->suggestion($plan, $sources),
                'evidence_snapshots' => $sources,
                'status' => PlanActionDraft::PROPOSED,
            ],
        );

        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '選んだEvidenceに基づく行動案を作りました。承認するまでTaskは変わりません。');
    }

    /**
     * Pure preview. No draft, Task, or evidence write occurs here.
     */
    public function previewRefresh(
        Request $request,
        Plan $plan,
        PlanActionDraft $draft,
        PlanOwnershipService $ownership,
        PlanActionDraftEvidenceService $evidence,
        PlanActionDraftTypedEvidenceService $typed,
    ) {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        abort_unless($draft->status === PlanActionDraft::PROPOSED, 409);

        $validated = $this->validateRefreshInputs($request);
        $added = $this->selectedAdditionalSources($plan, $validated, $evidence, $typed);
        $combined = $evidence->appendNew($evidence->existing($draft), $added);
        $afterAction = $typed->suggestion($plan, $combined);
        $sourceFingerprint = $evidence->fingerprint($combined);
        $candidateFingerprint = $evidence->candidateFingerprint($draft);

        return view('plans.action_draft_refresh_preview', compact(
            'plan', 'draft', 'added', 'combined', 'afterAction',
            'sourceFingerprint', 'candidateFingerprint',
        ));
    }

    /**
     * Explicitly apply the exact previewed evidence selection and base version.
     * A stale preview fails with 409 rather than silently replacing a user edit.
     */
    public function applyRefresh(
        Request $request,
        Plan $plan,
        PlanActionDraft $draft,
        PlanOwnershipService $ownership,
        PlanActionDraftEvidenceService $evidence,
        PlanActionDraftTypedEvidenceService $typed,
    ) {
        $ownership->authorizePlan($request, $plan);
        $this->withinPlan($plan, $draft);
        $validated = $this->validateRefreshInputs($request);
        $meta = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'size:64'],
            'candidate_fingerprint' => ['required', 'string', 'size:64'],
        ]);

        DB::transaction(function () use ($plan, $draft, $validated, $meta, $evidence, $typed) {
            $locked = PlanActionDraft::where('plan_id', $plan->id)
                ->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === PlanActionDraft::PROPOSED, 409);
            abort_unless((int) $locked->revision_no === (int) $meta['expected_revision'], 409);
            abort_unless(hash_equals($evidence->candidateFingerprint($locked), $meta['candidate_fingerprint']), 409);

            $additional = $this->selectedAdditionalSources($plan, $validated, $evidence, $typed);
            $combined = $evidence->appendNew($evidence->existing($locked), $additional);
            abort_unless(hash_equals($evidence->fingerprint($combined), $meta['source_fingerprint']), 409);

            $before = $locked->suggested_next_action;
            $after = $typed->suggestion($plan, $combined);
            $beforeRevision = (int) $locked->revision_no;

            $locked->update([
                'evidence_snapshots' => $combined,
                'source_kind' => $evidence->kindForSources($combined),
                'suggested_next_action' => $after,
                'revision_no' => $beforeRevision + 1,
            ]);

            PlanActionDraftRevision::create([
                'plan_action_draft_id' => $locked->id,
                'from_revision' => $beforeRevision,
                'to_revision' => $beforeRevision + 1,
                'before_action' => $before,
                'after_action' => $after,
                'added_evidence_snapshots' => $additional,
                'created_at' => now(),
            ]);
        });

        return redirect()->route('plans.action_drafts.index', $plan)
            ->with('success', '確認した差分を提案に反映しました。Taskは変更していません。');
    }

    /** @return array<string,mixed> */
    private function validateRefreshInputs(Request $request): array
    {
        $validated = $request->validate([
            'additional_work_log_ids' => ['sometimes', 'array', 'max:5'],
            'additional_work_log_ids.*' => ['required', 'integer', 'distinct'],
            'additional_task_evidence_ids' => ['sometimes', 'array', 'max:5'],
            'additional_task_evidence_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $count = count($validated['additional_work_log_ids'] ?? [])
            + count($validated['additional_task_evidence_ids'] ?? []);
        if ($count < 1 || $count > PlanActionDraftEvidenceService::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'additional_work_log_ids' => '追加する根拠を合計1〜5件選んでください。',
            ]);
        }
        return $validated;
    }

    /** @return list<array<string,mixed>> */
    private function selectedAdditionalSources(
        Plan $plan, array $validated, PlanActionDraftEvidenceService $evidence,
        PlanActionDraftTypedEvidenceService $typed,
    ): array {
        $logs = empty($validated['additional_work_log_ids'])
            ? [] : $evidence->snapshotsForLogs($plan, $validated['additional_work_log_ids']);
        $observations = empty($validated['additional_task_evidence_ids'])
            ? [] : $typed->snapshots($plan, $validated['additional_task_evidence_ids']);
        return array_merge($logs, $observations);
    }

    private function withinPlan(Plan $plan, PlanActionDraft $draft): void
    {
        abort_unless((int) $plan->id === (int) $draft->plan_id, 404);
    }
}
