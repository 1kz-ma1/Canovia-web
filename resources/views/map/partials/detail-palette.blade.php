<aside
    class="canovia-map-context-surface"
    data-map-context-surface
    data-map-document-viewport
    data-map-document-kind="detail"
    data-dashboard-document
    data-dashboard-document-owner="map"
    data-dashboard-document-kind="detail"
    aria-hidden="true"
    role="region"
    aria-labelledby="canovia-map-detail-document-title"
>
    <header class="canovia-map-context-header canovia-map-document-chrome" data-map-document-chrome>
        <div class="canovia-map-context-leading">
            @if (filled($hierarchy['parent_url'] ?? null))
                <a
                    href="{{ $hierarchy['parent_url'] }}"
                    class="canovia-map-document-back canovia-map-context-back"
                    data-map-document-back
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                    data-route-lock-skip
                ><span aria-hidden="true">←</span><span>{{ $isExecutionLevel && data_get($hierarchy, 'intent') === 'execution' ? 'Plan選択' : '戻る' }}</span></a>
            @endif

            <div class="canovia-map-document-chrome-title">
                <p class="canovia-map-kicker">DETAIL</p>
                <strong
                    id="canovia-map-detail-document-title"
                    class="canovia-map-context-document-title"
                    data-map-document-title
                    aria-live="polite"
                >詳細</strong>
            </div>
        </div>

        <div class="canovia-map-context-tools">
            @include('map.partials.document-camera-controls')
            <button
                type="button"
                class="canovia-map-context-expand"
                data-map-context-expand
                aria-expanded="false"
                aria-label="詳細パレットの表示サイズを切り替える"
            ><span data-map-context-expand-label>広げる</span></button>
            <button type="button" class="canovia-map-context-close" data-map-context-close aria-label="詳細を閉じる">×</button>
        </div>
    </header>

    <div
        class="canovia-map-document-scroll"
        data-map-document-scroll
        data-dashboard-document-scroll
        tabindex="0"
        role="region"
        aria-label="詳細資料"
    >
        <div class="canovia-map-document-stage" data-map-document-stage data-dashboard-document-stage>
            <div class="canovia-map-context-card" data-map-document-canvas data-dashboard-document-canvas>
                <div data-map-context-content></div>
            </div>
        </div>
    </div>

    <div class="canovia-map-document-position" data-map-document-position aria-hidden="true">
        <span class="canovia-map-document-position-label">資料位置</span>
        <span class="canovia-map-document-position-track">
            <span class="canovia-map-document-position-thumb" data-map-document-position-thumb></span>
        </span>
    </div>
</aside>
