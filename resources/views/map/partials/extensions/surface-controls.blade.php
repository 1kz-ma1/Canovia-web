{{-- Merge-safe extension boundary.
     Own: Map Page / preset switcher and Data Layer controls.
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

    $builtinMapPages = [
        [
            'id' => 'builtin:overview',
            'label' => '全体',
            'hint' => 'Canovia全体',
            'url' => route('map.index'),
        ],
        [
            'id' => 'builtin:execution',
            'label' => '実行',
            'hint' => '作業Context',
            'url' => route('map.index', ['level' => 'l1', 'intent' => 'execution']),
        ],
        [
            'id' => 'builtin:reflection',
            'label' => '振り返り',
            'hint' => 'Evidence・実績',
            'url' => route('map.index', ['level' => 'l1', 'intent' => 'reflection']),
        ],
        [
            'id' => 'builtin:collaboration',
            'label' => '共同',
            'hint' => 'Shared Plan',
            'url' => route('map.index', ['level' => 'l1', 'intent' => 'collaboration']),
        ],
    ];
@endphp

<div
    class="canovia-map-surface-controls"
    data-map-surface-controls
    data-map-current-route="{{ request()->getRequestUri() }}"
    data-map-current-label="{{ $currentContextLabel }}"
>
    <details
        class="canovia-map-page-control"
        data-map-page-control
        data-map-page-max-custom="8"
    >
        <summary class="canovia-map-page-summary" data-map-page-summary aria-label="Mapページを切り替える">
            <span aria-hidden="true">◉</span>
            <span data-map-page-summary-label>ページ</span>
            <span class="canovia-map-page-count" data-map-page-count hidden></span>
        </summary>

        <div class="canovia-map-page-palette">
            <div class="canovia-map-page-palette-heading">
                <div>
                    <strong>Mapページ</strong>
                    <span>同じCanoviaデータを、用途ごとの見方で開きます</span>
                </div>
                <span class="canovia-map-page-device-note">この端末</span>
            </div>

            <div class="canovia-map-page-builtins" data-map-page-builtins aria-label="標準ページ">
                @foreach ($builtinMapPages as $preset)
                    <a
                        href="{{ $preset['url'] }}"
                        class="canovia-map-page-item is-builtin"
                        data-map-page-open
                        data-map-page-id="{{ $preset['id'] }}"
                        data-map-page-name="{{ $preset['label'] }}"
                        data-map-page-route="{{ $preset['url'] }}"
                        data-instant-nav-skip
                        @if ($preset['id'] === 'builtin:overview') data-map-global-home @endif
                    >
                        <span class="canovia-map-page-item-mark" aria-hidden="true"></span>
                        <span class="canovia-map-page-item-copy">
                            <strong>{{ $preset['label'] }}</strong>
                            <small>{{ $preset['hint'] }}</small>
                        </span>
                    </a>
                @endforeach
            </div>

            <div
                class="canovia-map-page-custom-list"
                data-map-page-custom-list
                aria-label="保存したMapページ"
            ></div>

            <form class="canovia-map-page-save" data-map-page-save-form>
                <label for="canovia-map-page-name">現在のMapをページとして保存</label>
                <div class="canovia-map-page-save-row">
                    <input
                        id="canovia-map-page-name"
                        type="text"
                        maxlength="40"
                        class="input-field"
                        data-map-page-name-input
                        placeholder="例: AP学習 / 開発作業 / 全体確認"
                        value=""
                    >
                    <button type="submit" class="btn-secondary" data-map-page-save>
                        保存
                    </button>
                </div>
                <p class="canovia-map-page-note" data-map-page-note>
                    現在地と表示情報だけを保存します。Plan / Taskはコピーしません。
                </p>
            </form>
        </div>
    </details>

    @if ($availableDataLayers->isNotEmpty())
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
    @endif
</div>
