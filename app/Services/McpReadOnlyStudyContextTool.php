<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Minimum viable study-context MCP read. Separate from the Development tool:
 * no exercise answers, question bank content, evidence or user profile.
 *
 * The same per-Plan/subject/client/scope grant is rechecked under locks on
 * every request. Synthetic-only staging must never read a real study Plan.
 */
final class McpReadOnlyStudyContextTool
{
    public const NAME = 'canovia_get_study_plan_context';

    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
    ) {}

    /** @return array<string, mixed>|null */
    public function read(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        string $scope,
        int $limit,
    ): ?array {
        if (config('canovia_mcp.study_read_enabled') !== true
            || config('app.env') === 'staging'
            || $planId < 1
            || ! in_array($scope, ['overview', 'tasks'], true)
            || $limit < 1 || $limit > 8) {
            return null;
        }

        return DB::transaction(function () use ($principal, $planId, $scope, $limit): ?array {
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($plan === null) {
                return null;
            }

            $grant = $this->policy->authorizedGrant(
                $principal,
                $plan,
                $scope,
                lockForRead: true,
                requiredDomain: 'study',
            );
            if ($grant === null) {
                return null;
            }

            $items = [];
            $truncated = false;
            if ($scope === 'tasks') {
                $tasks = Task::query()
                    ->where('plan_id', $plan->id)
                    ->whereIn('status', ['doing', 'todo', 'paused'])
                    ->orderByRaw("CASE status WHEN 'doing' THEN 0 WHEN 'todo' THEN 1 ELSE 2 END")
                    ->orderBy('priority')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->limit($limit + 1)
                    ->get(['id', 'title', 'status', 'progress_percent']);

                $truncated = $tasks->count() > $limit;
                $items = $tasks->take($limit)->map(static fn (Task $task): array => [
                    'task_id' => (int) $task->id,
                    'title' => mb_substr((string) $task->title, 0, 160),
                    'status' => (string) $task->status,
                    'recorded_progress_percent' => $task->progress_percent === null
                        ? null
                        : max(0, min(100, (int) $task->progress_percent)),
                ])->values()->all();
            }

            // Metadata only. Exactly the same audit boundary as the
            // Development tool; do not persist titles, JWTs or answers.
            DB::table('mcp_delegated_access_events')->insert([
                'actor_user_id' => $grant->user_id,
                'subject_link_id' => $grant->subject_link_id,
                'grant_id' => $grant->id,
                'event_kind' => 'context_read',
                'scope' => $scope,
                'created_at' => now(),
            ]);

            return [
                'schema' => 'canovia.mcp.study_plan_context.v1',
                'delivery' => 'delegated_read_only',
                'scope' => $scope,
                'as_of' => now()->toIso8601String(),
                'plan' => [
                    'title' => mb_substr((string) $plan->title, 0, 160),
                    // This is the OWNER-RECORDED Plan deadline, not a verified
                    // official exam calendar date.
                    'recorded_deadline' => $plan->deadline?->toDateString(),
                ],
                'tasks' => $items,
                'truncated' => $truncated,
                'completion' => 'unverified',
                'caveat' => 'Only owner-recorded study tasks and deadline. No assessment, study results, question or answer data are shared. Task progress is not independently verified.',
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Read approved Canovia Study Plan context',
            'description' => 'Read a specifically consented private Study Plan. Overview gives the title and owner-recorded deadline. Tasks requires Tasks consent and returns up to eight Task IDs, titles, statuses and recorded progress. No exercise questions, answers, scores, personal profile or writes. The Plan ID must be supplied explicitly.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact study Plan ID provided by its owner.',
                    ],
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['overview', 'tasks'],
                        'description' => 'Defaults to overview. Tasks needs a separate Tasks permission.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 8,
                        'description' => 'Maximum returned Tasks; default 5.',
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
