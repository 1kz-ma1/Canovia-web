@php
    $canoviaSurface = $activeSurface ?? (request()->routeIs('map.*') ? 'explore' : 'home');
    if ($canoviaSurface === 'classic') $canoviaSurface = 'home';
    if ($canoviaSurface === 'map') $canoviaSurface = 'explore';
@endphp

<nav class="canovia-home-surface-switcher" aria-label="Canovia Surface" data-canovia-surface-nav>
    <span class="canovia-home-surface-label">SURFACES</span>
    <div class="canovia-home-surface-options">
        <a
            href="{{ route('home') }}"
            class="canovia-home-surface-option {{ $canoviaSurface === 'home' ? 'is-active' : '' }}"
            data-canovia-surface="home"
            data-route-lock-skip
            aria-label="Action Home"
            @if ($canoviaSurface === 'home') aria-current="page" @endif
            @if ($canoviaSurface === 'explore') data-map-home-fallback @endif
        >Home</a>
        <a
            href="{{ route('map.index') }}"
            class="canovia-home-surface-option {{ $canoviaSurface === 'explore' ? 'is-active' : '' }}"
            data-canovia-surface="explore"
            data-route-lock-skip
            aria-label="Canovia Explore"
            @if ($canoviaSurface === 'explore') aria-current="page" @endif
        >
            <span>Explore</span>
            <span class="canovia-home-surface-beta">MAP</span>
        </a>
    </div>
</nav>
