<?php

namespace App\Intelligence\Study;

use App\Intelligence\Data\StudyAdaptiveActionResult;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyScoreObservation;
use App\Services\BookkeepingPlacementDiagnosticService;
use App\Services\BookkeepingJournalPracticeService;
use App\Models\TaskEvidence;
use Illuminate\Database\Eloquent\Builder;

final class StudyWorkspaceStateResolver
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(
        Plan $plan,
        StudyAdaptiveActionResult $adaptive,
        ?int $userId = null,
        ?string $actorToken = null,
    ): array {
        $state = $adaptive->intelligence->state;
        $metrics = (array) $state->metrics;
        $facts = (array) $state->facts;

        $attempts = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->latest('created_at')
            ->latest('id')
            ->take(8)
            ->get();

        $scoreObservations = $this->scoreQuery(
            $plan,
            $userId,
            $actorToken,
        )
            // Keep internal placement diagnosis separate from an external
            // exam/score baseline; it has a different evidence contract.
            ->whereNotIn('metric_key', [
                BookkeepingPlacementDiagnosticService::METRIC,
                BookkeepingJournalPracticeService::METRIC,
            ])
            ->latest('observed_at')
            ->latest('id')
            ->take(6)
            ->get();

        $latestScoreObservation = $scoreObservations->first();

        $practiceEvidenceCount = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->where('type', 'study_practice_assessed')
            ->count();

        $recallEvidenceCount = TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->where('type', 'study_recall_reviewed')
            ->count();

        $scopeCount = max(
            0,
            (int) data_get($metrics, 'confirmed_scope_count', 0),
        );
        $observedScopeCount = max(
            0,
            (int) data_get($metrics, 'observed_scope_count', 0),
        );
        $attemptCount = StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)
            ->count();

        $scores = $attempts
            ->pluck('score_percent')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $weaknesses = $this->weaknesses($attempts->all());

        $currentPositionKnown = $scoreObservations->isNotEmpty()
            || $attemptCount > 0
            || $practiceEvidenceCount > 0
            || $recallEvidenceCount > 0
            || $observedScopeCount > 0;

        $activeTasks = $plan->relationLoaded('tasks')
            ? $plan->tasks
            : $plan->tasks()->get();

        $activeTaskCount = $activeTasks
            ->reject(fn ($task) => in_array(
                (string) $task->status,
                ['done', 'completed', 'cancelled'],
                true,
            ))
            ->count();

        $phase = match (true) {
            $attemptCount > 0 => 'active_practice',
            $practiceEvidenceCount > 0 || $recallEvidenceCount > 0 => 'evidence_informed',
            $scoreObservations->isNotEmpty() => 'score_baseline_known',
            $scopeCount > 0 => 'scope_informed',
            $activeTaskCount > 0 => 'baseline_needed',
            default => 'context_needed',
        };

        return [
            'phase' => $phase,
            'phase_label' => match ($phase) {
                'active_practice' => '演習履歴から現在地を把握済み',
                'evidence_informed' => '学習Evidenceから現在地を把握済み',
                'score_baseline_known' => '外部スコアから現在地を把握済み',
                'scope_informed' => '学習範囲を基準に現在地を測定中',
                'baseline_needed' => '最初の現在地確認が必要',
                default => '学習の入口を設定中',
            },
            'current_position_known' => $currentPositionKnown,
            'has_confirmed_scope' => $scopeCount > 0,
            'official_exam_reference' => is_array($facts['official_exam_reference'] ?? null)
                ? $facts['official_exam_reference'] : null,
            'confirmed_scope_count' => $scopeCount,
            'observed_scope_count' => $observedScopeCount,
            'practice_attempt_count' => $attemptCount,
            'score_observation_count' => $scoreObservations->count(),
            'has_external_score_baseline' => $scoreObservations->isNotEmpty(),
            'latest_external_score' => $latestScoreObservation
                instanceof StudyScoreObservation
                    ? $this->scoreObservation($latestScoreObservation)
                    : null,
            'external_score_history' => $scoreObservations
                ->map(fn (StudyScoreObservation $observation) =>
                    $this->scoreObservation($observation)
                )
                ->values()
                ->all(),
            'practice_evidence_count' => $practiceEvidenceCount,
            'recall_evidence_count' => $recallEvidenceCount,
            'active_task_count' => $activeTaskCount,
            'latest_score_percent' => $scores->first(),
            'recent_average_score_percent' => $scores->isNotEmpty()
                ? (int) round($scores->average())
                : null,
            'recent_scores' => $attempts
                ->take(5)
                ->map(fn (StudyPracticeAttempt $attempt) => [
                    'id' => (int) $attempt->id,
                    'title' => $attempt->exercise_title ?: 'AI演習',
                    'score_percent' => (int) $attempt->score_percent,
                    'created_at' => $attempt->created_at,
                ])
                ->values()
                ->all(),
            'weaknesses' => $weaknesses,
            'days_until_exam' => is_numeric(
                data_get($metrics, 'days_until_exam')
            )
                ? (int) data_get($metrics, 'days_until_exam')
                : null,
            'deadline_pressure' => (string) data_get(
                $facts,
                'deadline_pressure',
                'unknown',
            ),
            'exam_date' => data_get($facts, 'exam_date'),
            'readiness_score' => $adaptive->intelligence->readiness->score,
            'readiness_confidence' => $adaptive->intelligence
                ->readiness
                ->confidence
                ->value,
            'priority_remaining_scope' => collect(
                data_get($facts, 'priority_remaining_scope', [])
            )
                ->filter(fn ($item) => is_array($item))
                ->take(6)
                ->values()
                ->all(),
        ];
    }

    private function scoreQuery(
        Plan $plan,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = StudyScoreObservation::query()
            ->where('plan_id', $plan->id);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query
            ->whereNull('user_id')
            ->where('actor_token', (string) $actorToken);
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreObservation(
        StudyScoreObservation $observation,
    ): array {
        return [
            'id' => (int) $observation->id,
            'metric_key' => (string) $observation->metric_key,
            'metric_label' => (string) $observation->metric_label,
            'score_value' => (float) $observation->score_value,
            'display_value' => $observation->displayValue(),
            'scale_min' => $observation->scale_min,
            'scale_max' => $observation->scale_max,
            'unit' => (string) $observation->unit,
            'source_kind' => (string) $observation->source_kind,
            'source_label' => $observation->sourceLabel(),
            'source_detail' => $observation->source_label,
            'observed_at' => $observation->observed_at,
            'components' => is_array($observation->components)
                ? $observation->components
                : [],
        ];
    }

    /**
     * @param array<int,StudyPracticeAttempt> $attempts
     * @return array<int,string>
     */
    private function weaknesses(array $attempts): array
    {
        $weaknesses = collect();

        foreach ($attempts as $attempt) {
            $assessment = is_array($attempt->assessment)
                ? $attempt->assessment
                : [];
            $feedback = collect(data_get(
                $assessment,
                'question_feedback',
                [],
            ))
                ->filter(fn ($item) => is_array($item))
                ->filter(fn (array $item) => in_array(
                    (string) ($item['correctness'] ?? ''),
                    ['incorrect', 'partial'],
                    true,
                ));

            $structured = $feedback
                ->flatMap(fn (array $item) =>
                    is_array($item['weakness_topics'] ?? null)
                        ? $item['weakness_topics']
                        : []
                )
                ->filter(fn ($item) =>
                    is_scalar($item)
                    && trim((string) $item) !== ''
                )
                ->map(fn ($item) => trim((string) $item));

            if ($structured->isNotEmpty()) {
                $weaknesses = $weaknesses->concat($structured);
                continue;
            }

            $weaknesses = $weaknesses->concat(
                collect($attempt->weaknesses ?? [])
                    ->filter(fn ($item) =>
                        is_scalar($item)
                        && trim((string) $item) !== ''
                    )
                    ->map(fn ($item) => trim((string) $item))
            );
        }

        return $weaknesses
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(6)
            ->values()
            ->all();
    }
}
