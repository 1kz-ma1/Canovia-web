@php
    $personalization = is_array($node['personalization'] ?? null)
        ? $node['personalization']
        : null;
    $nodeReason = $personalization
        ? null
        : \App\Support\MapNodePresentation::reason($node);
@endphp

@if ($personalization)
    <div
        class="canovia-map-personalization-explanation"
        data-map-personalization-explanation
        data-map-personalization-node-id="{{ $node['id'] }}"
    >
        <div class="canovia-map-personalization-explanation-heading">
            <div>
                <p class="canovia-map-personalization-kicker">PERSONALIZED</p>
                <h3>ここにある理由</h3>
            </div>
            <span class="canovia-map-personalization-strength">
                {{ ($personalization['strength'] ?? 'active') === 'strong' ? '強め' : '適用中' }}
            </span>
        </div>

        <p>{{ $personalization['explanation'] ?? '利用状況に応じた近道として表示しています。' }}</p>

        @if (! empty($personalization['reason_labels']))
            <div class="canovia-map-personalization-reasons" aria-label="表示理由">
                @foreach ($personalization['reason_labels'] as $reasonLabel)
                    <span>{{ $reasonLabel }}</span>
                @endforeach
            </div>
        @endif

        <button
            type="button"
            class="btn-secondary w-full justify-center"
            data-map-personalization-hide
            data-map-personalization-node-id="{{ $node['id'] }}"
        >
            この端末では非表示
        </button>

        <p class="canovia-map-personalization-note">
            表示設定だけを変更します。Plan / Taskや優先度は変更しません。
        </p>
    </div>
@elseif ($nodeReason)
    <div class="canovia-map-node-context">
        <h3>ここにある理由</h3>
        <p>{{ $nodeReason }}</p>
    </div>
@endif
