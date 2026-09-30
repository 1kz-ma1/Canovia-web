<div class="canovia-map-hero canovia-map-toolbar canovia-map-fullscreen-topbar" data-map-fullscreen-topbar>
    <div class="canovia-map-hero-copy">
        <div class="canovia-map-heading">
            <p class="canovia-map-kicker">{{ $heroKicker }}</p>
            <h1 class="canovia-map-title">{{ $currentContextLabel }}</h1>

            @if ($isIntentHub)
                <p class="canovia-map-description">
                    Space Stationを中心に、計画・実行・振り返り・共同へ辿るCanovia全体のNavigation Layerです。
                </p>
            @elseif ($isDomainLevel && $isCollaborationMode)
                <p class="canovia-map-description">
                    Shared Planや外部Toolをサービス別ではなく、自分のAction・レビュー待ち・相手待ち・外部確認という目的から辿ります。
                </p>
            @elseif ($isPlanLevel && $isCollaborationMode)
                <p class="canovia-map-description">
                    {{ $hierarchy['collaboration_context_label'] ?? '共同Context' }}に該当するTask / Artifactを確認し、必要なExecutionまたは外部Toolへ進みます。
                </p>
            @elseif ($isDomainLevel && $isReflectionMode)
                <p class="canovia-map-description">
                    Plan分類ではなく、最近の実績・Evidence・完了Task・振り返り記録というLensから過去の事実を辿ります。
                </p>
            @elseif ($isPlanLevel && $isReflectionMode)
                <p class="canovia-map-description">
                    {{ $hierarchy['reflection_context_label'] ?? '振り返り' }}に該当する具体的な記録を確認し、元のPlanやTimelineへ戻れます。
                </p>
            @elseif ($isDomainLevel && data_get($hierarchy, 'intent') === 'execution')
                <p class="canovia-map-description">
                    実行したいPlanを直接選び、そのPlanのExecution Contextへ進みます。カテゴリは補足情報としてだけ扱います。
                </p>
            @elseif ($isDomainLevel)
                <p class="canovia-map-description">
                    {{ $hierarchy['intent_label'] ?? '計画' }}のContextを保ったまま、次のContextへSemantic Zoomします。
                </p>
            @elseif ($isPlanLevel)
                <p class="canovia-map-description">
                    {{ $hierarchy['domain_label'] ?? 'Domain' }}の中からPlanを選び、そのExecution Contextへ潜ります。
                </p>
            @elseif ($isExecutionLevel)
                <p class="canovia-map-description">
                    選んだPlanを中央に、次に進めることや記録を周囲へまとめます。マスを選ぶと詳しい内容を確認できます。
                </p>
            @endif
        </div>

        <details class="canovia-map-help">
            <summary>Mapの見方</summary>
            @if ($isIntentHub)
                <p>
                    中央のSpace Stationが入力・相談のHubです。周囲のIntentから、Plan・Execution・振り返り・共同Contextへ直接辿れます。
                </p>
            @elseif ($isHierarchyLevel && $isCollaborationMode)
                <p>
                    中央は共同作業の目的Context、周囲はその目的に該当するShared Plan / Artifactです。
                    GitHub等の外部状態は推測せず、Canoviaで明示された状態と確認先だけを表示します。
                </p>
            @elseif ($isHierarchyLevel && $isReflectionMode)
                <p>
                    中央は振り返りLens、周囲は既存WorkLog・Evidence・完了Taskから投影した記録です。
                    Map用の履歴は作らず、確認すると元のPlanやTimelineへ戻ります。
                </p>
            @elseif ($isHierarchyLevel)
                <p>
                    中央はひとつ上のContext、周囲はその子Contextです。「潜る ↘」で内側へ、「戻る」で外側へ移動します。
                    Space Stationは右下に固定され、現在のContextを離れずCaptureやAIを開けます。
                </p>
            @else
                <p>
                    中央は今見ているPlanです。「おすすめ」などのマスを押すと詳細が開きます。
                    必要な操作は詳細から進められ、Space Stationは右下からいつでも開けます。
                </p>
            @endif
        </details>
    </div>

    <div class="canovia-map-fullscreen-surface-switch">
        @include('layouts.partials.home-surface-switcher', ['activeSurface' => 'map'])
    </div>

    <div class="canovia-map-hero-actions">
        @if ($isIntentHub)
            <a
                href="{{ route('map.index', ['level' => 'l1', 'intent' => 'execution']) }}"
                class="btn-primary"
                data-map-semantic-zoom
                data-map-zoom-direction="in"
            >実行へ潜る</a>
            <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
            <a href="{{ route('my_plans.index') }}" class="btn-secondary">計画一覧</a>
        @elseif ($isExecutionLevel)
            @if ($primaryNodeId && filled(data_get($primaryLaunch, 'url')))
                <a
                    href="{{ data_get($primaryLaunch, 'url') }}"
                    class="btn-primary canovia-map-primary-launch"
                    data-map-classic-action
                    data-map-direct-primary-launch
                    data-map-action-role="primary"
                    data-map-node-id="{{ $primaryNodeId }}"
                    data-map-node-type="task"
                    data-map-position-role="action-primary"
                    data-map-is-primary="1"
                    title="{{ data_get($primaryLaunch, 'label', 'Primary Actionを進める') }}"
                    aria-label="{{ data_get($primaryLaunch, 'label', 'Primary Actionを進める') }}"
                >そのまま進める</a>
                <button type="button" class="btn-secondary canovia-map-now-button" data-map-primary-focus>
                    Contextを見る
                </button>
            @elseif ($primaryNodeId)
                <button type="button" class="btn-primary canovia-map-now-button" data-map-primary-focus>
                    今やることを見る
                </button>
            @endif
            <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
            @if (filled($hierarchy['parent_url'] ?? null))
                <a
                    href="{{ $hierarchy['parent_url'] }}"
                    class="btn-secondary"
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                >{{ data_get($hierarchy, 'intent') === 'execution' ? 'Plan選択へ戻る' : 'Plan Mapへ戻る' }}</a>
            @else
                <a
                    href="{{ route('map.index') }}"
                    class="btn-secondary"
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                    data-map-global-home
                >Canovia全体</a>
            @endif
            <a href="{{ route('roadmap.index') }}" class="btn-secondary canovia-map-roadmap-link">Roadmap</a>
        @else
            @if (filled($hierarchy['parent_url'] ?? null))
                <a
                    href="{{ $hierarchy['parent_url'] }}"
                    class="btn-secondary"
                    data-map-semantic-zoom
                    data-map-zoom-direction="out"
                >ひとつ外へ戻る</a>
            @endif
            <button type="button" class="btn-secondary hidden" data-map-focus-reset>全体を見る</button>
            <a href="{{ $isReflectionMode ? route('timeline.index') : route('my_plans.index') }}" class="btn-secondary">
                {{ $isReflectionMode ? 'Timeline' : '一覧で見る' }}
            </a>
        @endif
    </div>

    <div class="canovia-map-fullscreen-hierarchy">
        @include('map.partials.hierarchy-navigation')
    </div>
</div>
