<?php

namespace App\Intelligence\Development;

use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Data\EvidenceObservation;
use App\Models\Plan;
use App\Models\TaskEvidence;
use App\Services\GitHubTaskEvidenceReuseGuard;

final class DevelopmentEvidenceCollector
{
    public const TYPES = [
        'pull_request_observed',
        'pull_request_review_submitted',
        'pull_request_ci_observed',
        'pull_request_merged',
        'github_issue_observed',
        'github_branch_observed',
        'github_commit_observed',
        'github_deployment_observed',
        'development_quality_gate_confirmed',
    ];

    public function __construct(
        private readonly TaskEvidenceAdapter $adapter,
        private readonly GitHubTaskEvidenceReuseGuard $reuseGuard,
    ) {}

    /**
     * @return array<int,EvidenceObservation>
     */
    public function collect(Plan $plan): array
    {
        return TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->whereIn('type', self::TYPES)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (TaskEvidence $evidence) => $this->reuseGuard->eligible($plan, $evidence))
            ->map(fn (TaskEvidence $evidence) => $this->adapter->adapt($evidence))
            ->values()
            ->all();
    }
}
