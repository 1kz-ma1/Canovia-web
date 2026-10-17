<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Old-94-migration AP pilot: ONE owner's private Study Plan overview only.
 *
 * No Tasks, question data, attempts, scores, profile, evidence or write.
 * Every call locks Plan, linked subject and the one-day grant before reading.
 */
final class McpReadOnlyStudyContextTool
{
    public const NAME = 'canovia_get_study_plan_context';

    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
    ) {}

    /** @return array<string,mixed>|null */
    public function read(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        string $scope,
        int $limit,
    ): ?array {
        if (config('canovia_mcp.ap_pilot_enabled') !== true
            || config('canovia_mcp.study_read_enabled') !== true
            || config('app.env') === 'staging'
            || $scope !== 'overview'
            || $planId < 1 || $limit < 1 || $limit > 8) {
            return null;
        }

        return DB::transaction(function () use ($principal, $planId): ?array {
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($plan === null) {
                return null;
            }

            $grant = $this->policy->authorizedGrant(
                $principal, $plan, 'overview', lockForRead: true,
                requiredDomain: 'study',
            );
            if ($grant === null) {
                return null;
            }

            DB::table('mcp_delegated_access_events')->insert([
                'actor_user_id' => $grant->user_id,
                'subject_link_id' => $grant->subject_link_id,
                'grant_id' => $grant->id,
                'event_kind' => 'context_read',
                'scope' => 'overview',
                'created_at' => now(),
            ]);

            return [
                'schema' => 'canovia.mcp.study_plan_context.v1',
                'delivery' => 'delegated_read_only',
                'scope' => 'overview',
                'as_of' => now()->toIso8601String(),
                'plan' => [
                    'title' => mb_substr((string) $plan->title, 0, 160),
                    'recorded_deadline' => $plan->deadline?->toDateString(),
                ],
                'tasks' => [],
                'truncated' => false,
                'completion' => 'unverified',
                'caveat' => 'Only owner-recorded Plan title and deadline. No learning performance or task data.',
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Read approved Canovia Study Plan overview',
            'description' => 'Read the title and owner-recorded deadline of ONE explicitly consented personal Study Plan. Requires an exact Plan ID. No Tasks, learning history, answers, scores or writes.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact private Study Plan ID provided by its owner.',
                    ],
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['overview'],
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
