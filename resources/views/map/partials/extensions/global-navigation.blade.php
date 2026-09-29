@php
    $globalHierarchy = is_array($graph['hierarchy'] ?? null) ? $graph['hierarchy'] : [];
    $globalDepth = max(0, min(3, (int) ($globalHierarchy['depth'] ?? 0)));
    $globalCrumbs = collect($globalHierarchy['breadcrumbs'] ?? []);
    $globalHomeUrl = route('map.index');
    $globalIsHome = $mapLevel === 'l0';

    $globalMode = match (true) {
        (bool) ($graph['collaboration_mode'] ?? false) => 'collaboration',
        (bool) ($graph['reflection_mode'] ?? false) => 'reflection',
        default => 'standard',
    };

    $globalDepthLabels = match ($globalMode) {
        'collaboration' => ['全体', '共同', '目的', '実行'],
        'reflection' => ['全体', '振り返り', '記録', '詳細'],
        default => ['全体', '領域', 'Plan', '実行'],
    };

    $globalPathCrumbs = $globalCrumbs
        ->filter(fn ($crumb, $index) => $index > 0 && filled($crumb['label'] ?? null))
        ->values();
@endphp

<nav
    class="canovia-map-global-navigation"
    data-map-global-navigation
    data-map-global-depth="{{ $globalDepth }}"
    aria-label="Map現在地"
>
    <div class="canovia-map-global-navigation-main">
        @if ($globalIsHome)
            <span
                class="canovia-map-global-home is-current"
                aria-current="page"
                title="Canovia全体"
            >
                <span aria-hidden="true">⌂</span>
                <span>全体</span>
            </span>
        @else
            <a
                href="{{ $globalHomeUrl }}"
                class="canovia-map-global-home"
                data-map-semantic-zoom
                data-map-zoom-direction="out"
                data-route-lock-skip
                data-map-global-home
                aria-label="Canovia全体へ戻る"
                title="Canovia全体へ戻る"
            >
                <span aria-hidden="true">⌂</span>
                <span>全体へ</span>
            </a>
        @endif

        <div class="canovia-map-global-location">
            <span class="canovia-map-global-location-caption">現在地</span>
            <strong>{{ $currentContextLabel }}</strong>
        </div>
    </div>

    <div class="canovia-map-global-path" aria-label="上位の場所">
        @if ($globalIsHome)
            <span class="canovia-map-global-path-current" aria-current="page">Canovia</span>
        @else
            <a
                href="{{ $globalHomeUrl }}"
                class="canovia-map-global-path-link"
                data-map-semantic-zoom
                data-map-zoom-direction="out"
                data-route-lock-skip
            >Canovia</a>
        @endif

        @foreach ($globalPathCrumbs as $crumb)
            <span class="canovia-map-global-path-separator" aria-hidden="true">›</span>
            @if (filled($crumb['url'] ?? null))
                <a
                    href="{{ $crumb['url'] }}"
                    class="canovia-map-global-path-link"
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                    data-route-lock-skip
                >{{ $crumb['label'] }}</a>
            @else
                <span class="canovia-map-global-path-current" aria-current="page">
                    {{ $crumb['label'] }}
                </span>
            @endif
        @endforeach
    </div>

    <div class="canovia-map-global-depth" aria-label="Mapの深さ">
        @foreach ($globalDepthLabels as $depthIndex => $depthLabel)
            <span
                class="canovia-map-global-depth-step {{ $depthIndex === $globalDepth ? 'is-current' : '' }} {{ $depthIndex < $globalDepth ? 'is-passed' : '' }}"
                @if ($depthIndex === $globalDepth) aria-current="step" @endif
            >
                <span class="canovia-map-global-depth-dot" aria-hidden="true"></span>
                <span>{{ $depthLabel }}</span>
            </span>
        @endforeach
    </div>
</nav>
