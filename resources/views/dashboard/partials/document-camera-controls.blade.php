@php
    $mapCompatibility = (bool) ($mapCompatibility ?? false);
@endphp

<div
    class="canovia-dashboard-document-camera {{ $mapCompatibility ? 'canovia-map-document-camera' : '' }}"
    data-dashboard-document-camera
    @if ($mapCompatibility) data-map-document-camera @endif
    role="group"
    aria-label="資料の拡大縮小"
>
    <button
        type="button"
        class="canovia-dashboard-document-camera-button {{ $mapCompatibility ? 'canovia-map-document-camera-button' : '' }}"
        data-dashboard-document-zoom-out
        @if ($mapCompatibility) data-map-document-zoom-out @endif
        aria-label="資料を縮小"
    >−</button>
    <button
        type="button"
        class="canovia-dashboard-document-camera-fit {{ $mapCompatibility ? 'canovia-map-document-camera-fit' : '' }}"
        data-dashboard-document-fit
        @if ($mapCompatibility) data-map-document-fit @endif
        aria-label="資料全体を表示"
    >全体</button>
    <button
        type="button"
        class="canovia-dashboard-document-camera-button {{ $mapCompatibility ? 'canovia-map-document-camera-button' : '' }}"
        data-dashboard-document-zoom-in
        @if ($mapCompatibility) data-map-document-zoom-in @endif
        aria-label="資料を拡大"
    >＋</button>
    <output
        class="canovia-dashboard-document-camera-label {{ $mapCompatibility ? 'canovia-map-document-camera-label' : '' }}"
        data-dashboard-document-zoom-label
        @if ($mapCompatibility) data-map-document-zoom-label @endif
        aria-label="現在の表示倍率"
    >100%</output>
</div>
