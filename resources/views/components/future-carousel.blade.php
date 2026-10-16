@props(['id', 'label', 'count'])
<section class="future-carousel" data-future-carousel role="region" aria-roledescription="カルーセル" aria-label="{{ $label }}">
    <p id="{{ $id }}-help" class="future-carousel-help">横にスワイプ・スクロールして見る。枠にフォーカスすると左右矢印、Home / End キーでも選べます。</p>
    <div id="{{ $id }}-viewport" class="future-carousel-viewport" data-carousel-viewport tabindex="0" aria-label="{{ $label }}のカード" aria-describedby="{{ $id }}-help">
        <ul class="future-carousel-track" data-carousel-track>{{ $slot }}</ul>
    </div>
    <div class="future-carousel-controls" data-carousel-controls hidden>
        <button type="button" data-carousel-prev aria-label="{{ $label }}の前のカード" aria-controls="{{ $id }}-viewport">←</button>
        <div class="future-carousel-dots" aria-label="{{ $label }}のカードを選ぶ">
            @for ($i = 0; $i < $count; $i++)
                <button type="button" data-carousel-index="{{ $i }}" aria-label="{{ $label }}の{{ $i + 1 }}枚目へ" aria-controls="{{ $id }}-viewport"><span aria-hidden="true">●</span></button>
            @endfor
        </div>
        <button type="button" data-carousel-next aria-label="{{ $label }}の次のカード" aria-controls="{{ $id }}-viewport">→</button>
        <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-carousel-status></p>
    </div>
</section>
