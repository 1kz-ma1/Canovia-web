<div class="canovia-map-workspace" data-map-workspace>
    <div
        class="canovia-map-shell {{ $isIntentHub ? 'is-intent-hub' : ($isHierarchyLevel ? 'is-hierarchy-map' : 'is-execution-map') }}{{ $isCollaborationMode ? ' is-collaboration-map' : '' }}{{ $isReflectionMode ? ' is-reflection-map' : '' }}"
        data-canovia-map
        data-map-level="{{ $mapLevel }}"
    >
        @if ($isExecutionLevel)
            <span class="canovia-map-axis-label is-future">Future</span>
            <span class="canovia-map-axis-label is-past">Past</span>
            <span class="canovia-map-axis-label is-input">Input</span>
            <span class="canovia-map-axis-label is-action">Action</span>
        @endif

        @include('map.partials.extensions.canvas-overlays')
        @include('map.partials.scene')

        <div class="canovia-map-gesture-controls" data-map-gesture-controls aria-label="Map表示操作">
            <button type="button" data-map-zoom-out aria-label="Mapを縮小">−</button>
            <button type="button" data-map-view-reset aria-label="Map表示を中央へ戻す">◎</button>
            <button type="button" data-map-zoom-in aria-label="Mapを拡大">＋</button>
        </div>

        @include('map.partials.spatial-dock')
    </div>

    @include('map.partials.detail-palette')
</div>
