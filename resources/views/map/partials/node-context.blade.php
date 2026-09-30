@php
    $personalization = is_array($node['personalization'] ?? null)
        ? $node['personalization']
        : null;
    $isPinned = (bool) ($personalization['pinned'] ?? false);
    $canManagePin = auth()->check()
        && ($node['type'] ?? null) === 'satellite_plan'
        && filled($node['entity_id'] ?? null);
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
                {{ $isPinned ? '固定' : (($personalization['strength'] ?? 'active') === 'strong' ? '強め' : '適用中') }}
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

        @if ($canManagePin)
            @if ($isPinned)
                <form
                    method="POST"
                    action="{{ route('map.personalization.pins.destroy', ['plan' => $node['entity_id']]) }}"
                    class="canovia-map-personalization-pin-form"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-secondary w-full justify-center">
                        固定を解除
                    </button>
                </form>
            @else
                <form
                    method="POST"
                    action="{{ route('map.personalization.pins.store', ['plan' => $node['entity_id']]) }}"
                    class="canovia-map-personalization-pin-form"
                >
                    @csrf
                    <button type="submit" class="btn-secondary w-full justify-center">
                        このShortcutを固定
                    </button>
                </form>
            @endif
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
            @if ($isPinned)
                固定はアカウントに保存されます。この端末だけ非表示にしても固定自体は解除されません。
            @else
                表示設定だけを変更します。Plan / Taskや優先度は変更しません。
            @endif
        </p>
    </div>
@elseif ($nodeReason)
    <div class="canovia-map-node-context">
        <h3>ここにある理由</h3>
        <p>{{ $nodeReason }}</p>
    </div>
@endif
