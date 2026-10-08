<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActionDraft;
use Illuminate\Support\Str;

/**
 * Actionability and duplicate warnings. Never interprets user logs as
 * objectively verified proficiency; never silently rejects a valid title.
 */
final class PlanActionDraftQualityService
{
    /** @return array{duplicate_titles:list<string>,source_count:int,has_observed_evidence:bool,template_prompt:bool} */
    public function review(Plan $plan, PlanActionDraft $draft, array $titles): array
    {
        $existing = $plan->tasks()->whereIn('status', ['todo', 'doing'])
            ->pluck('title')->map(fn ($name) => mb_strtolower(trim((string) $name)))->all();

        $duplicates = collect($titles)
            ->filter(fn ($title) => in_array(mb_strtolower(trim((string) $title)), $existing, true))
            ->unique()->values()->all();

        $sources = is_array($draft->evidence_snapshots) && $draft->evidence_snapshots !== []
            ? $draft->evidence_snapshots
            : [['kind' => $draft->source_kind === 'work_log' ? 'work_log' : 'self_report']];

        return [
            'duplicate_titles' => $duplicates,
            'source_count' => count($sources),
            'has_observed_evidence' => collect($sources)->contains(fn ($source) =>
                in_array($source['kind'] ?? '', ['task_evidence', 'work_log'], true)
            ),
            'template_prompt' => str_contains((string) $draft->suggested_next_action, '次に試す作業を1つ決める')
                || str_contains((string) $draft->suggested_next_action, '記録')
                    && str_contains((string) $draft->suggested_next_action, '参考に'),
        ];
    }
}
