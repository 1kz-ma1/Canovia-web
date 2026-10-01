@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', '星座 | Canovia')

@section('content')
    @php
        $selectedConstellation = $plan
            ? $constellations->firstWhere('plan_id', (int) $plan->id)
            : null;
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
            <section
                class="canovia-constellation-universe"
                data-constellation-universe
                data-selected-plan-id="{{ $plan?->id }}"
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
                        $isSelected = $plan && (int) $plan->id === (int) $constellation['plan_id'];
                        $stars = collect($constellation['stars'] ?? []);
                        $starsById = $stars->keyBy('id');
                    @endphp

                    @if ($isSelected)
                        <div
                            class="canovia-plan-constellation is-selected"
                            data-plan-constellation
                            data-plan-id="{{ $constellation['plan_id'] }}"
                            data-pattern="{{ $constellation['pattern'] }}"
                            data-richness="{{ $constellation['richness_tier'] }}"
                            style="--constellation-x: {{ data_get($constellation, 'orbit.x', 50) }}%; --constellation-y: {{ data_get($constellation, 'orbit.y', 50) }}%;"
                            aria-label="{{ $constellation['title'] }}の星座"
                        >
                    @else
                        <a
                            href="{{ route('roadmap.index', ['plan_id' => $constellation['plan_id']]) }}"
                            class="canovia-plan-constellation"
                            data-plan-constellation
                            data-plan-id="{{ $constellation['plan_id'] }}"
                            data-pattern="{{ $constellation['pattern'] }}"
                            data-richness="{{ $constellation['richness_tier'] }}"
                            style="--constellation-x: {{ data_get($constellation, 'orbit.x', 50) }}%; --constellation-y: {{ data_get($constellation, 'orbit.y', 50) }}%;"
                            aria-label="{{ $constellation['title'] }}を選択"
                        >
                    @endif
                        <div class="canovia-plan-constellation-graph" aria-hidden="{{ $isSelected ? 'false' : 'true' }}">
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
                                @if ($isSelected)
                                    <button
                                        type="button"
                                        class="canovia-main-star"
                                        data-constellation-star
                                        data-constellation-star-open="{{ $star['id'] }}"
                                        data-star-status="{{ $star['status'] }}"
                                        data-star-current="{{ $star['is_current'] ? '1' : '0' }}"
                                        data-star-complete="{{ $star['is_complete'] ? '1' : '0' }}"
                                        style="--star-x: {{ $star['x'] }}%; --star-y: {{ $star['y'] }}%; --star-completion: {{ $star['completion_percent'] / 100 }};"
                                        aria-label="{{ $star['label'] }} {{ $star['completed_count'] }}/{{ $star['task_count'] }}。Task一覧を開く"
                                    >
                                        <span class="canovia-main-star-core" aria-hidden="true"></span>
                                        @for ($satellite = 0; $satellite < (int) $star['satellite_count']; $satellite++)
                                            <i class="canovia-main-star-satellite satellite-{{ $satellite + 1 }}" aria-hidden="true"></i>
                                        @endfor
                                        <span class="canovia-main-star-count">{{ $star['completed_count'] }}/{{ $star['task_count'] }}</span>
                                    </button>
                                @else
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
                                @endif
                            @endforeach
                        </div>

                        <div class="canovia-plan-constellation-label">
                            <strong>{{ $constellation['title'] }}</strong>
                            <span>{{ $constellation['completion_percent'] }}% 完成</span>
                        </div>
                    @if ($isSelected)
                        </div>

                        @foreach ($stars as $star)
                            <template data-constellation-star-template="{{ $star['id'] }}">
                                <section class="canovia-star-task-list">
                                    <header>
                                        <div>
                                            <p>STAR / TASK GROUP</p>
                                            <h2>{{ $star['label'] }}</h2>
                                        </div>
                                        <span>{{ $star['completed_count'] }} / {{ $star['task_count'] }} 完了</span>
                                    </header>

                                    <div class="canovia-star-task-items">
                                        @foreach ($star['tasks'] as $task)
                                            <article
                                                class="canovia-star-task-item"
                                                data-task-status="{{ $task['status'] }}"
                                                data-task-current="{{ $task['is_current'] ? '1' : '0' }}"
                                            >
                                                <span class="canovia-star-task-state" aria-hidden="true"></span>
                                                <div>
                                                    <strong>{{ $task['title'] }}</strong>
                                                    <small>
                                                        {{ $task['status_label'] }}
                                                        · 進捗 {{ $task['progress_percent'] }}%
                                                        · 残り {{ $task['remaining_minutes'] }}分
                                                    </small>
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>

                                    <div class="canovia-star-task-actions">
                                        <a href="{{ route('navigation.index', ['plan_id' => $constellation['plan_id']]) }}" class="btn-primary">
                                            このPlanを実行
                                        </a>
                                        <a href="{{ route('plans.show', $constellation['plan_id']) }}" class="btn-secondary">
                                            Plan詳細
                                        </a>
                                    </div>
                                </section>
                            </template>
                        @endforeach
                    @else
                        </a>
                    @endif
                @endforeach

                @if ($selectedConstellation)
                    <aside class="canovia-constellation-inspector" data-constellation-inspector>
                        <div>
                            <p>SELECTED CONSTELLATION</p>
                            <h2>{{ $selectedConstellation['title'] }}</h2>
                        </div>
                        <div class="canovia-constellation-inspector-metrics">
                            <span><strong>{{ $selectedConstellation['completion_percent'] }}%</strong>完成度</span>
                            <span><strong>{{ $selectedConstellation['completed_count'] }}/{{ $selectedConstellation['task_count'] }}</strong>Task</span>
                            <span><strong>{{ count($selectedConstellation['stars']) }}</strong>Main Star</span>
                        </div>
                        <div class="canovia-constellation-inspector-status">
                            <span data-status="{{ $selectedConstellation['status'] }}">{{ $selectedConstellation['status_label'] }}</span>
                            <small>星を選ぶとTask群を確認できます。</small>
                        </div>
                    </aside>
                @endif
                </div>
            </section>

            <dialog class="canovia-star-task-dialog" data-constellation-star-dialog aria-label="星のTask一覧">
                <div class="canovia-star-task-dialog-card">
                    <button type="button" class="canovia-star-task-dialog-close" data-constellation-star-close aria-label="閉じる">×</button>
                    <div data-constellation-star-dialog-body></div>
                </div>
            </dialog>

            <dialog class="canovia-station-dialog" data-constellation-station-dialog aria-labelledby="canovia-station-title">
                <div class="canovia-station-dialog-card">
                    <button type="button" class="canovia-star-task-dialog-close" data-constellation-station-close aria-label="閉じる">×</button>
                    <p>SPACE STATION</p>
                    <h2 id="canovia-station-title">Planを操作する</h2>
                    <div class="canovia-station-actions">
                        <a href="{{ route('plans.create') }}" class="btn-primary">新しいPlanを作る</a>
                        <a href="{{ route('my_plans.index') }}" class="btn-secondary">Plan一覧で管理</a>
                        @if ($plan && ($canManage ?? false))
                            <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">選択中のPlanを更新</a>
                        @endif
                        @if ($plan)
                            <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">選択中のPlan詳細</a>
                        @endif
                    </div>
                </div>
            </dialog>
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
