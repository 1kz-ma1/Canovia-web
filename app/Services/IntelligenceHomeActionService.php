<?php

namespace App\Services;

use App\Intelligence\Presentation\PlanIntelligencePresentation;
use App\Intelligence\Presentation\PlanIntelligencePresentationService;
use App\Models\Plan;
use Illuminate\Support\Collection;

final class IntelligenceHomeActionService
{
    public function __construct(
        private readonly PlanIntelligencePresentationService $presentations,
        private readonly PlanPriorityService $priorities,
    ) {}

    /**
     * Evaluate at most one supported Plan on Home.
     *
     * Task completion is deliberately not used to suppress Intelligence.
     * Readiness / State is the authority for Study and Development.
     *
     * @param Collection<int,Plan> $editablePlans
     * @param Collection<int,array<string,mixed>> $guidanceDeck
     */
    public function primary(
        Collection $editablePlans,
        Collection $guidanceDeck,
    ): ?PlanIntelligencePresentation {
        $primaryGuidance = $guidanceDeck->first();
        $guidancePlan = data_get($primaryGuidance, 'plan');

        $topSupported = $editablePlans
            ->filter(
                fn (Plan $candidate) => $this->presentations->supports($candidate),
            )
            ->sort(
                fn (Plan $left, Plan $right) =>
                    $this->comparePlans($left, $right),
            )
            ->first();

        if ($guidancePlan instanceof Plan) {
            if ($this->presentations->supports($guidancePlan)) {
                $plan = $guidancePlan;
            } elseif (
                $topSupported instanceof Plan
                && $this->comparePlans($topSupported, $guidancePlan) < 0
            ) {
                $plan = $topSupported;
            } else {
                return null;
            }
        } else {
            $plan = $topSupported;

            if (! $plan instanceof Plan) {
                return null;
            }
        }

        return $this->presentations->forPlan($plan);
    }

    private function comparePlans(Plan $left, Plan $right): int
    {
        $leftPriority = (int) data_get(
            $this->priorities->evaluate($left),
            'priority',
            3,
        );
        $rightPriority = (int) data_get(
            $this->priorities->evaluate($right),
            'priority',
            3,
        );

        $priority = $leftPriority <=> $rightPriority;
        if ($priority !== 0) {
            return $priority;
        }

        $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
            <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

        if ($deadline !== 0) {
            return $deadline;
        }

        return ((int) $left->id) <=> ((int) $right->id);
    }
}
