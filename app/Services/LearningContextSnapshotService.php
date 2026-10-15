<?php

namespace App\Services;

/**
 * Pure, ephemeral Learning context projection: Facts -> bounded observations
 * -> explicitly tentative study hint. Takes only already-authorized, whitelisted
 * Study and practice-summary projections. No AI call, persistence or notification.
 *
 * Reuse this composer inside Canovia Intelligence in a later, independently
 * authorized phase; never publish raw evidence or a free-form generated profile.
 */
final class LearningContextSnapshotService
{
    /** @param array<string, mixed> $study @param array<string, mixed> $practice */
    public function compose(array $study, array $practice): ?array
    {
        if (($study['schema'] ?? null) !== 'canovia.mcp.study_plan_context.v1'
            || ($study['scope'] ?? null) !== 'tasks'
            || ($practice['schema'] ?? null) !== 'canovia.mcp.study_practice_summary.v1'
            || ($practice['scope'] ?? null) !== 'study_progress'
            || ! is_array($study['plan'] ?? null)
            || ! is_array($practice['plan'] ?? null)
            || ($study['plan']['title'] ?? null) !== ($practice['plan']['title'] ?? null)
            || ! is_array($study['tasks'] ?? null)
            || ! is_array($practice['sample'] ?? null)
            || ! is_array($practice['weak_topics'] ?? null)) {
            return null;
        }

        $tasks = array_slice($study['tasks'], 0, 8);
        $weaknesses = array_slice($practice['weak_topics'], 0, 5);
        $sample = $practice['sample'];
        $considered = max(0, min(8, (int) ($sample['applied_attempts_considered'] ?? 0)));
        $scored = max(0, min($considered, (int) ($sample['scored_attempts'] ?? 0)));

        // A weakness label is recorded evidence, never a certified ability gap.
        // The source's bounded labels are shared only under learning_context consent.
        $observedTopics = [];
        foreach ($weaknesses as $topic) {
            if (! is_array($topic)
                || ! is_string($topic['topic'] ?? null)
                || mb_strlen($topic['topic']) > 48
                || trim($topic['topic']) === '') {
                continue;
            }
            $observedTopics[] = [
                'topic' => $topic['topic'],
                'mentions_in_sample' => max(0, min(8, (int) ($topic['mentions'] ?? 0))),
                'source' => 'approved_practice_aggregate',
                'certainty' => 'recorded_not_independently_verified',
            ];
        }

        // This is a rule-based HINT, not a Plan/Task mutation, scheduler or
        // proof that the highest-mentioned topic is objectively the weakest.
        $hint = $observedTopics === [] ? null : [
            'kind' => 'review_topic',
            'topic' => $observedTopics[0]['topic'],
            'suggested_question_count' => 10,
            'basis' => 'most_frequently_tagged_topic_in_bounded_sample',
            'certainty' => 'tentative',
            'state' => 'suggestion_only_not_applied',
        ];

        return [
            'schema' => 'canovia.learning_context_snapshot.v1',
            'delivery' => 'ephemeral_explicitly_consented_read_only',
            'purpose' => 'support_one_private_study_plan',
            'computed_at' => now()->toIso8601String(),
            'sources' => [
                // These mean READ TIME, not the date of the underlying result.
                'study_read_at' => $study['as_of'] ?? null,
                'practice_read_at' => $practice['as_of'] ?? null,
                'raw_evidence_shared' => false,
            ],
            'facts' => [
                'plan' => [
                    'title' => $study['plan']['title'],
                    'owner_recorded_deadline' => $study['plan']['recorded_deadline'] ?? null,
                    'certainty' => 'owner_recorded_not_official_exam_schedule',
                ],
                'active_tasks' => [
                    'items' => $tasks,
                    'truncated' => (bool) ($study['truncated'] ?? false),
                    'certainty' => 'owner_recorded_unverified_progress',
                ],
                'recent_practice' => [
                    'applied_attempts_considered' => $considered,
                    'scored_attempts' => $scored,
                    'owner_approved_external_results_considered' => max(
                        0, min($considered, (int) ($sample['owner_approved_mcp_results_considered'] ?? 0))
                    ),
                    'average_score_percent' => $sample['average_score_percent'] ?? null,
                    'latest_score_percent' => $sample['latest_score_percent'] ?? null,
                    'truncated' => (bool) ($practice['truncated'] ?? false),
                    'certainty' => 'recorded_scores_not_exam_verified',
                ],
            ],
            'observations' => [
                'weakness_tags' => $observedTopics,
                'sample_coverage' => $considered === 0
                    ? 'no_applied_practice_in_bounded_sample'
                    : 'latest_applied_results_only_not_all_history',
                'confidence' => $considered >= 3 ? 'bounded_sample' : 'insufficient_or_small_sample',
            ],
            'decision_support' => ['next_study_hint' => $hint],
            'limitations' => [
                'No raw questions, answers, grading rationale, notes, evidence, personal profile, or other Plans.',
                'A recorded score or Task status is not independent proof of competence or completion.',
                'A missing weakness tag does not establish mastery; this is not a diagnostic result.',
                'No monitoring, automatic notification, plan reprioritization or Task mutation occurs.',
            ],
        ];
    }
}
