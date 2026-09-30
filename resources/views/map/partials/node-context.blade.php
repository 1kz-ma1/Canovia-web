@php
    $personalization = is_array($node['personalization'] ?? null)
        ? $node['personalization']
        : null;
    $isPinned = (bool) ($personalization['pinned'] ?? false);
    $canManagePin = auth()->check()
        && ($node['type'] ?? null) === 'satellite_plan'
        && filled($node['entity_id'] ?? null);
    $shortcutPin = is_array($node['shortcut_pin'] ?? null)
        ? $node['shortcut_pin']
        : null;
    $shortcutPinEligible = (bool) ($shortcutPin['eligible'] ?? false);
    $shortcutPinPinned = (bool) ($shortcutPin['pinned'] ?? false);
    $shortcutPinStatus = (string) ($shortcutPin['status'] ?? '');
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

@if ($shortcutPin)
    <div
        class="canovia-map-shortcut-pin-context"
        data-map-shortcut-pin-context
        data-map-shortcut-pin-status="{{ $shortcutPinStatus }}"
    >
        <div class="canovia-map-personalization-explanation-heading">
            <div>
                <p class="canovia-map-personalization-kicker">SHORTCUT</p>
                <h3>全体Mapへの近道</h3>
            </div>
            @if ($shortcutPinPinned)
                <span class="canovia-map-personalization-strength">
                    {{ $shortcutPinEligible ? '固定中' : '対象外' }}
                </span>
            @endif
        </div>

        @if ($shortcutPinPinned && $shortcutPinEligible)
            <p>このPlanは全体Mapの近道として固定されています。</p>
            <form
                method="POST"
                action="{{ route('map.personalization.pins.destroy', ['plan' => $node['entity_id']]) }}"
                class="canovia-map-personalization-pin-form"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary w-full justify-center">
                    全体への固定を解除
                </button>
            </form>
        @elseif ($shortcutPinPinned)
            <p>固定設定は残っていますが、未完了Taskがないため現在は全体Mapの表示対象外です。</p>
            <form
                method="POST"
                action="{{ route('map.personalization.pins.destroy', ['plan' => $node['entity_id']]) }}"
                class="canovia-map-personalization-pin-form"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary w-full justify-center">
                    全体への固定を解除
                </button>
            </form>
        @elseif ($shortcutPinEligible)
            <p>このPlanを全体MapのPersonalized Shortcutとして固定できます。利用signalが弱くても、未完了Taskがある間は近道として残ります。</p>
            <form
                method="POST"
                action="{{ route('map.personalization.pins.store', ['plan' => $node['entity_id']]) }}"
                class="canovia-map-personalization-pin-form"
            >
                @csrf
                <button type="submit" class="btn-secondary w-full justify-center">
                    このPlanを全体へ固定
                </button>
            </form>
        @endif

        <p class="canovia-map-personalization-note">
            固定はL0の表示だけに作用し、Plan / Task / 優先度は変更しません。
        </p>
    </div>
@endif

