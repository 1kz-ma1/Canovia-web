@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'Canovia Map | Canovia')

@section('content')
    @php
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $nodeIndex = $nodes->keyBy('id');
        $mapLevel = (string) ($graph['level'] ?? 'l3');
        $isIntentHub = $mapLevel === 'l0';
        $isDomainLevel = $mapLevel === 'l1';
        $isPlanLevel = $mapLevel === 'l2';
        $isExecutionLevel = $mapLevel === 'l3';
        $isHierarchyLevel = $isDomainLevel || $isPlanLevel;
        $isCollaborationMode = (bool) ($graph['collaboration_mode'] ?? false);
        $primaryNodeId = $graph['primary_node_id'] ?? null;
        $centerNodeId = $graph['center_node_id'] ?? $primaryNodeId;
        $primaryLaunch = $graph['primary_launch'] ?? null;
        $hierarchy = is_array($graph['hierarchy'] ?? null) ? $graph['hierarchy'] : [];
        $mapReturnUrl = request()->getRequestUri();
        $currentContextLabel = (string) ($hierarchy['current_label'] ?? ($isIntentHub ? 'Canovia Map' : 'Canovia Map'));
        $heroKicker = match (true) {
            $mapLevel === 'l0' => 'L0 · CANOVIA NAVIGATION',
            $mapLevel === 'l1' && $isCollaborationMode => 'L1 · COLLABORATION CONTEXT',
            $mapLevel === 'l2' && $isCollaborationMode => 'L2 · COLLABORATION ITEMS',
            $mapLevel === 'l1' => 'L1 · DOMAIN MAP',
            $mapLevel === 'l2' => 'L2 · PLAN MAP',
            default => 'L3 · EXECUTION MAP',
        };
    @endphp

    <section
        class="canovia-map-page"
        data-canovia-map-page
        data-map-level="{{ $mapLevel }}"
        data-map-hierarchy-depth="{{ (int) ($hierarchy['depth'] ?? 0) }}"
        data-map-projection-key="{{ $graph['projection_key'] ?? '' }}"
        data-event-url="{{ route('behavior_events.store') }}"
    >
        <div class="canovia-map-hero canovia-map-toolbar canovia-map-fullscreen-topbar" data-map-fullscreen-topbar>
            <div class="canovia-map-hero-copy">
                <div class="canovia-map-heading">
                    <p class="canovia-map-kicker">{{ $heroKicker }}</p>
                    <h1 class="canovia-map-title">{{ $currentContextLabel }}</h1>

                    @if ($isIntentHub)
                        <p class="canovia-map-description">
                            Space Stationを中心に、計画・実行・振り返り・共同へ辿るCanovia全体のNavigation Layerです。
                        </p>
                    @elseif ($isDomainLevel && $isCollaborationMode)
                        <p class="canovia-map-description">
                            Shared Planや外部Toolをサービス別ではなく、自分のAction・レビュー待ち・相手待ち・外部確認という目的から辿ります。
                        </p>
                    @elseif ($isPlanLevel && $isCollaborationMode)
                        <p class="canovia-map-description">
                            {{ $hierarchy['collaboration_context_label'] ?? '共同Context' }}に該当するTask / Artifactを確認し、必要なExecutionまたは外部Toolへ進みます。
                        </p>
                    @elseif ($isDomainLevel)
                        <p class="canovia-map-description">
                            {{ $hierarchy['intent_label'] ?? '計画' }}のContextを保ったまま、Planが属する領域へSemantic Zoomします。
                        </p>
                    @elseif ($isPlanLevel)
                        <p class="canovia-map-description">
                            {{ $hierarchy['domain_label'] ?? 'Domain' }}の中からPlanを選び、そのExecution Contextへ潜ります。
                        </p>
                    @endif
                </div>

                <details class="canovia-map-help">
                    <summary>Mapの見方</summary>
                    @if ($isIntentHub)
                        <p>
                            中央のSpace Stationが入力・相談のHubです。周囲のIntentからDomain → Plan → Executionへ潜れます。
                        </p>
                    @elseif ($isHierarchyLevel && $isCollaborationMode)
                        <p>
                            中央は共同作業の目的Context、周囲はその目的に該当するShared Plan / Artifactです。
                            GitHub等の外部状態は推測せず、Canoviaで明示された状態と確認先だけを表示します。
                        </p>
                    @elseif ($isHierarchyLevel)
                        <p>
                            中央はひとつ上のContext、周囲はその子Contextです。「潜る ↘」で内側へ、「戻る」で外側へ移動します。
                            Space Stationは右下に固定され、現在のContextを離れずCaptureやAIを開けます。
                        </p>
                    @else
                        <p>
                            中央が現在のPrimary Actionです。上下はFuture / Past、左右はInput / Action。
                            Space Stationは右下の固定Dockからいつでも開けます。
                        </p>
                    @endif
                </details>
            </div>

            <div class="canovia-map-fullscreen-surface-switch">
                @include('layouts.partials.home-surface-switcher', ['activeSurface' => 'map'])
            </div>

            <div class="canovia-map-hero-actions">
                @if ($isIntentHub)
                    <a
                        href="{{ route('map.index', ['level' => 'l1', 'intent' => 'execution']) }}"
                        class="btn-primary"
                        data-map-semantic-zoom
                        data-map-zoom-direction="in"
                    >実行へ潜る</a>
                    <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
                    <a href="{{ route('my_plans.index') }}" class="btn-secondary">計画一覧</a>
                @elseif ($isExecutionLevel)
                    @if ($primaryNodeId && filled(data_get($primaryLaunch, 'url')))
                        <a
                            href="{{ data_get($primaryLaunch, 'url') }}"
                            class="btn-primary canovia-map-primary-launch"
                            data-map-classic-action
                            data-map-direct-primary-launch
                            data-map-action-role="primary"
                            data-map-node-id="{{ $primaryNodeId }}"
                            data-map-node-type="task"
                            data-map-position-role="now"
                            data-map-is-primary="1"
                            title="{{ data_get($primaryLaunch, 'label', 'Primary Actionを進める') }}"
                            aria-label="{{ data_get($primaryLaunch, 'label', 'Primary Actionを進める') }}"
                        >そのまま進める</a>
                        <button type="button" class="btn-secondary canovia-map-now-button" data-map-primary-focus>
                            Contextを見る
                        </button>
                    @elseif ($primaryNodeId)
                        <button type="button" class="btn-primary canovia-map-now-button" data-map-primary-focus>
                            今やることを見る
                        </button>
                    @endif
                    <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
                    @if (filled($hierarchy['parent_url'] ?? null))
                        <a
                            href="{{ $hierarchy['parent_url'] }}"
                            class="btn-secondary"
                            data-map-semantic-zoom
                            data-map-zoom-direction="out"
                        >Plan Mapへ戻る</a>
                    @else
                        <a href="{{ route('map.index') }}" class="btn-secondary">Canovia全体</a>
                    @endif
                    <a href="{{ route('roadmap.index') }}" class="btn-secondary canovia-map-roadmap-link">Roadmap</a>
                @else
                    @if (filled($hierarchy['parent_url'] ?? null))
                        <a
                            href="{{ $hierarchy['parent_url'] }}"
                            class="btn-secondary"
                            data-map-semantic-zoom
                            data-map-zoom-direction="out"
                        >ひとつ外へ戻る</a>
                    @endif
                    <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
                    <a href="{{ route('my_plans.index') }}" class="btn-secondary">Classic Plans</a>
                @endif
            </div>

            <div class="canovia-map-fullscreen-hierarchy">
                @include('map.partials.hierarchy-navigation')
            </div>
        </div>

        <div class="canovia-map-update-status hidden" data-map-update-status role="status" aria-live="polite">
            <span class="canovia-map-update-status-mark" aria-hidden="true">✦</span>
            <span>Mapを最新の状態へ更新しました</span>
        </div>

        <div class="canovia-map-workspace" data-map-workspace>
            <div
                class="canovia-map-shell {{ $isIntentHub ? 'is-intent-hub' : ($isHierarchyLevel ? 'is-hierarchy-map' : 'is-execution-map') }}{{ $isCollaborationMode ? ' is-collaboration-map' : '' }}"
                data-canovia-map
                data-map-level="{{ $mapLevel }}"
            >
                @if ($isExecutionLevel)
                    <span class="canovia-map-axis-label is-future">Future</span>
                    <span class="canovia-map-axis-label is-past">Past</span>
                    <span class="canovia-map-axis-label is-input">Input</span>
                    <span class="canovia-map-axis-label is-action">Action</span>
                @endif

                <div class="canovia-map-scene" data-map-scene>
                @if ($nodes->isNotEmpty())
                    <svg class="canovia-map-edges" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        @foreach ($edges as $edge)
                            @php
                                $source = $nodeIndex->get($edge['source']);
                                $target = $nodeIndex->get($edge['target']);
                            @endphp
                            @if ($source && $target)
                                <line
                                    class="canovia-map-edge {{ ($edge['secondary'] ?? false) ? 'is-secondary' : '' }}"
                                    x1="{{ data_get($source, 'position.x') }}"
                                    y1="{{ data_get($source, 'position.y') }}"
                                    x2="{{ data_get($target, 'position.x') }}"
                                    y2="{{ data_get($target, 'position.y') }}"
                                    data-map-edge
                                    data-map-edge-source="{{ $edge['source'] }}"
                                    data-map-edge-target="{{ $edge['target'] }}"
                                    data-map-edge-relation="{{ $edge['relation'] }}"
                                    style="--edge-strength: {{ (float) ($edge['strength'] ?? 0.5) }}"
                                />
                            @endif
                        @endforeach
                    </svg>

                    @foreach ($nodes as $node)
                        @php
                            $isPrimary = $node['id'] === $primaryNodeId;
                            $isCenter = $node['id'] === $centerNodeId;
                            $stateClass = match ($node['state'] ?? '') {
                                'primary' => 'is-primary',
                                'action' => 'is-action',
                                'input' => 'is-input',
                                'past' => 'is-past',
                                'hub' => 'is-hub',
                                'intent' => 'is-intent',
                                'hierarchy-parent' => 'is-hierarchy-parent',
                                'hierarchy-child' => 'is-hierarchy-child',
                                'satellite' => 'is-satellite',
                                default => '',
                            };
                            $kindClass = match ($node['type'] ?? '') {
                                'space_station' => 'is-space-station',
                                'intent' => 'is-intent-node',
                                'domain', 'intent_context', 'plan' => 'is-hierarchy-node',
                                'collaboration_hub', 'collaboration_context', 'collaboration_item' => 'is-hierarchy-node is-collaboration-node',
                                'satellite_plan', 'satellite_tool' => 'is-personalized-satellite',
                                default => '',
                            };
                            $hasFocusFallback = filled($node['available_action'] ?? null);
                            $directNavigation = $node['direct_navigation'] ?? null;
                            $directNavigationKind = (string) data_get($directNavigation, 'kind', 'classic');
                            $isZoomNavigation = str_starts_with($directNavigationKind, 'zoom-');
                            $isSatelliteNavigation = $directNavigationKind === 'satellite';
                            $isExternalNavigation = $directNavigationKind === 'external';
                            $zoomDirection = $directNavigationKind === 'zoom-out' ? 'out' : 'in';
                        @endphp

                        <div
                            class="canovia-map-node {{ $stateClass }} {{ $kindClass }} {{ $isCenter ? 'is-map-center' : '' }} {{ filled(data_get($directNavigation, 'url')) ? 'has-direct-navigation' : '' }}"
                            style="--map-x: {{ data_get($node, 'position.x', 50) }}%; --map-y: {{ data_get($node, 'position.y', 50) }}%; --node-scale: {{ (float) ($node['size_weight'] ?? 0.7) }}"
                            data-map-node
                            data-map-node-id="{{ $node['id'] }}"
                            data-map-node-type="{{ $node['type'] }}"
                            data-map-position-role="{{ $node['position_role'] }}"
                            data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
                            data-map-is-center="{{ $isCenter ? '1' : '0' }}"
                            data-map-x="{{ data_get($node, 'position.x', 50) }}"
                            data-map-y="{{ data_get($node, 'position.y', 50) }}"
                            @if ($isPrimary) aria-current="true" @endif
                        >
                            @if ($hasFocusFallback)
                                <a
                                    href="{{ $node['available_action'] }}"
                                    class="canovia-map-node-focus-link"
                                    data-map-node-focus
                                    aria-label="{{ $node['label'] }}のContextを見る"
                                >
                            @else
                                <div class="canovia-map-node-focus-link is-static">
                            @endif
                                <span class="canovia-map-node-eyebrow">{{ $node['eyebrow'] }}</span>
                                <span class="canovia-map-node-label">{{ $node['label'] }}</span>
                                @if (filled($node['subtitle'] ?? null))
                                    <span class="canovia-map-node-subtitle">{{ $node['subtitle'] }}</span>
                                @endif
                                @if ($hasFocusFallback)
                                    <span class="canovia-map-node-action">Contextを見る →</span>
                                @endif
                            @if ($hasFocusFallback)
                                </a>
                            @else
                                </div>
                            @endif

                            @if (filled(data_get($directNavigation, 'url')))
                                <a
                                    href="{{ data_get($directNavigation, 'url') }}"
                                    class="canovia-map-node-direct-open {{ $isZoomNavigation ? 'is-semantic-zoom' : '' }} {{ $isExternalNavigation ? 'is-external-tool' : '' }}"
                                    data-map-direct-open
                                    data-map-direct-navigation
                                    data-map-action-role="{{ $isZoomNavigation ? 'zoom' : ($isSatelliteNavigation ? 'satellite' : ($isExternalNavigation ? 'external_tool' : 'direct')) }}"
                                    data-map-node-id="{{ $node['id'] }}"
                                    data-map-node-type="{{ $node['type'] }}"
                                    data-map-position-role="{{ $node['position_role'] }}"
                                    data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
                                    @if ($isZoomNavigation)
                                        data-map-semantic-zoom
                                        data-map-zoom-direction="{{ $zoomDirection }}"
                                    @endif
                                    @if ($isExternalNavigation)
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    @endif
                                    title="{{ data_get($directNavigation, 'label', '開く') }}"
                                    aria-label="{{ data_get($directNavigation, 'label', '開く') }}"
                                >{{ $isZoomNavigation ? ($zoomDirection === 'out' ? '戻る ↖' : '潜る ↘') : ($isSatelliteNavigation ? '移動 ↗' : ($isExternalNavigation ? '外部 ↗' : '開く ↗')) }}</a>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="canovia-map-empty">
                        <p class="canovia-map-kicker">MAP IS READY</p>
                        @if ($isExecutionLevel)
                            <h2 class="mt-2 text-lg font-black text-slate-50">まだMapに置くActionがありません</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                PlanとTaskができると、Canoviaが現在のActionを中央へ配置します。
                            </p>
                        @else
                            <h2 class="mt-2 text-lg font-black text-slate-50">まだこの階層に置くContextがありません</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Planを作るとDomain → Plan → Executionの階層としてMapへ現れます。
                            </p>
                        @endif
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            <a href="{{ route('plans.create') }}" class="btn-primary">目標・Planを作る</a>
                            <a href="{{ route('inbox.index') }}" class="btn-secondary">Inboxを開く</a>
                        </div>
                    </div>
                @endif
                </div>

                <div class="canovia-map-gesture-controls" data-map-gesture-controls aria-label="Map表示操作">
                    <button type="button" data-map-zoom-out aria-label="Mapを縮小">−</button>
                    <button type="button" data-map-view-reset aria-label="Map表示を中央へ戻す">◎</button>
                    <button type="button" data-map-zoom-in aria-label="Mapを拡大">＋</button>
                </div>

                @include('map.partials.spatial-dock')
            </div>

            <aside
                class="canovia-map-context-surface"
                data-map-context-surface
                aria-hidden="true"
                aria-live="polite"
                aria-label="選択中のContext"
            >
                <div class="canovia-map-context-card">
                    <div class="canovia-map-context-header">
                        <div>
                            <p class="canovia-map-kicker">Contextual Classic Surface</p>
                            <p class="canovia-map-context-caption">選択中のContextに必要な操作だけを表示します</p>
                        </div>
                        <div class="canovia-map-context-tools">
                            <button
                                type="button"
                                class="canovia-map-context-expand"
                                data-map-context-expand
                                aria-expanded="false"
                                aria-label="Context Surfaceの表示サイズを切り替える"
                            ><span data-map-context-expand-label>広げる</span></button>
                            <button type="button" class="canovia-map-context-close" data-map-context-close aria-label="Focusを閉じる">×</button>
                        </div>
                    </div>
                    <div data-map-context-content></div>
                </div>
            </aside>
        </div>

        <div hidden data-map-surface-templates>
            @foreach ($nodes as $node)
                @php
                    $surface = $node['classic_surface'] ?? [];
                @endphp
                @if (! empty($surface))
                    <template data-map-surface-template="{{ $node['id'] }}">
                        <section class="canovia-map-classic-content" data-map-classic-content="{{ $node['id'] }}">
                            <p class="canovia-map-classic-kind">{{ $surface['kind'] ?? $node['type'] }}</p>
                            <h2 class="canovia-map-classic-title">{{ $surface['title'] ?? $node['label'] }}</h2>
                            @if (filled($surface['summary'] ?? null))
                                <p class="canovia-map-classic-summary">{{ $surface['summary'] }}</p>
                            @endif

                            @if (! empty($surface['meta']))
                                <div class="canovia-map-classic-meta">
                                    @foreach ($surface['meta'] as $meta)
                                        <span>{{ $meta }}</span>
                                    @endforeach
                                </div>
                            @endif

                            @if ($isIntentHub && ($node['id'] ?? null) === 'intent:space-station')
                                @include('map.partials.space-station-surface')
                            @else
                                <div class="canovia-map-classic-actions">
                                    @foreach (($surface['actions'] ?? []) as $action)
                                        @php
                                            $actionNavigationKind = (string) ($action['navigation_kind'] ?? '');
                                            $actionIsZoom = str_starts_with($actionNavigationKind, 'zoom-');
                                            $actionIsExternal = $actionNavigationKind === 'external' || (bool) ($action['external'] ?? false);
                                            $actionZoomDirection = $actionNavigationKind === 'zoom-out' ? 'out' : 'in';
                                        @endphp
                                        <a
                                            href="{{ $action['url'] }}"
                                            class="{{ ($action['primary'] ?? false) ? 'btn-primary' : 'btn-secondary' }} w-full justify-center"
                                            data-map-classic-action
                                            data-map-action-role="{{ $actionIsZoom ? 'zoom' : ($actionIsExternal ? 'external_tool' : (($action['primary'] ?? false) ? 'primary' : 'secondary')) }}"
                                            @if ($actionIsExternal)
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            @endif
                                            @if ($actionIsZoom)
                                                data-map-semantic-zoom
                                                data-map-zoom-direction="{{ $actionZoomDirection }}"
                                            @endif
                                        >{{ $action['label'] }}</a>
                                    @endforeach
                                </div>
                                @auth
                                    @if ($isExecutionLevel && (bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
                                        <div class="canovia-map-companion-entry">
                                            <div>
                                                <p class="canovia-map-companion-kicker">COMPANION</p>
                                                <p class="canovia-map-companion-copy">このNodeと直接つながるContextを引き継いで相談します。</p>
                                            </div>
                                            <form method="POST" action="{{ route('companion.entry') }}" data-mutation-once data-map-companion-form>
                                                @csrf
                                                <input type="hidden" name="entry_type" value="map">
                                                <input type="hidden" name="map_node_id" value="{{ $node['id'] }}">
                                                <input type="hidden" name="source_path" value="{{ $mapReturnUrl }}#focus={{ rawurlencode($node['id']) }}">
                                                <input type="hidden" name="source_route" value="map.index">
                                                <button type="submit" class="btn-secondary w-full justify-center" data-map-classic-action>
                                                    ✦ このContextについて相談
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                @endauth
                            @endif
                        </section>
                    </template>
                @endif
            @endforeach

            @if (is_array($graph['spatial_dock'] ?? null))
                <template data-map-global-surface-template="space-station">
                    <section class="canovia-map-classic-content" data-map-global-classic-content="space-station">
                        <p class="canovia-map-classic-kind">Spatial Dock</p>
                        <h2 class="canovia-map-classic-title">Space Station</h2>
                        <p class="canovia-map-classic-summary">
                            今見ている{{ $hierarchy['current_label'] ?? 'Context' }}を離れず、Capture・接続候補・Companionを開きます。
                        </p>
                        @include('map.partials.space-station-surface')
                    </section>
                </template>
            @endif
        </div>

        <p class="canovia-map-note">
            @if ($isIntentHub)
                L0の固定Intentは常に残ります。Personalized Satelliteは既存Contextを最大4件だけ昇格するAttention表現で、元のPlan / Toolの存在そのものは変えません。
            @elseif ($isHierarchyLevel)
                この階層のNode位置は現在の構造から決定的に投影し、保存しません。Space Station DockはLevelを跨いで同じ位置に残ります。
            @else
                Execution中も右下のSpace Stationから入力・相談へ戻れます。Classic Surfaceで操作した後は、意味のある状態差分だけ静かに再投影します。
            @endif
        </p>
    </section>
@endsection
