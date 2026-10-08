<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * One narrowly scoped MCP tool: bounded Plan title and optionally Task titles,
 * statuses and recorded progress. No GitHub/Evidence/log/description export.
 *
 * Caller MUST have an IdP-introspected ChatGPT bearer. The transaction locks
 * Plan, link and grant through authorizedGrant(), ensuring that concurrent
 * revocation or ownership changes cannot interleave with projection.
 */
final class McpReadOnlyContextTool
{
    public const NAME = 'canovia_get_development_plan_context';

    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
        private readonly DevelopmentPrivateAiContextPreviewService $preview,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(
        McpVerifiedTokenPrincipal $principal,
        int $planId,
        string $scope,
        int $limit,
    ): ?array {
        if ($planId < 1 || ! in_array($scope, ['overview', 'tasks'], true)
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
            );
            if ($grant === null) {
                // Missing Plan, no subject link, revoked grant and unapproved
                // scope are all deliberately indistinguishable to the client.
                return null;
            }

            $projection = $this->preview->preview($plan, $scope, $limit);

            $result = [
                'schema' => 'canovia.mcp.development_plan_context.v1',
                'delivery' => 'delegated_read_only',
                'scope' => $scope,
                'as_of' => $projection['as_of'],
                'plan' => $projection['plan'],
                'tasks' => $projection['tasks'],
                'truncated' => $projection['truncated'],
                'completion' => 'unverified',
                'caveat' => 'Recorded Canovia data only. Task status and progress are not evidence of GitHub PR, CI, production deploy or device verification.',
            ];

            // Append metadata-only access evidence atomically with the read.
            // Never store Plan/Task title, bearer, subject, payload, cookies.
            DB::table('mcp_delegated_access_events')->insert([
                'actor_user_id' => $grant->user_id,
                'subject_link_id' => $grant->subject_link_id,
                'grant_id' => $grant->id,
                'event_kind' => 'context_read',
                'scope' => $scope,
                'created_at' => now(),
            ]);

            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Read approved Canovia Development Plan context',
            'description' => 'Read only a specifically consented personal Development Plan. Overview returns its title. Tasks scope requires separate Tasks consent and returns up to eight Task titles, statuses and recorded progress. No Task descriptions, evidence, GitHub credentials or mutation. Plan IDs must be supplied explicitly.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact Canovia Plan ID selected by its owner.',
                    ],
                    'scope' => [
                        'type' => 'string',
                        'enum' => ['overview', 'tasks'],
                        'description' => 'Default overview. Tasks requires separate user consent.',
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
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }
}
