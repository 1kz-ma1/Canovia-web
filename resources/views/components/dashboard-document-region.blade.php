@props([
    'title' => null,
    'kicker' => null,
    'span' => 4,
    'rows' => 1,
    'emphasis' => 'normal',
])

@php
    $safeSpan = max(1, min(12, (int) $span));
    $safeRows = max(1, min(4, (int) $rows));
    $safeEmphasis = in_array($emphasis, ['normal', 'primary', 'quiet', 'alert'], true)
        ? $emphasis
        : 'normal';
@endphp

<section
    {{ $attributes->class(['canovia-dashboard-document-region']) }}
    data-dashboard-document-region
    data-dashboard-region-emphasis="{{ $safeEmphasis }}"
    style="--dashboard-region-span: {{ $safeSpan }}; --dashboard-region-rows: {{ $safeRows }};"
>
    @if ($kicker || $title)
        <header class="canovia-dashboard-document-region-heading">
            @if ($kicker)
                <p>{{ $kicker }}</p>
            @endif
            @if ($title)
                <h3>{{ $title }}</h3>
            @endif
        </header>
    @endif

    <div class="canovia-dashboard-document-region-body">
        {{ $slot }}
    </div>
</section>
