<aside
    class="canovia-map-context-surface"
    data-map-context-surface
    data-map-document-viewport
    data-map-document-kind="detail"
    aria-hidden="true"
    aria-live="polite"
    aria-label="選択中のマスの詳細"
>
    <div class="canovia-map-context-card" data-map-document-canvas>
        <div class="canovia-map-context-header">
            <div>
                <p class="canovia-map-kicker">詳細</p>
                <p class="canovia-map-context-caption">選んだマスの内容と操作を確認できます</p>
            </div>
            <div class="canovia-map-context-tools">
                @if (filled($hierarchy['parent_url'] ?? null))
                    <a
                        href="{{ $hierarchy['parent_url'] }}"
                        class="canovia-map-context-back"
                        data-map-document-back
                        data-route-lock-skip
                    >{{ $isExecutionLevel && data_get($hierarchy, 'intent') === 'execution' ? '← Plan選択' : '← 戻る' }}</a>
                @endif
                <button
                    type="button"
                    class="canovia-map-context-expand"
                    data-map-context-expand
                    aria-expanded="false"
                    aria-label="詳細パレットの表示サイズを切り替える"
                ><span data-map-context-expand-label>広げる</span></button>
                <button type="button" class="canovia-map-context-close" data-map-context-close aria-label="詳細を閉じる">×</button>
            </div>
        </div>
        <div data-map-context-content></div>
    </div>
</aside>
