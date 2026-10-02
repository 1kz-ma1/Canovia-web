@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'タイムライン | Canovia')

@section('content')
    <div class="space-y-6" data-timeline-surface data-timeline-schema="2">
        <header class="pk-v18-page-hero pk-v18-timeline-hero">
            <div class="relative z-10">
                <p class="pk-v18-eyebrow">TIMELINE / LOOK BACK</p>
                <h1>歩いてきた軌道。</h1>
                <p>作業、共同の変化、達成した節目をひとつの流れで振り返る。</p>
            </div>
            <div class="pk-v18-timeline-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
        </header>

        @if (($achievementConstellation ?? collect())->isNotEmpty())
            <section class="page-card overflow-hidden p-4 sm:p-5" data-achievement-constellation>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-black tracking-[0.18em] text-emerald-300">ACHIEVEMENT CONSTELLATION</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">積み上げて完成した星座</h2>
                        <p class="mt-1 text-sm text-slate-400">達成したPlanは、Timelineに消えずに残ります。</p>
                    </div>
                    <a href="{{ route('achievements.index') }}" class="btn-secondary">達成一覧</a>
                </div>

                <div class="relative mt-4 h-64 overflow-hidden rounded-3xl border border-slate-800 bg-slate-950/70 sm:h-72">
                    <div class="absolute inset-0 opacity-60" aria-hidden="true">
                        <span class="absolute left-1/2 top-1/2 h-32 w-32 -translate-x-1/2 -translate-y-1/2 rounded-full border border-slate-800"></span>
                        <span class="absolute left-1/2 top-1/2 h-56 w-56 -translate-x-1/2 -translate-y-1/2 rounded-full border border-slate-900"></span>
                    </div>

                    @foreach ($achievementConstellation->take(16) as $achievement)
                        @php($plan = $achievement['plan'])
                        @php($position = $achievement['constellation'])
                        <a
                            href="{{ route('achievements.show', $plan) }}"
                            class="group absolute -translate-x-1/2 -translate-y-1/2 text-center"
                            style="left: {{ $position['x'] }}%; top: {{ $position['y'] }}%;"
                            data-achievement-star
                            data-achievement-plan-id="{{ $plan->id }}"
                            aria-label="{{ $plan->title }}の達成記録を見る"
                        >
                            <span
                                class="mx-auto block h-4 w-4 rounded-full border border-emerald-200/70 bg-emerald-300 shadow-[0_0_24px_rgba(110,231,183,.5)] transition group-hover:scale-125"
                                aria-hidden="true"
                            ></span>
                            <span class="mt-2 block max-w-28 truncate text-[11px] font-bold text-slate-300 group-hover:text-white">
                                {{ $plan->title }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @forelse ($items as $date => $group)
            <section class="timeline-day page-card pk-v18-timeline-day p-3.5 sm:p-5">
                <div class="timeline-day-heading">
                    <span class="timeline-dot" aria-hidden="true"></span>
                    <h2 class="font-black text-slate-100">
                        {{ $date === '日付不明' ? $date : \Carbon\Carbon::parse($date)->isoFormat('M/D (ddd)') }}
                    </h2>
                </div>

                <div class="mt-4 space-y-3">
                    @foreach ($group as $event)
                        @php($plan = $event['plan'])
                        <article
                            class="timeline-entry pk-v18-timeline-entry plan-identity-shell"
                            data-plan-accent="{{ $plan->accentKey() }}"
                            data-timeline-event
                            data-timeline-event-kind="{{ $event['kind'] }}"
                            data-timeline-plan-id="{{ $plan->id }}"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="plan-identity-chip text-xs">
                                        <span aria-hidden="true">{{ $plan->displayIcon() }}</span>{{ $plan->title }}
                                    </p>

                                    @if ($event['kind'] === 'plan_completed')
                                        <span class="badge badge-green">ACHIEVEMENT</span>
                                    @elseif ($event['kind'] === 'collaboration')
                                        <span class="badge badge-slate">COLLABORATION</span>
                                    @endif
                                </div>

                                <h3 class="mt-1 font-bold text-slate-100">{{ $event['title'] }}</h3>

                                @if (filled($event['summary']))
                                    <p class="mt-1 text-sm leading-6 text-slate-400">{{ $event['summary'] }}</p>
                                @endif

                                @if ($event['kind'] !== 'work')
                                    <a href="{{ $event['url'] }}" class="mt-2 inline-flex text-xs font-bold text-cyan-300 hover:text-cyan-200">
                                        {{ $event['kind'] === 'plan_completed' ? '達成記録を見る' : 'Planを開く' }}
                                    </a>
                                @endif
                            </div>

                            @if ($event['kind'] === 'work')
                                <span class="badge badge-slate shrink-0">{{ $event['actual_minutes'] }}分</span>
                            @else
                                <time class="shrink-0 text-xs text-slate-500" datetime="{{ $event['occurred_at']?->toIso8601String() }}">
                                    {{ $event['occurred_at']?->format('H:i') }}
                                </time>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="empty-state page-card p-8 text-center">
                <div class="text-4xl" aria-hidden="true">◷</div>
                <h2 class="mt-3 text-xl font-bold text-slate-100">まだ履歴はありません</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">作業や共同の変化、Planの達成がここに積み上がります。</p>
                <a href="{{ route('navigation.index') }}" class="btn-primary mt-5">今日へ</a>
            </section>
        @endforelse

        @if (($similarPlans ?? collect())->isNotEmpty())
            <section class="page-card p-4 sm:p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-slate-100">似た目標を進めている人</h2>
                    <span class="text-xs text-slate-500">ちょっと覗いてみる</span>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    @foreach ($similarPlans as $similarPlan)
                        <a href="{{ route('public_plans.show', $similarPlan->public_slug) }}" class="home-plan-card plan-identity-shell block" data-plan-accent="{{ $similarPlan->accentKey() }}">
                            <div class="flex items-start justify-between gap-3">
                                <span class="plan-identity-icon" aria-hidden="true">{{ $similarPlan->displayIcon() }}</span>
                                <span class="badge badge-slate">{{ $similarPlan->category ?: '計画' }}</span>
                            </div>
                            <h3 class="mt-3 line-clamp-2 font-black text-slate-100">{{ $similarPlan->title }}</h3>
                            <p class="mt-2 text-xs text-slate-400">{{ $similarPlan->user?->name ?: 'Canoviaユーザー' }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
