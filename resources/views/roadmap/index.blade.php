@extends(in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'ロードマップ | Canovia')

@section('content')
    @php
        $previousRoadmapUrl = $previousPlan ? route('roadmap.index', ['plan_id' => $previousPlan->id]) : null;
        $nextRoadmapUrl = $nextPlan ? route('roadmap.index', ['plan_id' => $nextPlan->id]) : null;
        $roadmapProfile = $roadmapPresentation ?? [];
    @endphp

    @if ($previousRoadmapUrl)<link rel="prefetch" href="{{ $previousRoadmapUrl }}">@endif
    @if ($nextRoadmapUrl)<link rel="prefetch" href="{{ $nextRoadmapUrl }}">@endif

    <div class="pk-v19-roadmap-page">
        <header
            class="pk-v19-roadmap-hero"
            data-roadmap-profile="{{ $roadmapProfile['key'] ?? 'general' }}"
            data-roadmap-renderer-preference="{{ $roadmapProfile['roadmap_renderer'] ?? 'task_flow' }}"
            data-roadmap-renderer-active="{{ $roadmapProfile['active_renderer'] ?? 'task_flow' }}"
        >
            <div class="pk-v19-roadmap-hero-copy">
                <p class="pk-v18-eyebrow">CANOVIA / {{ $roadmapProfile['label'] ?? 'ROADMAP' }}</p>
                <h1>{{ $roadmapProfile['roadmap_title'] ?? 'ロードマップ' }}</h1>
                <p>{{ $roadmapProfile['roadmap_description'] ?? '小さな一歩が、大きな未来につながる。' }}</p>
            </div>
            <div class="pk-v19-roadmap-planet" aria-hidden="true"></div>
            <img src="/brand/mascot-guide.webp" alt="" class="pk-v19-roadmap-guide" aria-hidden="true">
            <p class="pk-v19-roadmap-guide-copy" aria-hidden="true">一歩ずつ進んで<br>理想の自分に<br>近づこう！ ✦</p>
        </header>

        @if ($plans->isNotEmpty())
            <nav class="pk-v19-plan-carousel" data-roadmap-plan-tabs aria-label="計画を切り替える">
                @foreach ($plans as $item)
                    @php
                        $totalTasks = $item->tasks->count();
                        $doneTasks = $item->tasks->where('status', 'done')->count();
                        $percent = $totalTasks > 0 ? (int) round(($doneTasks / $totalTasks) * 100) : 0;
                    @endphp
                    <a
                        href="{{ route('roadmap.index', ['plan_id' => $item->id]) }}"
                        class="pk-v19-plan-card {{ $plan?->id === $item->id ? 'is-active' : '' }}"
                        data-plan-accent="{{ $item->accentKey() }}"
                        aria-current="{{ $plan?->id === $item->id ? 'page' : 'false' }}"
                    >
                        <span class="pk-v19-plan-card-icon" aria-hidden="true">{{ $item->displayIcon() }}</span>
                        <span class="pk-v19-plan-card-copy">
                            <strong>{{ $item->title }}</strong>
                            <small>{{ $doneTasks }} / {{ $totalTasks }}</small>
                            <i><b style="width: {{ $percent }}%"></b></i>
                        </span>
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($plan && $roadmap)
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-700/60 bg-slate-950/35 px-4 py-3 backdrop-blur">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">選択中の計画</p>
                    <p class="mt-1 truncate text-sm font-bold text-slate-100">{{ $plan->title }}</p>
                    <p class="mt-1 text-[11px] text-slate-500">{{ $roadmapProfile['label'] ?? '汎用' }}向け表示 · {{ $roadmapProfile['roadmap_description'] ?? '' }}</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary px-3 py-2 text-xs" data-guide-target="plan-detail">計画詳細</a>
                    <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary px-3 py-2 text-xs" data-guide-target="plan-resources">関連資料</a>
                    <a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-secondary border-cyan-300/20 bg-cyan-300/[0.05] px-3 py-2 text-xs text-cyan-100" data-guide-target="collaboration-settings">
                        @if ($canManage ?? false)
                            {{ $plan->is_collaborative ? '共同計画を管理' : '共同計画にする' }}
                        @else
                            共同計画を見る
                        @endif
                    </a>
                    @if ($canManage ?? false)
                        <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-primary px-3 py-2 text-xs" data-guide-target="plan-update">計画を更新</a>
                        <details class="relative">
                            <summary class="btn-secondary cursor-pointer list-none px-3 py-2 text-xs" aria-label="計画メニュー">…</summary>
                            <div class="absolute right-0 z-[80] mt-2 w-52 rounded-2xl border border-slate-700 bg-slate-950/95 p-2 shadow-2xl backdrop-blur">
                                <a href="{{ route('plans.edit', $plan) }}" class="block rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-slate-800">計画を編集</a>
                                @auth
                                    <a href="{{ route('plans.collaboration.settings', $plan) }}" class="block rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-slate-800">共同計画・共有</a>
                                @endauth
                            </div>
                        </details>
                    @elseif (($collaborationRole ?? null) === 'editor')
                        <span class="rounded-full border border-cyan-300/20 bg-cyan-300/5 px-3 py-2 text-xs font-semibold text-cyan-100">編集者</span>
                    @else
                        <span class="rounded-full border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-400">閲覧のみ</span>
                    @endif
                </div>
            </div>

            <div
                class="roadmap-plan-pager"
                data-roadmap-plan-pager
                data-onboarding-target="roadmap-surface"
                tabindex="0"
                data-prev-url="{{ $previousRoadmapUrl }}"
                data-next-url="{{ $nextRoadmapUrl }}"
                aria-live="polite"
            >
                <section
                    class="pk-v19-roadmap-surface plan-identity-shell"
                    data-plan-accent="{{ $plan->accentKey() }}"
                    data-roadmap-renderer-preference="{{ $roadmapProfile['roadmap_renderer'] ?? 'task_flow' }}"
                    data-roadmap-renderer-active="{{ $roadmapProfile['active_renderer'] ?? 'task_flow' }}"
                >
                    @include('plans.partials.roadmap', [
                        'roadmap' => $roadmap,
                        'roadmapPlan' => $plan,
                        'roadmapCanEdit' => $canEdit,
                        'roadmapCanManage' => $canManage ?? false,
                        'roadmapMode' => 'plan',
                        'roadmapRecommendedMinutes' => $recommendation?->recommendedMinutes,
                        'roadmapRecommendationReasons' => $recommendation?->reasons ?? [],
                    ])
                </section>
                <p class="roadmap-swipe-hint md:hidden" aria-hidden="true">← スワイプで計画を切替 →</p>
            </div>
        @else
            <section class="empty-state page-card p-8 text-center">
                <div class="text-4xl" aria-hidden="true">🗺️</div>
                <h2 class="mt-3 text-xl font-bold text-slate-100">まだロードマップがありません</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">計画を作ると、ここに未来へ続く道が見えるようになります。</p>
                <a href="{{ route('plans.create') }}" class="btn-primary mt-5">最初の計画を作る</a>
            </section>
        @endif

        @if ($plan)
            <blockquote class="pk-v19-roadmap-quote">
                <span aria-hidden="true">“</span>
                <p>今の努力が、きっとどこかでつながってる。</p>
                <small>A BRIGHTER<br>TOMORROW.</small>
            </blockquote>
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

