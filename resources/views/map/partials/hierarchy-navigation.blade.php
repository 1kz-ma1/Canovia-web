@php
    $hierarchy = is_array($graph['hierarchy'] ?? null) ? $graph['hierarchy'] : [];
    $depth = (int) ($hierarchy['depth'] ?? 0);
    $breadcrumbs = collect($hierarchy['breadcrumbs'] ?? []);
    $isCollaborationHierarchy = (bool) ($hierarchy['collaboration_mode'] ?? false)
        || (($hierarchy['intent'] ?? null) === 'collaboration' && filled($hierarchy['collaboration_context_key'] ?? null));
    $levelLabels = $isCollaborationHierarchy
        ? [
            0 => 'Intent',
            1 => 'Purpose',
            2 => 'Context',
            3 => 'Execution',
        ]
        : [
            0 => 'Intent',
            1 => 'Domain',
            2 => 'Plan',
            3 => 'Execution',
        ];
@endphp

<div class="canovia-map-hierarchy-bar" data-map-hierarchy-path data-map-hierarchy-depth="{{ $depth }}">
    <nav class="canovia-map-breadcrumbs" aria-label="Map hierarchy">
        @foreach ($breadcrumbs as $index => $crumb)
            @if ($index > 0)
                <span class="canovia-map-breadcrumb-separator" aria-hidden="true">›</span>
            @endif

            @if (filled($crumb['url'] ?? null))
                <a
                    href="{{ $crumb['url'] }}"
                    class="canovia-map-breadcrumb-link"
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                    data-route-lock-skip
                >{{ $crumb['label'] }}</a>
            @else
                <span class="canovia-map-breadcrumb-current" aria-current="page">{{ $crumb['label'] ?? '' }}</span>
            @endif
        @endforeach
    </nav>

    <div class="canovia-map-depth-track" aria-label="Map depth">
        @foreach ($levelLabels as $levelDepth => $label)
            <span
                class="canovia-map-depth-step {{ $levelDepth === $depth ? 'is-active' : '' }} {{ $levelDepth < $depth ? 'is-visited' : '' }}"
                title="L{{ $levelDepth }} · {{ $label }}"
            >
                <span aria-hidden="true"></span>
                <small>L{{ $levelDepth }}</small>
            </span>
        @endforeach
    </div>
</div>
