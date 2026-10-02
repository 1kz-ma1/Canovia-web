@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', '星座 | Canovia')

@section('content')
    @php
        $selectedConstellation = $plan
            ? $constellations->firstWhere('plan_id', (int) $plan->id)
            : null;
        $constellationCount = $constellations->count();
        $overviewDensity = match (true) {
            $constellationCount <= 2 => 0.52,
            $constellationCount <= 4 => 0.68,
            $constellationCount <= 6 => 0.82,
            default => 1.0,
        };
        $overviewDensityKey = $constellationCount <= 6 ? 'compact' : 'wide';
        $selectedStars = collect($selectedConstellation['stars'] ?? []);
        $selectedStarsById = $selectedStars->keyBy('id');
        $initialStar = $selectedStars->firstWhere('is_current', true) ?? $selectedStars->first();
    @endphp

    <div class="canovia-constellation-page" data-constellation-page>
        <header class="canovia-constellation-header">
            <div>
                <p class="pk-v18-eyebrow">CANOVIA / CONSTELLATION</p>
                <h1>計画の全体像と、現在地を見る。</h1>
                <p>ここでは「次に何をするか」ではなく、Planがどんな形で、どこまで完成しているかを確認します。</p>
            </div>
            @if ($plan)
                <a href="{{ route('roadmap.index') }}" class="btn-secondary canovia-constellation-overview-link">
                    全体へ
                </a>
            @endif
        </header>

        @if ($constellations->isNotEmpty())
            @if ($selectedConstellation)
                <section
                    class="canovia-constellation-selected-workspace"
                    data-constellation-selected-workspace
                    data-selected-plan-id="{{ $selectedConstellation['plan_id'] }}"
                >
                    <section class="canovia-constellation-focus-palette" data-constellation-focus-palette>
                        <div class="canovia-constellation-focus-graph-shell">
                            <div class="canovia-constellation-space-dust" aria-hidden="true"></div>
                            <div
                                class="canovia-plan-constellation is-selected is-focus"
                                data-plan-constellation
                                data-plan-id="{{ $selectedConstellation['plan_id'] }}"
                                data-pattern="{{ $selectedConstellation['pattern'] }}"
                                data-richness="{{ $selectedConstellation['richness_tier'] }}"
                                aria-label="{{ $selectedConstellation['title'] }}の星座"
                            >
                                <div class="canovia-plan-constellation-graph" aria-hidden="false">
                                    <svg class="canovia-constellation-edges" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                        @foreach ($selectedConstellation['edges'] ?? [] as $edge)
                                            @php
                                                $sourceStar = $selectedStarsById->get($edge['source']);
                                                $targetStar = $selectedStarsById->get($edge['target']);
                                            @endphp
                                            @if ($sourceStar && $targetStar)
                                                <line
                                                    x1="{{ $sourceStar['x'] }}"
                                                    y1="{{ $sourceStar['y'] }}"
                                                    x2="{{ $targetStar['x'] }}"
                                                    y2="{{ $targetStar['y'] }}"
                                                    data-constellation-edge
                                                    data-edge-relation="{{ $edge['relation'] }}"
                                                    data-edge-lit="{{ ! empty($edge['is_lit']) ? '1' : '0' }}"
                                                />
                                            @endif
                                        @endforeach
                                    </svg>

                                    @foreach ($selectedStars as $star)
                                        <button
                                            type="button"
                                            class="canovia-main-star {{ $initialStar && $initialStar['id'] === $star['id'] ? 'is-selected' : '' }}"
                                            data-constellation-star
                                            data-constellation-star-open="{{ $star['id'] }}"
                                            data-constellation-star-selected="{{ $initialStar && $initialStar['id'] === $star['id'] ? '1' : '0' }}"
                                            data-star-status="{{ $star['status'] }}"
                                            data-star-current="{{ $star['is_current'] ? '1' : '0' }}"
                                            data-star-complete="{{ $star['is_complete'] ? '1' : '0' }}"
                                            style="--star-x: {{ $star['x'] }}%; --star-y: {{ $star['y'] }}%; --star-completion: {{ $star['completion_percent'] / 100 }};"
                                            aria-label="{{ $star['label'] }} {{ $star['completed_count'] }}/{{ $star['task_count'] }}。Task群を確認"
                                        >
                                            <span class="canovia-main-star-core" aria-hidden="true"></span>
                                            @for ($satellite = 0; $satellite < (int) $star['satellite_count']; $satellite++)
                                                <i class="canovia-main-star-satellite satellite-{{ $satellite + 1 }}" aria-hidden="true"></i>
                                            @endfor
                                            <span class="canovia-main-star-count">{{ $star['completed_count'] }}/{{ $star['task_count'] }}</span>
                                        </button>
                                    @endforeach
                                </div>

                                <div class="canovia-plan-constellation-label">
                                    <strong>{{ $selectedConstellation['title'] }}</strong>
                                    <span>{{ $selectedConstellation['completion_percent'] }}% 完成</span>
                                </div>
                            </div>
                        </div>

                        <aside
                            class="canovia-constellation-star-panel"
                            data-constellation-star-panel
                            data-selected-star-id="{{ $initialStar['id'] ?? '' }}"
                            aria-live="polite"
                        >
                            <div data-constellation-star-panel-body>
                                @if ($initialStar)
                                    @include('roadmap.partials.star-task-group', [
                                        'star' => $initialStar,
                                        'constellation' => $selectedConstellation,
                                    ])
                                @else
                                    <div class="canovia-star-task-empty">
                                        <strong>Task Groupはまだありません。</strong>
                                        <p>Taskを追加すると星座の中にMain Starとして現れます。</p>
                                    </div>
                                @endif
                            </div>
                        </aside>
                    </section>

                    @foreach ($selectedStars as $star)
                        <template data-constellation-star-template="{{ $star['id'] }}">
                            @include('roadmap.partials.star-task-group', [
                                'star' => $star,
                                'constellation' => $selectedConstellation,
                            ])
                        </template>
                    @endforeach

                    <section class="canovia-constellation-detail-palette" data-constellation-detail-palette>
                        <div class="canovia-constellation-detail-heading">
                            <div>
                                <p>SELECTED CONSTELLATION</p>
                                <h2>{{ $selectedConstellation['title'] }}</h2>
                            </div>
                            <span data-status="{{ $selectedConstellation['status'] }}">{{ $selectedConstellation['status_label'] }}</span>
                        </div>

                        <div class="canovia-constellation-detail-metrics">
                            <span><strong>{{ $selectedConstellation['completion_percent'] }}%</strong>完成度</span>
                            <span><strong>{{ $selectedConstellation['completed_count'] }}/{{ $selectedConstellation['task_count'] }}</strong>Task</span>
                            <span><strong>{{ count($selectedConstellation['stars']) }}</strong>Main Star</span>
                        </div>

                        <p class="canovia-constellation-detail-note">
                            上の星を選ぶと、そのTask群と次のActionをここから画面遷移せず確認できます。
                        </p>

                        <div class="canovia-constellation-detail-actions">
                            <a href="{{ route('navigation.index', ['plan_id' => $selectedConstellation['plan_id']]) }}" class="btn-primary">
                                このPlanを実行
                            </a>
                            <a href="{{ route('plans.show', $selectedConstellation['plan_id']) }}" class="btn-secondary">
                                Plan詳細
                            </a>
                            @if ($canManage ?? false)
                                <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">
                                    Planを更新
                                </a>
                            @endif
                        </div>
                    </section>
                </section>
            @else
                <section
                    class="canovia-constellation-universe is-overview is-{{ $overviewDensityKey }}"
                    data-constellation-universe
                    data-constellation-overview
                    data-constellation-density="{{ $overviewDensityKey }}"
                    data-plan-count="{{ $constellationCount }}"
                    aria-label="Plan Constellation"
                >
                    <div class="canovia-constellation-stage" data-constellation-stage>
                        <div class="canovia-constellation-space-dust" aria-hidden="true"></div>

                        <button
                            type="button"
                            class="canovia-space-station"
                            data-constellation-station-open
                            aria-haspopup="dialog"
                            aria-label="Space Stationを開く"
                        >
                            <span class="canovia-space-station-core" aria-hidden="true">
                                <i></i><i></i><i></i>
                            </span>
                            <strong>SPACE STATION</strong>
                            <small>Plan操作</small>
                        </button>

                        @foreach ($constellations as $constellation)
                            @php
                                $stars = collect($constellation['stars'] ?? []);
                                $starsById = $stars->keyBy('id');
                                $rawX = (float) data_get($constellation, 'orbit.x', 50);
                                $rawY = (float) data_get($constellation, 'orbit.y', 50);
                                $displayX = round(50 + (($rawX - 50) * $overviewDensity), 2);
                                $displayY = round(50 + (($rawY - 50) * $overviewDensity), 2);
                            @endphp

                            <a
                                href="{{ route('roadmap.index', ['plan_id' => $constellation['plan_id']]) }}"
                                class="canovia-plan-constellation"
                                data-plan-constellation
                                data-plan-id="{{ $constellation['plan_id'] }}"
                                data-pattern="{{ $constellation['pattern'] }}"
                                data-richness="{{ $constellation['richness_tier'] }}"
                                style="--constellation-x: {{ $displayX }}%; --constellation-y: {{ $displayY }}%;"
                                aria-label="{{ $constellation['title'] }}を選択"
                            >
                                <div class="canovia-plan-constellation-graph" aria-hidden="true">
                                    <svg class="canovia-constellation-edges" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                        @foreach ($constellation['edges'] ?? [] as $edge)
                                            @php
                                                $sourceStar = $starsById->get($edge['source']);
                                                $targetStar = $starsById->get($edge['target']);
                                            @endphp
                                            @if ($sourceStar && $targetStar)
                                                <line
                                                    x1="{{ $sourceStar['x'] }}"
                                                    y1="{{ $sourceStar['y'] }}"
                                                    x2="{{ $targetStar['x'] }}"
                                                    y2="{{ $targetStar['y'] }}"
                                                    data-constellation-edge
                                                    data-edge-relation="{{ $edge['relation'] }}"
                                                    data-edge-lit="{{ ! empty($edge['is_lit']) ? '1' : '0' }}"
                                                />
                                            @endif
                                        @endforeach
                                    </svg>

                                    @foreach ($stars as $star)
                                        <span
                                            class="canovia-main-star"
                                            data-constellation-star
                                            data-star-status="{{ $star['status'] }}"
                                            data-star-current="{{ $star['is_current'] ? '1' : '0' }}"
                                            data-star-complete="{{ $star['is_complete'] ? '1' : '0' }}"
                                            style="--star-x: {{ $star['x'] }}%; --star-y: {{ $star['y'] }}%; --star-completion: {{ $star['completion_percent'] / 100 }};"
                                        >
                                            <span class="canovia-main-star-core" aria-hidden="true"></span>
                                            @for ($satellite = 0; $satellite < (int) $star['satellite_count']; $satellite++)
                                                <i class="canovia-main-star-satellite satellite-{{ $satellite + 1 }}" aria-hidden="true"></i>
                                            @endfor
                                        </span>
                                    @endforeach
                                </div>

                                <div class="canovia-plan-constellation-label">
                                    <strong>{{ $constellation['title'] }}</strong>
                                    <span>{{ $constellation['completion_percent'] }}% 完成</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>

                <dialog class="canovia-station-dialog" data-constellation-station-dialog aria-labelledby="canovia-station-title">
                    <div class="canovia-station-dialog-card">
                        <button type="button" class="canovia-star-task-dialog-close" data-constellation-station-close aria-label="閉じる">×</button>
                        <p>SPACE STATION</p>
                        <h2 id="canovia-station-title">Planを操作する</h2>
                        <div class="canovia-station-actions">
                            <a href="{{ route('plans.create') }}" class="btn-primary">新しいPlanを作る</a>
                            <a href="{{ route('my_plans.index') }}" class="btn-secondary">Plan一覧で管理</a>
                        </div>
                    </div>
                </dialog>
            @endif
        @else
            <section class="empty-state page-card p-8 text-center">
                <div class="text-4xl" aria-hidden="true">✦</div>
                <h2 class="mt-3 text-xl font-bold text-slate-100">最初の星座を作ろう</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">Planを作ると、その構造と進捗がここに星座として現れます。</p>
                <a href="{{ route('plans.create') }}" class="btn-primary mt-5">最初のPlanを作る</a>
            </section>
        @endif
    </div>
@endsection

@section('offline_snapshot')
@if ($plan && $roadmap)
@php
    $offlineCurrent = ! empty($roadmap['current'])
        ? collect($roadmap['current'])->only(['task_id', 'title', 'status', 'status_label', 'progress_percent', 'remaining_minutes', 'next_action_note', 'is_current'])->all()
        : null;
    $offlineSnapshot = [
        'type' => 'roadmap',
        'captured_at' => now()->toIso8601String(),
        'csrf_token' => csrf_token(),
        'plan' => ['id' => $plan->id, 'title' => $plan->title, 'category' => $plan->category],
        'current' => $offlineCurrent,
        'roadmap' => collect($roadmap['nodes'] ?? [])
            ->map(fn ($node) => collect($node)->only(['task_id', 'title', 'status', 'status_label', 'progress_percent', 'remaining_minutes', 'next_action_note', 'is_current'])->all())
            ->values()
            ->all(),
        'plans' => $plans->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
        ])->values()->all(),
        'continuity' => $continuity,
    ];
@endphp
<script type="application/json" id="pacekeeper-offline-snapshot">{!! json_encode($offlineSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@endsection
