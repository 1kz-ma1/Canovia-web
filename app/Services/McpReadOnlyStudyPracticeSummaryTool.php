<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use Illuminate\Support\Facades\DB;

/**
 * No raw problems/answers, question banks, response text or per-attempt
 * records. Aggregate only owner-APPLIED attempts. Explicit additional
 * OAuth-approved per-Plan study_progress grant is required for each call.
 */
final class McpReadOnlyStudyPracticeSummaryTool
{
    public const NAME = 'canovia_get_study_practice_summary';

    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
    ) {}

    /** @return array<string,mixed>|null */
    public function read(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        int $limit,
    ): ?array {
        if (config('canovia_mcp.study_read_enabled') !== true
            || config('canovia_mcp.study_summary_read_enabled') !== true
            || config('app.env') === 'staging'
            || $planId < 1 || $limit < 1 || $limit > 8) {
            return null;
        }

        return DB::transaction(function () use ($principal, $planId, $limit): ?array {
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($plan === null) {
                return null;
            }
            $grant = $this->policy->authorizedGrant(
                $principal,
                $plan,
                'study_progress',
                lockForRead: true,
                requiredDomain: 'study',
            );
            if ($grant === null) {
                return null;
            }

            // Only applied results are final Canovia study evidence; drafts,
            // generated exercises and unconfirmed assessments are excluded.
            $attempts = StudyPracticeAttempt::query()
                ->where('plan_id', $plan->id)
                ->where('user_id', $plan->user_id)
                ->whereNotNull('applied_at')
                ->orderByDesc('applied_at')
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get(['score_percent', 'weaknesses', 'assessment', 'applied_at']);
            $hasMoreAppliedAttempts = $attempts->count() > $limit;
            $attempts = $attempts->take($limit);

            $scores = [];
            $topics = [];
            foreach ($attempts as $attempt) {
                $value = $attempt->score_percent;
                if (is_int($value) && $value >= 0 && $value <= 100) {
                    $scores[] = $value;
                }

                // Modern Study attempts classify errors per question. Use
                // only topic tags for incorrect/partial answers, never raw
                // question, model rationale or feedback text. Legacy rows
                // without typed feedback may use the bounded weakness list.
                $items = data_get($attempt->assessment, 'question_feedback', []);
                $items = is_array($items) ? array_slice($items, 0, 30) : [];
                $structured = collect($items)->contains(
                    static fn ($item): bool => is_array($item)
                        && (array_key_exists('correctness', $item)
                            || array_key_exists('error_type', $item))
                );
                $labels = [];
                if ($structured) {
                    foreach ($items as $item) {
                        if (! is_array($item)
                            || ! in_array($item['correctness'] ?? null, ['incorrect', 'partial'], true)) {
                            continue;
                        }
                        $raw = $item['weakness_topics'] ?? [];
                        if (! is_array($raw) || $raw === []) {
                            $raw = $item['misconceptions'] ?? [];
                        }
                        if (is_array($raw)) {
                            array_push($labels, ...array_slice($raw, 0, 8));
                        }
                    }
                } else {
                    $labels = (array) $attempt->weaknesses;
                }

                foreach ($labels as $label) {
                    if (! is_string($label)) {
                        continue;
                    }
                    $label = trim(preg_replace('/[\\p{C}]+/u', ' ', $label) ?? '');
                    if ($label === '' || mb_strlen($label) > 48) {
                        continue;
                    }
                    $key = mb_strtolower($label);
                    if (! isset($topics[$key]) && count($topics) >= 16) {
                        continue;
                    }
                    if (! isset($topics[$key])) {
                        $topics[$key] = ['topic' => $label, 'mentions' => 0];
                    }
                    $topics[$key]['mentions']++;
                }
            }

            $weak = array_values($topics);
            usort($weak, static fn (array $a, array $b): int =>
                ($b['mentions'] <=> $a['mentions']) ?: strcmp($a['topic'], $b['topic'])
            );

            // Metadata audit only. Raw scopes, JWTs, identity, Plan titles,
            // scores and returned topic labels are never stored in the log.
            DB::table('mcp_delegated_access_events')->insert([
                'actor_user_id' => $grant->user_id,
                'subject_link_id' => $grant->subject_link_id,
                'grant_id' => $grant->id,
                'event_kind' => 'context_read',
                'scope' => 'study_progress',
                'created_at' => now(),
            ]);

            return [
                'schema' => 'canovia.mcp.study_practice_summary.v1',
                'delivery' => 'delegated_read_only',
                'scope' => 'study_progress',
                'as_of' => now()->toIso8601String(),
                'plan' => ['title' => mb_substr((string) $plan->title, 0, 160)],
                'sample' => [
                    'applied_attempts_considered' => $attempts->count(),
                    'scored_attempts' => count($scores),
                    'average_score_percent' => $scores !== []
                        ? (int) round(array_sum($scores) / count($scores))
                        : null,
                    'latest_score_percent' => $scores[0] ?? null,
                ],
                'weak_topics' => array_slice($weak, 0, 5),
                'truncated' => count($weak) > 5 || $hasMoreAppliedAttempts,
                'completion' => 'unverified',
                'caveat' => 'Only aggregated owner-applied Canovia study records. Weakness labels and scores may be model-derived or user-recorded, not independently verified. No raw questions, answers, assessments or attempts are exported.',
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Summarize approved Canovia Study practice results',
            'description' => 'Aggregate up to 8 latest owner-applied Study practice results: average/latest score and five short weakness topics. Requires independent explicit Study progress Plan consent, never just Tasks consent. Raw questions, answers, assessment details, descriptions and drafts are excluded. Plan ID must be provided by the owner.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact consented Study Plan ID supplied by its owner.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 8,
                        'description' => 'Most recent applied attempts to aggregate, default 8.',
                    ],
                ],
                'required' => ['plan_id'],
                'additionalProperties' => false,
            ],
            'securitySchemes' => [[
                'type' => 'oauth2',
                'scopes' => [McpProtectedResourceConfiguration::READ_SCOPE],
            ]],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }
}
