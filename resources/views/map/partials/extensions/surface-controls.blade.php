{{-- Merge-safe extension boundary.
     Own: Map Surface/Page switcher and Data Layer controls.
     Keep canonical Plan/Task state outside this view. --}}
@php
    $mapDataLayers = is_array($graph['data_layers'] ?? null) ? $graph['data_layers'] : [];
    $availableDataLayers = collect($mapDataLayers['available'] ?? [])
        ->filter(fn ($layer) => is_string($layer) && in_array($layer, [
            'progress',
            'deadline',
            'status',
            'evidence',
            'dependency',
            'priority',
        ], true))
        ->values();
    $defaultDataLayers = collect($mapDataLayers['default_enabled'] ?? [])
        ->intersect($availableDataLayers)
        ->values();
    $dataLayerLabels = [
        'progress' => ['label' => '進捗', 'hint' => 'Taskの記録済み進捗率'],
        'deadline' => ['label' => '期限', 'hint' => 'Planに設定済みの期限'],
        'status' => ['label' => '状態', 'hint' => 'Taskの明示status'],
        'evidence' => ['label' => 'Evidence', 'hint' => 'Taskに紐づく記録件数'],
        'dependency' => ['label' => '前提関係', 'hint' => '登録済みDependencyだけを強調'],
        'priority' => ['label' => '優先度', 'hint' => '保存済みpriority値'],
    ];
@endphp

@if ($availableDataLayers->isNotEmpty())
    <div class="canovia-map-surface-controls" data-map-surface-controls>
        <details
            class="canovia-map-data-layer-control"
            data-map-data-layer-control
            data-map-data-layer-available="{{ $availableDataLayers->implode(',') }}"
            data-map-data-layer-default="{{ $defaultDataLayers->implode(',') }}"
        >
            <summary class="canovia-map-data-layer-summary" data-map-data-layer-summary>
                <span aria-hidden="true">◫</span>
                <span>表示情報</span>
                <span class="canovia-map-data-layer-count" data-map-data-layer-count hidden></span>
            </summary>

            <div class="canovia-map-data-layer-palette" aria-label="Mapに重ねる情報">
                <div class="canovia-map-data-layer-palette-heading">
                    <strong>表示情報</strong>
                    <span>必要なものだけMapへ重ねます</span>
                </div>

                <div class="canovia-map-data-layer-options">
                    @foreach ($availableDataLayers as $layer)
                        @php($copy = $dataLayerLabels[$layer] ?? ['label' => $layer, 'hint' => ''])
                        <label class="canovia-map-data-layer-option">
                            <span class="canovia-map-data-layer-option-copy">
                                <strong>{{ $copy['label'] }}</strong>
                                <small>{{ $copy['hint'] }}</small>
                            </span>
                            <input
                                type="checkbox"
                                value="{{ $layer }}"
                                data-map-layer-toggle
                                aria-label="{{ $copy['label'] }}をMapに表示"
                            >
                            <span class="canovia-map-data-layer-switch" aria-hidden="true"></span>
                        </label>
                    @endforeach
                </div>

                <p class="canovia-map-data-layer-note">
                    表示設定はこの端末だけに保存します。TaskやPlanの状態は変更しません。
                </p>
            </div>
        </details>
    </div>
@endif
