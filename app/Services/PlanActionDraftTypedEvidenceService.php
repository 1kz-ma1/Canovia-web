<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\TaskEvidence;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reuse captured, scoped TaskEvidence as user-selected provenance, without
 * interpreting scores as mastery or remote observations as verified facts.
 */
final class PlanActionDraftTypedEvidenceService
{
    private const TYPES = [
        'study' => [
            'study_practice_assessed', 'study_recall_reviewed',
            'study_resource_study_completed', 'study_language_activity_completed',
        ],
        'development' => [
            'pull_request_observed', 'pull_request_review_submitted',
            'pull_request_merged', 'pull_request_ci_observed',
            'github_issue_observed', 'github_commit_observed',
            'github_deployment_observed', 'development_quality_gate_confirmed',
        ],
        'career' => ['interview_review_completed', 'interview_result_recorded'],
    ];

    public function __construct(private readonly PlanCategoryProfileService $profiles) {}

    public function domain(Plan $plan): string
    {
        return $this->profiles->forPlan($plan)->key;
    }

    /** @return list<string> */
    public function allowedTypes(Plan $plan): array
    {
        return self::TYPES[$this->domain($plan)] ?? [];
    }

    /** @return Collection<int,TaskEvidence> */
    public function available(Plan $plan): Collection
    {
        $types = $this->allowedTypes($plan);
        if ($types === []) {
            return collect();
        }

        return TaskEvidence::where('plan_id', $plan->id)
            ->whereIn('type', $types)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * @param list<int|string> $ids
     * @return list<array<string,mixed>>
     */
    public function snapshots(Plan $plan, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > PlanActionDraftEvidenceService::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'task_evidence_ids' => '選択できる観測記録は1〜5件です。',
            ]);
        }

        $rows = TaskEvidence::query()->where('plan_id', $plan->id)
            ->whereIn('type', $this->allowedTypes($plan))
            ->whereIn('id', $ids)->get();

        // Cross-Plan and wrong-Domain records share the same non-disclosure result.
        if ($rows->count() !== count($ids)) {
            abort(404);
        }

        return $rows->sort(fn (TaskEvidence $a, TaskEvidence $b) =>
            strcmp((string) $a->occurred_at, (string) $b->occurred_at)
                ?: ($a->id <=> $b->id)
        )->values()->map(function (TaskEvidence $row): array {
            return [
                'kind' => 'task_evidence',
                'task_evidence_id' => (int) $row->id,
                'evidence_type' => $row->type,
                'evidence_source' => $row->source->value,
                'source_label' => $row->sourceLabel(),
                'action' => mb_substr($row->typeLabel(), 0, 255),
                'outcome' => mb_substr($row->summary(), 0, 2000),
                'occurred_at' => $row->occurred_at?->toIso8601String(),
            ];
        })->all();
    }

    /** Rules are action prompts only, not estimated capability or aptitude. */
    public function suggestion(Plan $plan, array $sources): string
    {
        $typed = array_values(array_filter($sources, fn ($s) => ($s['kind'] ?? '') === 'task_evidence'));
        if ($typed === []) {
            return app(PlanActionDraftEvidenceService::class)->suggest($sources);
        }

        $recent = $typed[count($typed) - 1];
        $topic = match ($recent['evidence_type'] ?? '') {
            'study_practice_assessed' => '演習結果を見返し、復習が必要な設問があれば最大3問選ぶ',
            'study_recall_reviewed' => 'Recallの振り返りを見返し、次に確認するカードを選ぶ',
            'study_resource_study_completed', 'study_language_activity_completed'
                => '今回の学習記録を見返し、次に練習する内容を1つ選ぶ',
            'pull_request_merged' => 'マージしたPRを確認し、必要な動作検証を1件決める',
            'pull_request_ci_observed' => 'CIの記録を確認し、追加で確認すべき項目を1つ選ぶ',
            'pull_request_review_submitted' => 'レビュー記録を確認し、対応する指摘があるかを確認する',
            'github_deployment_observed', 'development_quality_gate_confirmed'
                => '品質確認の記録を見直し、次に確認すべき動作を1つ選ぶ',
            'pull_request_observed', 'github_issue_observed', 'github_commit_observed'
                => '開発イベントの記録を見直し、次に進めるIssueか検証作業を1つ選ぶ',
            'interview_review_completed' => '面接の振り返りを確認し、次回の準備を1つ試す',
            'interview_result_recorded' => '選考結果と本人の希望を照合し、次の選択を1つ考える',
            default => '記録を見返し、次に試す行動を1つ考える',
        };
        return mb_substr('記録'.count($sources).'件を参考に：'.$topic, 0, 255);
    }
}
