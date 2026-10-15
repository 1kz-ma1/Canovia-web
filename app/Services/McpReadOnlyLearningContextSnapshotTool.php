<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated, explicitly re-consented learning_context scope.
 * No existing overview/tasks/study_progress grant silently enables this tool.
 */
final class McpReadOnlyLearningContextSnapshotTool
{
    public const NAME = 'canovia_get_learning_context_snapshot';

    public function __construct(
        private readonly McpDelegatedPlanAccessPolicy $policy,
        private readonly McpReadOnlyStudyContextTool $study,
        private readonly McpReadOnlyStudyPracticeSummaryTool $practice,
        private readonly LearningContextSnapshotService $snapshots,
    ) {}

    public function enabled(): bool
    {
        return config('canovia_mcp.learning_context_snapshot_enabled') === true
            && config('canovia_mcp.study_read_enabled') === true
            && config('canovia_mcp.study_summary_read_enabled') === true
            && config('app.env') !== 'staging'
            && app(McpStudyPilotAccessPolicy::class)->configured();
    }

    /** @return array<string, mixed>|null */
    public function read(McpVerifiedTokenPrincipal $principal, int $planId): ?array
    {
        if (! $this->enabled() || $planId < 1) {
            return null;
        }

        // The outer transaction keeps owner, subject, client, grant and Plan
        // locks held across BOTH projections. Revalidate the specific new
        // consent under the lock before invoking either underlying read.
        return DB::transaction(function () use ($principal, $planId): ?array {
            $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
            if ($plan === null
                || $this->policy->authorizedGrant(
                    $principal, $plan, 'learning_context',
                    lockForRead: true, requiredDomain: 'study',
                ) === null) {
                return null;
            }

            $study = $this->study->read($principal, $planId, 'tasks', 8);
            $practice = $this->practice->read($principal, $planId, 8);
            if ($study === null || $practice === null) {
                return null;
            }

            return $this->snapshots->compose($study, $practice);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'Read explicitly approved Canovia Learning Context Snapshot',
            'description' => 'Read one explicitly consented private Study Plan context snapshot: owner-recorded deadline, up to eight active Task titles/progress, up to eight approved practice results in aggregate, and tentative weakness-based study hints. Requires distinct learning_context consent. No raw questions, answers, private profile, other Plans or writes.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'plan_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Exact Study Plan ID explicitly selected by its owner.',
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
