@if (is_array($graph['spatial_dock'] ?? null))
    <button
        type="button"
        class="canovia-map-spatial-dock"
        data-map-spatial-dock
        data-map-dock-id="{{ data_get($graph, 'spatial_dock.id', 'space-station') }}"
        aria-label="Space StationをこのContextのまま開く"
        aria-expanded="false"
    >
        <span class="canovia-map-spatial-dock-orbit" aria-hidden="true"></span>
        <span class="canovia-map-spatial-dock-mark" aria-hidden="true">✦</span>
        <span class="canovia-map-spatial-dock-copy">
            <strong>{{ data_get($graph, 'spatial_dock.label', 'Space Station') }}</strong>
            <small>Capture / AI</small>
        </span>
    </button>
@endif
