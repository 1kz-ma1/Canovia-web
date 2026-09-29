@php
    $nodeOverlay = is_array($node['overlay'] ?? null) ? $node['overlay'] : [];
    $progressPercent = max(0, min(100, (int) data_get($nodeOverlay, 'progress.percent', 0)));
    $evidenceCount = max(0, (int) data_get($nodeOverlay, 'evidence.count', 0));
    $dependencyCount = max(0, (int) data_get($nodeOverlay, 'dependency.count', 0));
@endphp

@if ($nodeOverlay !== [])
    <div class="canovia-map-node-data-layers" data-map-node-data-layers aria-hidden="true">
        @if (array_key_exists('progress', $nodeOverlay))
            <span
                class="canovia-map-layer-badge is-progress"
                data-map-layer-badge="progress"
                style="--map-layer-progress: {{ $progressPercent }}"
            >
                <span class="canovia-map-layer-progress-ring"></span>
                <span>{{ $progressPercent }}%</span>
            </span>
        @endif

        @if (filled(data_get($nodeOverlay, 'deadline.label')))
            <span class="canovia-map-layer-badge is-deadline" data-map-layer-badge="deadline">
                期限 {{ data_get($nodeOverlay, 'deadline.label') }}
            </span>
        @endif

        @if (filled(data_get($nodeOverlay, 'status.label')))
            <span class="canovia-map-layer-badge is-status" data-map-layer-badge="status">
                {{ data_get($nodeOverlay, 'status.label') }}
            </span>
        @endif

        @if ($evidenceCount > 0)
            <span class="canovia-map-layer-badge is-evidence" data-map-layer-badge="evidence">
                記録 {{ $evidenceCount }}
            </span>
        @endif

        @if ($dependencyCount > 0)
            <span class="canovia-map-layer-badge is-dependency" data-map-layer-badge="dependency">
                前提 {{ $dependencyCount }}
            </span>
        @endif

        @if (filled(data_get($nodeOverlay, 'priority.label')))
            <span class="canovia-map-layer-badge is-priority" data-map-layer-badge="priority">
                {{ data_get($nodeOverlay, 'priority.label') }}
            </span>
        @endif
    </div>
@endif
