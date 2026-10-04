<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\Plan;
use App\Models\Task;

final readonly class PlanIntelligencePresentation
{
    /**
     * @param array<int,array{label:string,value:string,detail:?string}> $metrics
     */
    public function __construct(
        public IntelligenceDomain $domain,
        public Plan $plan,
        public StateSnapshot $state,
        public ReadinessAssessment $readiness,
        public Decision $decision,
        public ActionProposal $action,
        public string $eyebrow,
        public string $headline,
        public string $readinessLabel,
        public string $stateLabel,
        public string $gapLabel,
        public string $gapDetail,
        public string $detailUrl,
        public string $actionUrl,
        public string $actionMethod,
        public string $actionLabel,
        public array $metrics = [],
        public ?Task $targetTask = null,
        public bool $requiresTaskProjection = false,
    ) {}

    public function readinessDisplay(): string
    {
        return $this->readiness->score === null
            ? '未判定'
            : $this->readiness->score.'/100';
    }

    public function confidenceDisplay(): string
    {
        return $this->readiness->confidence->percent().'%';
    }
}
