@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'カレンダー | Canovia')

@section('content')
    @php
        $anchor = $calendar['anchor'];
        $view = $calendar['view'];
        $selected = $calendar['selected'];
        $selectedDay = $calendar['selected_day'];
        $prev = $view === 'week' ? $anchor->copy()->subWeek() : $anchor->copy()->subMonth();
        $next = $view === 'week' ? $anchor->copy()->addWeek() : $anchor->copy()->addMonth();
    @endphp

    <div class="space-y-6">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-3xl font-black tracking-tight text-slate-50">時間の見通し</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('calendar.index', ['view' => 'month', 'date' => $anchor->format('Y-m-d'), 'selected' => $selected->format('Y-m-d')]) }}" class="btn-secondary px-3 py-2 text-xs {{ $view === 'month' ? 'calendar-view-active' : '' }}">月</a>
                <a href="{{ route('calendar.index', ['view' => 'week', 'date' => $anchor->format('Y-m-d'), 'selected' => $selected->format('Y-m-d')]) }}" class="btn-secondary px-3 py-2 text-xs {{ $view === 'week' ? 'calendar-view-active' : '' }}">週</a>
            </div>
        </header>

        @if ($calendar['configured_plan_count'] === 0 && $plans->isNotEmpty())
            <div class="assistant-notice assistant-notice-info">
                まだ作業できる時間が設定されていません。計画を更新すると、より現実的な見通しになります。
            </div>
        @endif

        <section class="page-card overflow-hidden p-3 sm:p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
                <a class="calendar-nav-button" href="{{ route('calendar.index', ['view' => $view, 'date' => $prev->format('Y-m-d'), 'selected' => $selected->format('Y-m-d')]) }}" aria-label="前へ">‹</a>
                <div class="text-center">
                    <p class="text-lg font-black text-slate-100">{{ $view === 'week' ? $calendar['start']->isoFormat('M/D').' – '.$calendar['end']->isoFormat('M/D') : $anchor->isoFormat('YYYY年 M月') }}</p>
                    <a href="{{ route('calendar.index', ['view' => $view, 'date' => today()->format('Y-m-d'), 'selected' => today()->format('Y-m-d')]) }}" class="text-xs font-bold text-sky-300">今日へ</a>
                </div>
                <a class="calendar-nav-button" href="{{ route('calendar.index', ['view' => $view, 'date' => $next->format('Y-m-d'), 'selected' => $selected->format('Y-m-d')]) }}" aria-label="次へ">›</a>
            </div>

            <div class="calendar-weekdays" aria-hidden="true">
                @foreach (['月','火','水','木','金','土','日'] as $label)<span>{{ $label }}</span>@endforeach
            </div>
            <div class="calendar-grid {{ $view === 'week' ? 'is-week' : '' }}">
                @foreach ($calendar['days'] as $day)
                    @php($isOtherMonth = $view === 'month' && !$day['date']->isSameMonth($anchor))
                    <a href="{{ route('calendar.index', ['view' => $view, 'date' => $anchor->format('Y-m-d'), 'selected' => $day['date']->format('Y-m-d')]) }}"
                       class="calendar-day {{ $day['is_today'] ? 'is-today' : '' }} {{ $day['date']->isSameDay($selected) ? 'is-selected' : '' }} {{ $isOtherMonth ? 'is-muted' : '' }}">
                        <span class="calendar-date-number">{{ $day['date']->day }}</span>
                        @if ($day['available_minutes'] > 0 || $day['actual_minutes'] > 0)
                            <span class="calendar-day-metric"><strong>{{ $day['actual_minutes'] }}</strong>/{{ $day['available_minutes'] }}分</span>
                            <span class="calendar-capacity-bar"><i style="width: {{ $day['available_minutes'] > 0 ? min(100, round(($day['actual_minutes'] / $day['available_minutes']) * 100)) : 0 }}%"></i></span>
                        @else
                            <span class="calendar-day-empty">—</span>
                        @endif
                        @if ($day['shortage_minutes'] > 0)<span class="calendar-shortage">不足 {{ $day['shortage_minutes'] }}分</span>@endif
                    </a>
                @endforeach
            </div>
        </section>

        @if ($selectedDay)
            <section class="page-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-sky-300">{{ $selectedDay['date']->isoFormat('M/D (ddd)') }}</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">使える {{ $selectedDay['available_minutes'] }}分 / 実績 {{ $selectedDay['actual_minutes'] }}分</h2>
                        <p class="mt-1 text-sm text-slate-400">残り {{ $selectedDay['remaining_minutes'] }}分・必要目安 {{ $selectedDay['recommended_minutes'] }}分</p>
                    </div>
                    @if ($selectedDay['is_today'])
                        <a href="{{ route('navigation.index') }}" class="btn-primary">今日へ</a>
                    @endif
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($selectedDay['plans'] as $planDay)
                        @continue($planDay['available_minutes'] <= 0 && $planDay['actual_minutes'] <= 0)
                        <article class="metric-card plan-identity-shell" data-plan-accent="{{ $planDay['plan']->accentKey() }}">
                            <p class="plan-identity-chip text-xs"><span aria-hidden="true">{{ $planDay['plan']->displayIcon() }}</span>{{ $planDay['plan']->title }}</p>
                            <p class="mt-2 text-sm font-bold text-slate-100">作業可能 {{ $planDay['available_minutes'] }}分</p>
                            <p class="mt-1 text-xs text-slate-400">実績 {{ $planDay['actual_minutes'] }}分 / 必要目安 {{ $planDay['recommended_minutes'] }}分</p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
