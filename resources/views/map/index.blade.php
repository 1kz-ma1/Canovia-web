@extends('layouts.app')

@section('title', 'Canovia Map | Canovia')

@section('content')
    @php
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $nodeIndex = $nodes->keyBy('id');
        $primaryNodeId = $graph['primary_node_id'] ?? null;
    @endphp

    <section
        class="canovia-map-page"
        data-canovia-map-page
        data-map-projection-key="{{ $graph['projection_key'] ?? '' }}"
        data-event-url="{{ route('behavior_events.store') }}"
    >
        <div class="canovia-map-hero canovia-map-toolbar">
            <div class="canovia-map-hero-copy">
                <div class="canovia-map-heading">
                    <p class="canovia-map-kicker">Living Goal Map</p>
                    <h1 class="canovia-map-title">Canovia Map</h1>
                </div>
                <details class="canovia-map-help">
                    <summary>Mapの見方</summary>
                    <p>
                        中央が現在のPrimary Actionです。上下はFuture / Past、左右はInput / Action。
                        Nodeを選ぶと直接関係するContextだけへFocusし、操作はClassic Surfaceで行います。
                    </p>
                </details>
            </div>
            <div class="canovia-map-hero-actions">
                @if ($primaryNodeId)
                    <button type="button" class="btn-primary canovia-map-now-button" data-map-primary-focus>
                        今やることを見る
                    </button>
                @endif
                <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
                <a href="{{ route('home') }}" class="btn-secondary" data-map-home-fallback>Classic Home</a>
                <a href="{{ route('roadmap.index') }}" class="btn-secondary canovia-map-roadmap-link">Roadmap</a>
            </div>
        </div>

        <div class="canovia-map-update-status hidden" data-map-update-status role="status" aria-live="polite">
            <span class="canovia-map-update-status-mark" aria-hidden="true">✦</span>
            <span>Mapを最新の状態へ更新しました</span>
        </div>

        <div class="canovia-map-workspace" data-map-workspace>
            <div class="canovia-map-shell" data-canovia-map>
                <span class="canovia-map-axis-label is-future">Future</span>
                <span class="canovia-map-axis-label is-past">Past</span>
                <span class="canovia-map-axis-label is-input">Input</span>
                <span class="canovia-map-axis-label is-action">Action</span>

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
                            $stateClass = match ($node['state'] ?? '') {
                                'primary' => 'is-primary',
                                'action' => 'is-action',
                                'input' => 'is-input',
                                'past' => 'is-past',
                                default => '',
                            };
                            $tag = filled($node['available_action'] ?? null) ? 'a' : 'div';
                        @endphp

                        <{{ $tag }}
                            @if ($tag === 'a') href="{{ $node['available_action'] }}" @endif
                            class="canovia-map-node {{ $stateClass }}"
                            style="--map-x: {{ data_get($node, 'position.x', 50) }}%; --map-y: {{ data_get($node, 'position.y', 50) }}%; --node-scale: {{ (float) ($node['size_weight'] ?? 0.7) }}"
                            data-map-node
                            data-map-node-id="{{ $node['id'] }}"
                            data-map-node-type="{{ $node['type'] }}"
                            data-map-position-role="{{ $node['position_role'] }}"
                            data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
                            data-map-x="{{ data_get($node, 'position.x', 50) }}"
                            data-map-y="{{ data_get($node, 'position.y', 50) }}"
                            @if ($isPrimary) aria-current="true" @endif
                        >
                            <span class="canovia-map-node-eyebrow">{{ $node['eyebrow'] }}</span>
                            <span class="canovia-map-node-label">{{ $node['label'] }}</span>
                            @if (filled($node['subtitle'] ?? null))
                                <span class="canovia-map-node-subtitle">{{ $node['subtitle'] }}</span>
                            @endif
                            @if ($tag === 'a')
                                <span class="canovia-map-node-action">選択して操作 →</span>
                            @endif
                        </{{ $tag }}>
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
                                @if ((bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
                                    <div class="canovia-map-companion-entry">
                                        <div>
                                            <p class="canovia-map-companion-kicker">COMPANION</p>
                                            <p class="canovia-map-companion-copy">このNodeと直接つながるContextを引き継いで相談します。</p>
                                        </div>
                                        <form method="POST" action="{{ route('companion.entry') }}" data-mutation-once data-map-companion-form>
                                            @csrf
                                            <input type="hidden" name="entry_type" value="map">
                                            <input type="hidden" name="map_node_id" value="{{ $node['id'] }}">
                                            <input type="hidden" name="source_path" value="{{ route('map.index') }}#focus={{ rawurlencode($node['id']) }}">
                                            <input type="hidden" name="source_route" value="map.index">
                                            <button type="submit" class="btn-secondary w-full justify-center" data-map-classic-action>
                                                ✦ このContextについて相談
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            @endauth
                        </section>
                    </template>
                @endif
            @endforeach
        </div>

        <p class="canovia-map-note">
            Focus中もMap自体はContextの理解に専念します。Classic Surfaceから実行画面へ移動したあとは、
            Mapへ戻った時だけ最新状態を確認し、Primary ActionやEvidenceなどに意味のある差がある場合だけ静かに再配置します。
        </p>
    </section>
@endsection
