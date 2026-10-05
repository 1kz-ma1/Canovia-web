<?php

namespace App\Intelligence\Study;

final class StudyWorkspaceSurfaceRegistry
{
    /**
     * @return array<string,array{partial:string,label:string}>
     */
    public function definitions(): array
    {
        return [
            'goal_summary' => [
                'partial' => 'workspace.study.surfaces.goal-summary',
                'label' => 'Goal Summary',
            ],
            'current_state' => [
                'partial' => 'workspace.study.surfaces.current-state',
                'label' => 'Current State',
            ],
            'missing_context' => [
                'partial' => 'workspace.study.surfaces.missing-context',
                'label' => 'Missing Context',
            ],
            'readiness' => [
                'partial' => 'workspace.study.surfaces.readiness',
                'label' => 'Readiness',
            ],
            'biggest_gap' => [
                'partial' => 'workspace.study.surfaces.biggest-gap',
                'label' => 'Biggest Gap',
            ],
            'study_recommendation' => [
                'partial' => 'workspace.study.surfaces.study-recommendation',
                'label' => 'Study Recommendation',
            ],
            'current_action' => [
                'partial' => 'workspace.study.surfaces.current-action',
                'label' => 'Current Action',
            ],
            'weaknesses' => [
                'partial' => 'workspace.study.surfaces.weaknesses',
                'label' => 'Weaknesses',
            ],
            'recent_results' => [
                'partial' => 'workspace.study.surfaces.recent-results',
                'label' => 'Recent Results',
            ],
            'scope_coverage' => [
                'partial' => 'workspace.study.surfaces.scope-coverage',
                'label' => 'Scope Coverage',
            ],
            'study_methods' => [
                'partial' => 'workspace.study.surfaces.study-methods',
                'label' => 'Study Methods',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function surface(
        string $key,
        array $data = [],
    ): array {
        $definition = $this->definitions()[$key] ?? null;

        if (! is_array($definition)) {
            throw new \InvalidArgumentException(
                "Unknown Study Workspace surface [{$key}].",
            );
        }

        return [
            'key' => $key,
            'label' => $definition['label'],
            'partial' => $definition['partial'],
            'data' => $data,
        ];
    }
}
