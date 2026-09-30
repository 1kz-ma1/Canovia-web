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
                'satellite_plan', 'satellite_tool', 'satellite_reflection', 'satellite_collaboration' => 'is-personalized-satellite',
                'task', 'evidence' => $isReflectionMode && $isHierarchyLevel ? 'is-hierarchy-node is-reflection-node' : '',
                default => '',
            };
            $visualKind = \App\Support\MapNodeVisualGrammar::kind($node);
            $presentation = \App\Support\MapNodePresentation::for($node);
            $presentationKind = (string) ($presentation['kind'] ?? 'container');
            $presentationLabel = (string) ($presentation['label'] ?? $node['label']);
            $presentationEyebrow = $presentation['eyebrow'] ?? null;
            $presentationSubtitle = $presentation['subtitle'] ?? null;
            $isLeafPresentation = $presentationKind === 'leaf';
            $hasFocusFallback = filled($node['available_action'] ?? null);
            $directNavigation = $node['direct_navigation'] ?? null;
            $directNavigationKind = (string) data_get($directNavigation, 'kind', 'classic');
            $isZoomNavigation = str_starts_with($directNavigationKind, 'zoom-');
            $isSatelliteNavigation = $directNavigationKind === 'satellite';
            $isExternalNavigation = $directNavigationKind === 'external';
            $isDirectNavigation = $directNavigationKind === 'direct';
            $zoomDirection = $directNavigationKind === 'zoom-out' ? 'out' : 'in';
            $usesDirectBody = filled(data_get($directNavigation, 'url'))
                && (! $isLeafPresentation || $isSatelliteNavigation)
                && (($isZoomNavigation && $zoomDirection === 'in')
                    || $isSatelliteNavigation
                    || $isExternalNavigation
                    || $isDirectNavigation);
            $nodeEntryMode = $usesDirectBody
                ? (($isZoomNavigation && $zoomDirection === 'in') ? 'semantic' : 'direct')
                : 'focus';
            $personalization = is_array($node['personalization'] ?? null)
                ? $node['personalization']
                : null;
            $personalizationStrength = (string) ($personalization['strength'] ?? '');
            $personalizationPinned = (bool) ($personalization['pinned'] ?? false);
        @endphp

        <div
            class="canovia-map-node {{ $stateClass }} {{ $kindClass }} visual-{{ $visualKind }} {{ $isCenter ? 'is-map-center' : '' }} {{ filled(data_get($directNavigation, 'url')) ? 'has-direct-navigation' : '' }}"
            style="--map-x: {{ data_get($node, 'position.x', 50) }}%; --map-y: {{ data_get($node, 'position.y', 50) }}%; --node-scale: {{ (float) ($node['size_weight'] ?? 0.7) }}"
            data-map-node
            data-map-node-id="{{ $node['id'] }}"
            data-map-node-type="{{ $node['type'] }}"
            data-map-visual-kind="{{ $visualKind }}"
            data-map-position-role="{{ $node['position_role'] }}"
            data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
            data-map-is-center="{{ $isCenter ? '1' : '0' }}"
            data-map-node-entry-mode="{{ $nodeEntryMode }}"
            data-map-presentation-kind="{{ $presentationKind }}"
            data-map-x="{{ data_get($node, 'position.x', 50) }}"
            data-map-y="{{ data_get($node, 'position.y', 50) }}"
            @if ($personalization)
                data-map-personalized-node
                data-map-personalization-strength="{{ $personalizationStrength }}"
                @if ($personalizationPinned) data-map-personalization-pinned="1" @endif
            @endif
            @if ($isPrimary) aria-current="true" @endif
        >
            @if ($usesDirectBody)
                <a
                    href="{{ data_get($directNavigation, 'url') }}"
                    class="canovia-map-node-focus-link {{ $isZoomNavigation ? 'is-semantic-entry' : 'is-direct-entry' }}"
                    data-map-direct-navigation
                    data-map-action-role="{{ $isZoomNavigation ? 'zoom' : ($isSatelliteNavigation ? 'satellite' : ($isExternalNavigation ? 'external_tool' : 'direct')) }}"
                    data-map-node-id="{{ $node['id'] }}"
                    data-map-node-type="{{ $node['type'] }}"
                    data-map-position-role="{{ $node['position_role'] }}"
                    data-map-is-primary="{{ $isPrimary ? '1' : '0' }}"
                    @if ($isZoomNavigation)
                        data-map-semantic-zoom
                        data-map-zoom-direction="{{ $zoomDirection }}"
                        data-route-lock-skip
                    @endif
                    @if ($isExternalNavigation)
                        target="_blank"
                        rel="noopener noreferrer"
                    @endif
                    aria-label="{{ data_get($directNavigation, 'label', $node['label']) }}"
                >
            @elseif ($hasFocusFallback)
                <a
                    href="{{ $node['available_action'] }}"
                    class="canovia-map-node-focus-link"
                    data-map-node-focus
                    aria-label="{{ $node['label'] }}の詳細を見る"
                >
            @else
                <div class="canovia-map-node-focus-link is-static">
            @endif
                <span
                    class="canovia-map-node-glyph"
                    data-map-node-glyph
                    data-map-node-glyph-kind="{{ $visualKind }}"
                    aria-hidden="true"
                ><span class="canovia-map-node-glyph-core"></span></span>
                @if (filled($presentationEyebrow))
                    <span class="canovia-map-node-eyebrow">{{ $presentationEyebrow }}</span>
                @endif
                <span class="canovia-map-node-label">{{ $presentationLabel }}</span>
                @if (filled($presentationSubtitle))
                    <span class="canovia-map-node-subtitle">{{ $presentationSubtitle }}</span>
                @endif
            @if ($usesDirectBody || $hasFocusFallback)
                </a>
            @else
                </div>
            @endif

            @include('map.partials.node-data-layers', ['node' => $node])

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
                        data-route-lock-skip
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
                PlanとTaskができると、Planを中央にして現在のActionやEvidenceを周囲へ配置します。
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
