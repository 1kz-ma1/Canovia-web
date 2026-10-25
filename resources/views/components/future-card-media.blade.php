@props(['media' => null])
<figure class="future-card-media">
    @if ($media)
        <img src="{{ asset('images/future/'.$media['file']) }}" width="960" height="540" loading="lazy" decoding="async" alt="{{ $media['alt'] }}" @class(['future-card-art-contain' => ($media['fit'] ?? null) === 'contain']) data-future-art>
        <figcaption>構想のイメージ図 · 現在の画面や提供状況を示すものではありません</figcaption>
    @else
        <div class="future-card-placeholder" role="img" aria-label="Canoviaの機能構想。専用イラストは準備中">
            <img src="{{ asset('brand/logo-mark.svg') }}" width="80" height="80" alt="" loading="lazy">
            <span>これからの可能性を、一緒に。</span>
        </div>
    @endif
</figure>
