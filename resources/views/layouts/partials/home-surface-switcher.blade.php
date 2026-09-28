@php
    $homeSurface = $activeSurface ?? (request()->routeIs('map.*') ? 'map' : 'classic');
@endphp

<nav class="canovia-home-surface-switcher" aria-label="ホーム表示" data-home-surface-switcher>
    <span class="canovia-home-surface-label">HOME SURFACE</span>
    <div class="canovia-home-surface-options">
        <a
            href="{{ route('home') }}"
            class="canovia-home-surface-option {{ $homeSurface === 'classic' ? 'is-active' : '' }}"
            data-home-surface="classic"
            aria-label="Classic Home"
            @if ($homeSurface === 'classic') aria-current="page" @endif
            @if ($homeSurface === 'map') data-map-home-fallback @endif
        >Classic</a>
        <a
            href="{{ route('map.index') }}"
            class="canovia-home-surface-option {{ $homeSurface === 'map' ? 'is-active' : '' }}"
            data-home-surface="map"
            @if ($homeSurface === 'map') aria-current="page" @endif
        >
            <span>Map</span>
            <span class="canovia-home-surface-beta">BETA</span>
        </a>
    </div>
</nav>
