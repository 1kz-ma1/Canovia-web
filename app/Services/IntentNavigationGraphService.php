<?php

namespace App\Services;

use App\Enums\MapLevel;

final class IntentNavigationGraphService
{
    /**
     * Build the fixed L0 semantic navigation graph.
     *
     * L0 nodes describe stable user intents. They do not depend on recommendation
     * results and carry no visual attention fields such as position or importance.
     *
     * @return array{
     *     nodes:\Illuminate\Support\Collection<int,array<string,mixed>>,
     *     edges:\Illuminate\Support\Collection<int,array<string,mixed>>
     * }
     */
    public function build(): array
    {
        $nodes = collect([
            $this->node(
                id: 'intent:space-station',
                type: 'space_station',
                eyebrow: 'SPACE STATION',
                label: 'Space Station',
                subtitle: '入力・解釈・接続・Actionを扱う中央ハブ',
                action: route('inbox.index'),
                attentionRole: 'space-station',
                classicSurface: $this->surface(
                    'Space Station',
                    'Space Station',
                    'テキスト・URL・スクリーンショット・PDFを受け取り、入力 → 解釈 → 接続候補 → Actionを1つのFlowで扱います。接続確定は必ずユーザーが行い、整理せずCompanionへ相談することもできます。',
                    [
                        $this->action('Inboxをすべて見る', route('inbox.index'), true),
                    ],
                    ['Input', 'Interpret', 'Connect', 'Action'],
                ),
            ),
            $this->intentNode(
                key: 'plan',
                label: '計画',
                subtitle: '目標・Plan・これからを整理する',
                summary: '目標やPlanを見渡し、これから進む方向を整える領域です。',
                fallbackLabel: '計画一覧を開く',
                fallbackUrl: route('my_plans.index'),
                meta: ['Goal', 'Plan', 'Roadmap'],
            ),
            $this->intentNode(
                key: 'execution',
                label: '実行',
                subtitle: '今やることと実行Contextへ進む',
                summary: 'Planを直接選び、現在のExecution ContextへSemantic Zoomします。',
                fallbackLabel: '今日の実行導線を開く',
                fallbackUrl: route('navigation.index'),
                meta: ['Task', 'Tool', 'Current Context'],
            ),
            $this->intentNode(
                key: 'reflection',
                label: '振り返り',
                subtitle: '実績・Evidence・過去の流れを見る',
                summary: 'Planの構造を保ったまま実績・Evidence・成果へ辿る入口です。',
                fallbackLabel: 'Timelineを開く',
                fallbackUrl: route('timeline.index'),
                meta: ['Evidence', 'Timeline', 'Achievements'],
            ),
            $this->intentNode(
                key: 'collaboration',
                label: '共同',
                subtitle: 'Shared Planと人との作業へ進む',
                summary: 'Shared Plan・担当Artifact・外部Toolを、サービス別ではなく「次に何をするか」という共同作業Contextから辿ります。',
                fallbackLabel: '共同Planを探す',
                fallbackUrl: route('my_plans.index'),
                meta: ['My Action', 'Review', 'Waiting', 'External Tools'],
            ),
        ]);

        $edges = collect([
            $this->edge('intent:space-station', 'intent:plan', 'routes_to', 'station-plan'),
            $this->edge('intent:space-station', 'intent:execution', 'routes_to', 'station-execution'),
            $this->edge('intent:space-station', 'intent:reflection', 'routes_to', 'station-reflection'),
            $this->edge('intent:space-station', 'intent:collaboration', 'routes_to', 'station-collaboration'),
        ]);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @param array<int,string> $meta
     * @return array<string,mixed>
     */
    private function intentNode(
        string $key,
        string $label,
        string $subtitle,
        string $summary,
        string $fallbackLabel,
        string $fallbackUrl,
        array $meta,
    ): array {
        $zoomUrl = route('map.index', [
            'level' => MapLevel::Domain->value,
            'intent' => $key,
        ]);

        return $this->node(
            id: 'intent:'.$key,
            type: 'intent',
            eyebrow: 'INTENT',
            label: $label,
            subtitle: $subtitle,
            action: $zoomUrl,
            attentionRole: 'intent-'.$key,
            navigationKind: 'zoom-in',
            classicSurface: $this->surface(
                'Intent',
                $label,
                $summary,
                [
                    $this->action($key === 'execution' ? 'Planを選ぶ' : '領域へ入る', $zoomUrl, true, 'zoom-in'),
                    $this->action($fallbackLabel, $fallbackUrl),
                ],
                $meta,
            ),
        );
    }

    /**
     * @param array<string,mixed> $classicSurface
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $type,
        string $eyebrow,
        string $label,
        string $subtitle,
        string $action,
        string $attentionRole,
        array $classicSurface,
        ?string $navigationKind = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'entity_id' => null,
            'eyebrow' => $eyebrow,
            'label' => $label,
            'subtitle' => $subtitle,
            'available_action' => $action,
            'classic_surface' => $classicSurface,
            'attention_role' => $attentionRole,
            'navigation_kind' => $navigationKind,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(string $source, string $target, string $relation, string $attentionRole): array
    {
        return [
            'source' => $source,
            'target' => $target,
            'relation' => $relation,
            'secondary' => false,
            'attention_role' => $attentionRole,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<int,string> $meta
     * @return array<string,mixed>
     */
    private function surface(
        string $kind,
        string $title,
        string $summary,
        array $actions,
        array $meta = [],
    ): array {
        return [
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'actions' => $actions,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function action(
        string $label,
        string $url,
        bool $primary = false,
        ?string $navigationKind = null,
    ): array {
        return array_filter([
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
            'navigation_kind' => $navigationKind,
        ], fn ($value) => $value !== null);
    }
}
