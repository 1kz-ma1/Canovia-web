<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\WorkLog;
use Illuminate\Validation\ValidationException;

final class PlanActionDraftEvidenceService
{
    public const MAX_SOURCES = 5;

    /**
     * @param array<int, int|string> $ids
     * @return list<array{kind:string,work_log_id:int,action:string,outcome:string,worked_on:?string}>
     */
    public function snapshotsForLogs(Plan $plan, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'work_log_ids' => '有効な実績を1〜5件選んでください。',
            ]);
        }

        $logs = WorkLog::query()->with('task:id,title')
            ->where('plan_id', $plan->id)
            ->whereIn('id', $ids)->get();

        if ($logs->count() !== count($ids)) {
            abort(404); // Never disclose existence of evidence in another Plan.
        }

        // Stable chronological order, not checkbox submission order.
        $logs = $logs->sort(fn ($a, $b) =>
            strcmp((string) $a->worked_on?->toDateString(), (string) $b->worked_on?->toDateString())
                ?: ($a->id <=> $b->id)
        )->values();

        return $logs->map(function (WorkLog $log): array {
            $action = trim((string) ($log->task_title_snapshot ?: $log->task?->title));
            $outcome = trim((string) ($log->outcome ?: $log->memo));
            if ($action === '' || $outcome === '') {
                throw ValidationException::withMessages([
                    'work_log_ids' => '作業名と結果がそろった実績を選んでください。',
                ]);
            }

            return [
                'kind' => 'work_log',
                'work_log_id' => (int) $log->id,
                'action' => mb_substr($action, 0, 255),
                'outcome' => $outcome,
                'worked_on' => $log->worked_on?->toDateString(),
            ];
        })->all();
    }

    /** @return list<array<string,mixed>> */
    public function existing(PlanActionDraft $draft): array
    {
        if (is_array($draft->evidence_snapshots) && $draft->evidence_snapshots !== []) {
            return $draft->evidence_snapshots;
        }

        // V58.71 pre-migration rows have the original evidence only in columns.
        return [[
            'kind' => $draft->source_kind === 'work_log' ? 'work_log' : 'self_report',
            'work_log_id' => $draft->source_work_log_id ? (int) $draft->source_work_log_id : null,
            'action' => $draft->completed_action,
            'outcome' => $draft->observed_outcome,
            'worked_on' => null,
        ]];
    }

    /** @param list<array<string,mixed>> $existing
     *  @param list<array<string,mixed>> $additional
     *  @return list<array<string,mixed>>
     */
    public function appendNew(array $existing, array $additional): array
    {
        $used = array_values(array_filter(array_map(
            fn ($source) => $source['kind'] === 'work_log' ? (int) ($source['work_log_id'] ?? 0) : 0,
            $existing,
        )));

        $new = array_values(array_filter($additional, fn ($source) =>
            ! in_array((int) $source['work_log_id'], $used, true)
        ));
        if ($new === []) {
            throw ValidationException::withMessages([
                'additional_work_log_ids' => 'まだこの案に含まれていない実績を選んでください。',
            ]);
        }
        if (count($new) !== count($additional)) {
            throw ValidationException::withMessages([
                'additional_work_log_ids' => 'すでに含まれる実績は選択できません。',
            ]);
        }
        if (count($existing) + count($new) > self::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'additional_work_log_ids' => '根拠は合計5件までです。',
            ]);
        }
        return array_merge($existing, $new);
    }

    /** The rule is explicit and deterministic; it does not assert cause or mastery. */
    public function suggest(array $sources): string
    {
        $last = $sources[count($sources) - 1];
        $action = mb_substr((string) $last['action'], 0, 95);
        return '「'.$action.'」を含む'.count($sources).'件の結果を比較し、次に試す作業を1つ決める';
    }

    public function fingerprint(array $sources): string
    {
        return hash('sha256', json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function candidateFingerprint(PlanActionDraft $draft): string
    {
        return hash('sha256', $draft->suggested_next_action);
    }
}
