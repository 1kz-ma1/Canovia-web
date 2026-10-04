<?php

namespace App\Intelligence\Development;

use App\Enums\EvidenceSource;
use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Data\EvidenceObservation;
use App\Models\Plan;
use App\Models\TaskEvidence;

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
    ];

    public function __construct(
        private readonly TaskEvidenceAdapter $adapter,
    ) {}

    /**
     * @return array<int,EvidenceObservation>
     */
    public function collect(Plan $plan): array
    {
        return TaskEvidence::query()
            ->where('plan_id', $plan->id)
            ->where('source', EvidenceSource::GitHub->value)
            ->whereIn('type', self::TYPES)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TaskEvidence $evidence) => $this->adapter->adapt($evidence))
            ->values()
            ->all();
    }
}
