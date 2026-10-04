<?php

namespace App\Intelligence\Career;

use App\Intelligence\Adapters\TaskEvidenceAdapter;
use App\Intelligence\Data\EvidenceObservation;
use App\Models\Plan;
use App\Models\TaskEvidence;

final class CareerEvidenceCollector
{
    public const TYPES = [
        'interview_review_completed',
        'interview_result_recorded',
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
            ->whereIn('type', self::TYPES)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(
                fn (TaskEvidence $evidence) =>
                    $this->adapter->adapt($evidence),
            )
            ->values()
            ->all();
    }
}
