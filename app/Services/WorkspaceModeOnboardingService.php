<?php

namespace App\Services;

use App\Enums\WorkspaceMode;
use App\Intelligence\Presentation\PlanIntelligencePresentation;
use App\Models\Plan;

final class WorkspaceModeOnboardingService
{
    public function __construct(
        private readonly WorkspaceModeRegistry $registry,
    ) {}

    /**
     * @param array<int,string> $completedStepKeys
     * @return array<string,mixed>|null
     */
    public function build(
        WorkspaceMode $mode,
        array $completedStepKeys,
        ?Plan $plan = null,
        ?PlanIntelligencePresentation $presentation = null,
        bool $canEdit = false,
    ): ?array {
        $definition = $this->registry->definition($mode);
        $steps = collect($definition->onboardingSteps)
            ->filter(fn ($step) => is_array($step) && filled($step['key'] ?? null))
            ->values();

        if ($steps->isEmpty()) {
            return null;
        }

        $completed = collect($completedStepKeys)
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->map(fn (string $key) => trim($key))
            ->unique()
            ->values();

        $currentIndex = $steps->search(
            fn (array $step) => ! $completed->contains((string) $step['key']),
        );

        if ($currentIndex === false) {
            return null;
        }

        $renderedSteps = $steps
            ->map(function (array $step, int $index) use (
                $completed,
                $currentIndex,
            ) {
                $key = (string) $step['key'];

                return [
                    ...$step,
                    'position' => $index + 1,
                    'state' => $completed->contains($key)
                        ? 'complete'
                        : ($index === $currentIndex ? 'current' : 'upcoming'),
                ];
            })
            ->all();

        $current = $renderedSteps[$currentIndex];
        $action = $this->action(
            $mode,
            (string) ($current['action_key'] ?? ''),
            $plan,
            $presentation,
            $canEdit,
            (string) ($current['action_label'] ?? '進める'),
        );

        return [
            'mode' => $mode->value,
            'label' => $definition->label,
            'description' => $definition->description,
            'accent_tone' => $definition->accentTone,
            'completed_count' => $completed->intersect(
                $steps->pluck('key'),
            )->count(),
            'total_count' => $steps->count(),
            'steps' => $renderedSteps,
            'current_step' => $current,
            'action' => $action,
        ];
    }

    /**
     * @return array{url:string,method:string,label:string}|null
     */
    private function action(
        WorkspaceMode $mode,
        string $actionKey,
        ?Plan $plan,
        ?PlanIntelligencePresentation $presentation,
        bool $canEdit,
        string $defaultLabel,
    ): ?array {
        if ($actionKey === 'create_plan') {
            return [
                'url' => route('plans.create', [
                    'workspace_mode' => $mode->value,
                ]),
                'method' => 'GET',
                'label' => $defaultLabel,
            ];
        }

        if (
            $actionKey === 'capture_study_scope'
            && $plan instanceof Plan
        ) {
            return [
                'url' => route('plans.study_scope.index', $plan),
                'method' => 'GET',
                'label' => $defaultLabel,
            ];
        }

        if (
            $actionKey === 'study_current_action'
            && $presentation instanceof PlanIntelligencePresentation
        ) {
            if (
                $presentation->actionMethod === 'POST'
                && ! $canEdit
            ) {
                return null;
            }

            return [
                'url' => $presentation->actionUrl,
                'method' => $presentation->actionMethod,
                'label' => $presentation->actionLabel ?: $defaultLabel,
            ];
        }

        if (
            $actionKey === 'connect_github'
            && $plan instanceof Plan
        ) {
            return [
                'url' => route('github_workflow.index', [
                    'plan_id' => $plan->id,
                ]),
                'method' => 'GET',
                'label' => $defaultLabel,
            ];
        }

        return null;
    }
}
