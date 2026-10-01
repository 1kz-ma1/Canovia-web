@props([
    'kind' => 'dashboard',
    'label' => 'Dashboard Document',
    'width' => '72rem',
    'camera' => true,
])

<section
    {{ $attributes->class(['canovia-dashboard-document']) }}
    data-dashboard-document
    data-dashboard-document-kind="{{ $kind }}"
    aria-label="{{ $label }}"
>
    <header class="canovia-dashboard-document-chrome" data-dashboard-document-chrome>
        <div class="canovia-dashboard-document-chrome-content">
            {{ $chrome ?? '' }}
        </div>

        @if ($camera)
            @include('dashboard.partials.document-camera-controls')
        @endif
    </header>

    <div
        class="canovia-dashboard-document-scroll"
        data-dashboard-document-scroll
        tabindex="0"
        role="region"
        aria-label="{{ $label }}の資料"
    >
        <div
            class="canovia-dashboard-document-stage"
            data-dashboard-document-stage
        >
            <div
                class="canovia-dashboard-document-canvas"
                data-dashboard-document-canvas
                style="--dashboard-document-natural-width: {{ $width }}"
            >
                {{ $slot }}
            </div>
        </div>
    </div>
</section>
