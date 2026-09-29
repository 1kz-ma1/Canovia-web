<aside
    class="canovia-map-context-surface"
    data-map-context-surface
    aria-hidden="true"
    aria-live="polite"
    aria-label="選択中のマスの詳細"
>
    <div class="canovia-map-context-card">
        <div class="canovia-map-context-header">
            <div>
                <p class="canovia-map-kicker">詳細</p>
                <p class="canovia-map-context-caption">選んだマスの内容と操作を確認できます</p>
            </div>
            <div class="canovia-map-context-tools">
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
