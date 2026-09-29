@php($nodeReason = \App\Support\MapNodePresentation::reason($node))
@if ($nodeReason)
    <div class="canovia-map-node-context">
        <h3>ここにある理由</h3>
        <p>{{ $nodeReason }}</p>
    </div>
@endif
