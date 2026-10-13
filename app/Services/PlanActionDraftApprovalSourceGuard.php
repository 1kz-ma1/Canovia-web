<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Intelligence\Support\CanonicalJson;
use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\WorkLog;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * G3/A10 last-mile guard for owner-approved Task creation from V58 Drafts.
 *
 * Called INSIDE the same transaction as the Task writes, after locking the
 * Plan and Draft. Always lock Task and GitHub repository root BEFORE WorkLog
 * and TaskEvidence, matching native/github producer order. Tentative reads
 * only determine the lock set; revalidate their exact bindings under locks.
 */
final class PlanActionDraftApprovalSourceGuard
{
    public function __construct(
        private readonly PlanActionDraftEvidenceService $workLogs,
        private readonly PlanActionDraftTypedEvidenceService $typed,
        private readonly GitHubTaskEvidenceReuseGuard $reuseGuard,
    ) {}

    public function assertCurrent(Plan $plan, PlanActionDraft $draft): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Action Draft source approval requires a transaction.');
        }

        $sources = $this->workLogs->existing($draft);
        if ($sources === [] || count($sources) > PlanActionDraftEvidenceService::MAX_SOURCES) {
            abort(409, '根拠の記録を再確認してから承認してください。');
        }

        $logIds = [];
        $evidenceIds = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                abort(409, '根拠の記録が変更されています。');
            }

            switch ($source['kind'] ?? null) {
                case 'self_report':
                    // This is the owner's own recorded assertion, not an
                    // external or mutable source reference.
                    if (trim((string) ($source['action'] ?? '')) === ''
                        || trim((string) ($source['outcome'] ?? '')) === '') {
                        abort(409, '記録の内容を再確認してください。');
                    }
                    break;
                case 'work_log':
                    $logIds[] = (int) ($source['work_log_id'] ?? 0);
                    break;
                case 'task_evidence':
                    $evidenceIds[] = (int) ($source['task_evidence_id'] ?? 0);
                    break;
                default:
                    abort(409, '未対応の根拠のため承認できません。');
            }
        }

        if (in_array(0, $logIds, true) || in_array(0, $evidenceIds, true)
            || count(array_unique($logIds)) !== count($logIds)
            || count(array_unique($evidenceIds)) !== count($evidenceIds)) {
            abort(409, '根拠の識別情報を再確認してください。');
        }

        // Initial reads are NOT authority. They only collect the Task/root
        // locks needed in canonical Plan -> Draft -> Task -> PlanArtifact ->
        // WorkLog -> TaskEvidence order, shared with GitHubTaskEvidenceCommitGuard.
        // Every binding is checked again after all related rows are locked.
        $preLogs = WorkLog::query()->where('plan_id', $plan->id)
            ->whereIn('id', $logIds)->get();
        $preRecords = TaskEvidence::query()->where('plan_id', $plan->id)
            ->whereIn('id', $evidenceIds)->get();
        if ($preLogs->count() !== count($logIds)
            || $preRecords->count() !== count($evidenceIds)) {
            abort(409, '根拠の記録が削除または移動されました。');
        }

        $taskIds = $preLogs->pluck('task_id')
            ->concat($preRecords->pluck('task_id'))
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($taskIds !== []) {
            $validTasks = Task::query()->where('plan_id', $plan->id)
                ->whereIn('id', $taskIds)->orderBy('id')
                ->lockForUpdate()->get()->count();
            if ($validTasks !== count($taskIds)) {
                abort(409, '根拠に関連するTaskの所属が変更されました。');
            }
        }

        $rootIds = $preRecords
            ->filter(fn (TaskEvidence $row) => $row->source === EvidenceSource::GitHub)
            ->map(fn (TaskEvidence $row) => (int) data_get(
                $row->metadata, 'github_repository_artifact_id', 0,
            ))
            ->filter(fn ($id) => $id > 0)
            ->unique()->sort()->values()->all();

        if ($rootIds !== []) {
            $roots = PlanArtifact::query()->where('plan_id', $plan->id)
                ->whereIn('id', $rootIds)->orderBy('id')
                ->lockForUpdate()->get();
            if ($roots->count() !== count($rootIds)) {
                abort(409, 'GitHubの接続根拠が削除または移動されました。');
            }
        }

        $logs = WorkLog::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $logIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $records = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $evidenceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($logs->count() !== count($logIds)
            || $records->count() !== count($evidenceIds)) {
            abort(409, '根拠の記録が削除または移動されました。');
        }

        // Source retargeting while acquiring parent locks must never result
        // in confirming a new, unprotected Task or root.
        $preLogBindings = $preLogs->keyBy('id');
        $preEvidenceBindings = $preRecords->keyBy('id');
        foreach ($logs as $row) {
            $before = $preLogBindings->get($row->id);
            if (! $before || $before->task_id !== $row->task_id) {
                abort(409, '根拠に関連するTaskが変更されました。');
            }
        }
        foreach ($records as $row) {
            $before = $preEvidenceBindings->get($row->id);
            if (! $before || $before->task_id !== $row->task_id
                || $before->source !== $row->source
                || (int) data_get($before->metadata, 'github_repository_artifact_id', 0)
                    !== (int) data_get($row->metadata, 'github_repository_artifact_id', 0)) {
                abort(409, '根拠の参照先が変更されました。新しい案を確認してください。');
            }
        }

        // Approval happens after composition, potentially following an App
        // disconnect/reconnect. Lock the root through the final Task insert;
        // no other transaction can revoke/reconnect it after this check.
        if ($records->contains(fn (TaskEvidence $row) =>
            ! $this->reuseGuard->eligible($plan, $row)
        )) {
            abort(409, 'GitHub由来の根拠を現在確認できません。再同期後に新しい案を作成してください。');
        }

        // The original stored observations are compared to fresh projections.
        // For new records also compare hashes over the complete source
        // revision: an edit to private metadata must not silently pass just
        // because the brief display summary remained unchanged.
        $freshLogs = $logIds === [] ? [] :
            $this->workLogs->snapshotsForLogs($plan, $logIds);
        $freshTyped = $evidenceIds === [] ? [] :
            $this->typed->snapshots($plan, $evidenceIds);

        $fresh = [];
        foreach (array_merge($freshLogs, $freshTyped) as $source) {
            $key = ($source['kind'] ?? '').':'.(
                $source['work_log_id'] ?? $source['task_evidence_id'] ?? 0
            );
            $fresh[$key] = $source;
        }

        foreach ($sources as $original) {
            if (($original['kind'] ?? null) === 'self_report') {
                continue;
            }

            $key = ($original['kind'] ?? '').':'.(
                $original['work_log_id'] ?? $original['task_evidence_id'] ?? 0
            );
            $current = $fresh[$key] ?? null;
            if ($current === null) {
                abort(409, '根拠の記録が利用できません。');
            }

            $signature = $original['source_signature'] ?? null;
            if ($signature !== null && (! is_string($signature)
                || ! preg_match('/^[a-f0-9]{64}$/', $signature)
                || ! hash_equals($signature, (string) $current['source_signature']))) {
                abort(409, '根拠の記録が更新されています。新しい案を確認してください。');
            }

            // Legacy V58.71 work-log drafts recorded no dates or source digest.
            // Their actual displayed action/outcome still need to match.
            if (! array_key_exists('source_signature', $original)
                && ($original['kind'] ?? '') === 'work_log'
                && ($original['worked_on'] ?? null) === null) {
                if ((string) ($original['action'] ?? '') !== $current['action']
                    || (string) ($original['outcome'] ?? '') !== $current['outcome']) {
                    abort(409, '実績の内容が変更されています。');
                }
                continue;
            }

            unset($current['source_signature']);
            $shown = $original;
            unset($shown['source_signature']);
            if (! hash_equals(
                CanonicalJson::encode($shown),
                CanonicalJson::encode($current),
            )) {
                abort(409, '提案に使った根拠が変更されています。');
            }
        }
    }
}
