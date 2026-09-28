@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'Canovia Map | Canovia')

@section('content')
    @php
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $nodeIndex = $nodes->keyBy('id');
        $mapLevel = (string) ($graph['level'] ?? 'l3');
        $isIntentHub = $mapLevel === 'l0';
        $primaryNodeId = $graph['primary_node_id'] ?? null;
        $centerNodeId = $graph['center_node_id'] ?? $primaryNodeId;
        $primaryLaunch = $graph['primary_launch'] ?? null;
        $mapReturnUrl = $isIntentHub
            ? route('map.index')
            : route('map.index', ['level' => 'l3']);
    @endphp

    @include('layouts.partials.home-surface-switcher', ['activeSurface' => 'map'])

    <section
        class="canovia-map-page"
        data-canovia-map-page
        data-map-level="{{ $mapLevel }}"
        data-map-projection-key="{{ $graph['projection_key'] ?? '' }}"
        data-event-url="{{ route('behavior_events.store') }}"
    >
        <div class="canovia-map-hero canovia-map-toolbar">
            <div class="canovia-map-hero-copy">
                <div class="canovia-map-heading">
                    <p class="canovia-map-kicker">{{ $isIntentHub ? 'CANOVIA NAVIGATION' : 'L3 · EXECUTION MAP' }}</p>
                    <h1 class="canovia-map-title">Canovia Map</h1>
                    @if ($isIntentHub)
                        <p class="canovia-map-description">
                            Space Stationを中心に、計画・実行・振り返り・共同へ辿るCanovia全体のNavigation Layerです。
                        </p>
                    @endif
                </div>
                <details class="canovia-map-help">
                    <summary>Mapの見方</summary>
                    @if ($isIntentHub)
                        <p>
                            中央のSpace Stationが入力・相談のHubです。周囲のNodeは行動目的を表し、
                            Node本体でContextを確認、「開く ↗」から現在のClassic Surfaceへ直接移動できます。
                        </p>
                    @else
                        <p>
                            中央が現在のPrimary Actionです。上下はFuture / Past、左右はInput / Action。
                            Node本体でContextへFocusし、「開く ↗」があるNodeは目的地へ直接移動できます。操作はClassic Surfaceで行います。
                        </p>
                    @endif
                </details>
            </div>
            <div class="canovia-map-hero-actions">
                @if ($isIntentHub)
                    <a href="{{ route('map.index', ['level' => 'l3']) }}" class="btn-primary">実行Mapへ</a>
                    <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
                    <a href="{{ route('my_plans.index') }}" class="btn-secondary">計画一覧</a>
                @else
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
                    <a href="{{ route('map.index') }}" class="btn-secondary">Canovia全体</a>
                    <a href="{{ route('roadmap.index') }}" class="btn-secondary canovia-map-roadmap-link">Roadmap</a>
                @endif
            </div>
        </div>

        <div class="canovia-map-update-status hidden" data-map-update-status role="status" aria-live="polite">
            <span class="canovia-map-update-status-mark" aria-hidden="true">✦</span>
            <span>Mapを最新の状態へ更新しました</span>
        </div>

        <div class="canovia-map-workspace" data-map-workspace>
            <div class="canovia-map-shell {{ $isIntentHub ? 'is-intent-hub' : 'is-execution-map' }}" data-canovia-map data-map-level="{{ $mapLevel }}">
                @unless ($isIntentHub)
                    <span class="canovia-map-axis-label is-future">Future</span>
                    <span class="canovia-map-axis-label is-past">Past</span>
                    <span class="canovia-map-axis-label is-input">Input</span>
                    <span class="canovia-map-axis-label is-action">Action</span>
                @endunless

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
                                default => '',
                            };
                            $kindClass = match ($node['type'] ?? '') {
                                'space_station' => 'is-space-station',
                                'intent' => 'is-intent-node',
                                default => '',
                            };
                            $hasFocusFallback = filled($node['available_action'] ?? null);
                            $directNavigation = $node['direct_navigation'] ?? null;
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
                                    class="canovia-map-node-direct-open"
                                    data-map-direct-open
                                    data-map-direct-navigation
                                    data-map-action-role="direct"
                                    data-map-node-id="{{ $node['id'] }}"
                                    data-map-node-type="{{ $node['type'] }}"
                                    data-map-position-role="{{ $node['position_role'] }}"
                                    data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
                                    title="{{ data_get($directNavigation, 'label', '開く') }}"
                                    aria-label="{{ data_get($directNavigation, 'label', '開く') }}"
                                >開く ↗</a>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="canovia-map-empty">
                        <p class="canovia-map-kicker">MAP IS READY</p>
                        <h2 class="mt-2 text-lg font-black text-slate-50">まだMapに置くActionがありません</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            PlanとTaskができると、Canoviaが現在のActionを中央へ配置します。
                        </p>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            <a href="{{ route('plans.create') }}" class="btn-primary">目標・Planを作る</a>
                            <a href="{{ route('inbox.index') }}" class="btn-secondary">Inboxを開く</a>
                        </div>
                    </div>
                @endif
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
                @php($surface = $node['classic_surface'] ?? [])
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
                                        <a
                                            href="{{ $action['url'] }}"
                                            class="{{ ($action['primary'] ?? false) ? 'btn-primary' : 'btn-secondary' }} w-full justify-center"
                                            data-map-classic-action
                                            data-map-action-role="{{ ($action['primary'] ?? false) ? 'primary' : 'secondary' }}"
                                        >{{ $action['label'] }}</a>
                                    @endforeach
                                </div>
                                @auth
                                    @if (! $isIntentHub && (bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
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
        </div>

        <p class="canovia-map-note">
            @if ($isIntentHub)
                L0はCanovia全体へ辿るための固定Navigation Layerです。おすすめはNodeの存在を決めず、
                今後AttentionやPersonalized Satelliteとして強調へ反映します。
            @else
                Focus中もMap自体はContextの理解に専念します。Classic Surfaceから実行画面へ移動したあとは、
                Mapへ戻った時だけ最新状態を確認し、Primary ActionやEvidenceなどに意味のある差がある場合だけ静かに再配置します。
            @endif
        </p>
    </section>
@endsection
