<?php

namespace App\Services;

use Illuminate\Http\Request;

final class StudyWorkspaceViewService
{
    public const DEFAULT_VIEW = 'work';

    /**
     * @return array<string,array{
     *   key:string,
     *   label:string,
     *   description:string,
     *   category_key:string,
     *   category_label:string,
     *   category_order:int,
     *   view_order:int,
     *   surface_keys:array<int,string>
     * }>
     */
    public function definitions(): array
    {
        return [
            'work' => [
                'key' => 'work',
                'label' => '今やること',
                'description' => '次のActionと学習方法',
                'category_key' => 'execution',
                'category_label' => '実行',
                'category_order' => 10,
                'view_order' => 10,
                'surface_keys' => [
                    'missing_context',
                    'study_method_recommendation',
                    'study_recommendation',
                    'current_action',
                    'study_methods',
                ],
            ],
            'preparation' => [
                'key' => 'preparation',
                'label' => '学習準備',
                'description' => '範囲・教材・学習条件',
                'category_key' => 'preparation',
                'category_label' => '準備',
                'category_order' => 20,
                'view_order' => 10,
                'surface_keys' => [
                    'goal_summary',
                    'learning_type_confirmation',
                ],
            ],
            'analysis' => [
                'key' => 'analysis',
                'label' => '学習分析',
                'description' => '現在地・弱点・Readiness',
                'category_key' => 'analysis',
                'category_label' => '分析',
                'category_order' => 30,
                'view_order' => 10,
                'surface_keys' => [
                    'current_state',
                    'readiness',
                    'biggest_gap',
                    'weaknesses',
                    'recent_results',
                    'scope_coverage',
                ],
            ],
            'history' => [
                'key' => 'history',
                'label' => '履歴',
                'description' => 'Decision・Action・State',
                'category_key' => 'history',
                'category_label' => '記録',
                'category_order' => 40,
                'view_order' => 10,
                'surface_keys' => [],
            ],
        ];
    }

    public function selected(Request $request): string
    {
        $surface = mb_strtolower(trim((string) $request->query(
            'surface',
            self::DEFAULT_VIEW,
        )));

        return array_key_exists($surface, $this->definitions())
            ? $surface
            : self::DEFAULT_VIEW;
    }

    /**
     * @return array<string,mixed>
     */
    public function definition(string $key): array
    {
        return $this->definitions()[$key]
            ?? $this->definitions()[self::DEFAULT_VIEW];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function options(): array
    {
        return collect($this->definitions())
            ->sortBy([
                ['category_order', 'asc'],
                ['view_order', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $composition
     * @return array<string,mixed>
     */
    public function project(
        array $composition,
        string $view,
    ): array {
        $definition = $this->definition($view);
        $allowed = array_flip(
            (array) ($definition['surface_keys'] ?? []),
        );

        $surfaces = collect(
            data_get($composition, 'surfaces', []),
        )
            ->filter(fn ($surface) =>
                is_array($surface)
                && isset($allowed[(string) ($surface['key'] ?? '')])
            )
            ->values()
            ->all();

        return [
            ...$composition,
            'surfaces' => $surfaces,
        ];
    }
}
