<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;

final class PlanIntelligencePresentationService
{
    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
        private readonly StudyAdaptiveActionService $study,
        private readonly DevelopmentAdaptiveActionService $development,
        private readonly CareerAdaptiveActionService $career,
        private readonly StudyIntelligencePresentationAdapter $studyAdapter,
        private readonly DevelopmentIntelligencePresentationAdapter $developmentAdapter,
        private readonly CareerIntelligencePresentationAdapter $careerAdapter,
    ) {}

    public function forPlan(Plan $plan): ?PlanIntelligencePresentation
    {
        return match ($this->profiles->forPlan($plan)->key) {
            'study' => $this->studyAdapter->adapt(
                $plan,
                $this->study->evaluate($plan),
            ),
            'development' => $this->developmentAdapter->adapt(
                $plan,
                $this->development->evaluate($plan),
            ),
            'career' => $this->careerAdapter->adapt(
                $plan,
                $this->career->evaluate($plan),
            ),
            default => null,
        };
    }

    public function supports(Plan $plan): bool
    {
        return in_array(
            $this->profiles->forPlan($plan)->key,
            ['study', 'development', 'career'],
            true,
        );
    }
}
