@extends('layouts.app')

@section('title', 'Canovia Map | Canovia')

@section('content')
    @php
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $nodeIndex = $nodes->keyBy('id');
        $primaryNodeId = $graph['primary_node_id'] ?? null;
    @endphp

    <section class="canovia-map-page" data-canovia-map-page>
        <div class="canovia-map-hero">
            <div class="canovia-map-hero-copy">
                <p class="canovia-map-kicker">Experimental · Living Goal Map</p>
                <h1 class="canovia-map-title">Canovia Map</h1>
                <p class="canovia-map-description">
                    必要な情報・機能・画面へたどり着くためのContext Mapです。
                    Mapでは「どこへ行くか」を理解し、実際の入力・編集・実行はこれまでのClassic画面で行います。
                </p>
            </div>
            <div class="canovia-map-hero-actions">
                <a href="{{ route('home') }}" class="btn-secondary">Classic Home</a>
                <a href="{{ route('roadmap.index') }}" class="btn-secondary">Roadmap</a>
            </div>
        </div>

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
                        @if ($isPrimary) aria-current="true" @endif
                    >
                        <span class="canovia-map-node-eyebrow">{{ $node['eyebrow'] }}</span>
                        <span class="canovia-map-node-label">{{ $node['label'] }}</span>
                        @if (filled($node['subtitle'] ?? null))
                            <span class="canovia-map-node-subtitle">{{ $node['subtitle'] }}</span>
                        @endif
                        @if ($tag === 'a')
                            <span class="canovia-map-node-action">Classicで開く →</span>
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

        <p class="canovia-map-note">
            V42.0では配置は決定論Policyです。ノードは自由ドラッグせず、中央=Now、上=Future、下=Past、左=Input、右=Actionの意味を固定しています。
            複雑な操作はMap内へ持ち込まず、ノードからClassic画面へ移動します。
        </p>
    </section>
@endsection
