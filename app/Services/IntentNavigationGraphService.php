<?php

namespace App\Services;

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
                subtitle: 'Capture・相談・接続候補を扱う中央ハブ',
                action: route('inbox.index'),
                attentionRole: 'space-station',
                classicSurface: $this->surface(
                    'Space Station',
                    'Space Station',
                    'テキスト・URL・スクリーンショット・PDFを受け取り、Canoviaが接続候補を示します。確定は必ずユーザーが行い、相談はCompanionへ引き継げます。',
                    [
                        $this->action('Inboxをすべて見る', route('inbox.index'), true),
                    ],
                    ['Capture', 'Companion', 'Human Review'],
                ),
            ),
            $this->node(
                id: 'intent:plan',
                type: 'intent',
                eyebrow: 'INTENT',
                label: '計画',
                subtitle: '目標・Plan・これからを整理する',
                action: route('my_plans.index'),
                attentionRole: 'intent-plan',
                classicSurface: $this->surface(
                    'Intent',
                    '計画',
                    '目標やPlanを見渡し、これから進む方向を整える領域です。',
                    [
                        $this->action('計画一覧を見る', route('my_plans.index'), true),
                        $this->action('新しいPlanを作る', route('plans.create')),
                    ],
                    ['Goal', 'Plan', 'Roadmap'],
                ),
            ),
            $this->node(
                id: 'intent:execution',
                type: 'intent',
                eyebrow: 'INTENT',
                label: '実行',
                subtitle: '今やることと実行Contextへ進む',
                action: route('map.index', ['level' => 'l3']),
                attentionRole: 'intent-execution',
                classicSurface: $this->surface(
                    'Intent',
                    '実行',
                    '現在のPlan・Task・Tool・EvidenceをつないだExecution Mapへ入り、今のContextで行動します。',
                    [
                        $this->action('Execution Mapへ入る', route('map.index', ['level' => 'l3']), true),
                        $this->action('今日の実行導線を開く', route('navigation.index')),
                    ],
                    ['Task', 'Tool', 'Current Context'],
                ),
            ),
            $this->node(
                id: 'intent:reflection',
                type: 'intent',
                eyebrow: 'INTENT',
                label: '振り返り',
                subtitle: '実績・Evidence・過去の流れを見る',
                action: route('timeline.index'),
                attentionRole: 'intent-reflection',
                classicSurface: $this->surface(
                    'Intent',
                    '振り返り',
                    '実績・Evidence・成果を振り返り、次の判断につながるContextを確認する領域です。',
                    [
                        $this->action('Timelineを見る', route('timeline.index'), true),
                        $this->action('実績を見る', route('achievements.index')),
                    ],
                    ['Evidence', 'Timeline', 'Achievements'],
                ),
            ),
            $this->node(
                id: 'intent:collaboration',
                type: 'intent',
                eyebrow: 'INTENT',
                label: '共同',
                subtitle: 'Shared Planと人との作業へ進む',
                action: route('my_plans.index'),
                attentionRole: 'intent-collaboration',
                classicSurface: $this->surface(
                    'Intent',
                    '共同',
                    'Shared Planやメンバーとの作業を確認する領域です。外部共同Toolの再投影はV44.5でここへ接続します。',
                    [
                        $this->action('共同Planを探す', route('my_plans.index'), true),
                        $this->action('共同Planを作る', route('plans.create')),
                    ],
                    ['Shared Plan', 'Members', 'External Tools'],
                ),
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
    private function action(string $label, string $url, bool $primary = false): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
        ];
    }
}
