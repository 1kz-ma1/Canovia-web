@extends(request()->header('X-Canovia-Instant-Navigation') ? 'layouts.instant' : 'layouts.app')

@section('title', 'タイムライン | Canovia')

@section('content')
    <div class="space-y-6">
        <header class="pk-v18-page-hero pk-v18-timeline-hero">
            <div class="relative z-10">
                <p class="pk-v18-eyebrow">TIMELINE / LOOK BACK</p>
                <h1>歩いてきた軌道。</h1>
                <p>積み上げた一歩を、次の未来へつなげる。</p>
            </div>
            <div class="pk-v18-timeline-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
        </header>

        @forelse ($items as $date => $group)
            <section class="timeline-day page-card pk-v18-timeline-day p-3.5 sm:p-5">
                <div class="timeline-day-heading">
                    <span class="timeline-dot" aria-hidden="true"></span>
                    <h2 class="font-black text-slate-100">{{ \Carbon\Carbon::parse($date)->isoFormat('M/D (ddd)') }}</h2>
                </div>
                <div class="mt-4 space-y-3">
                    @foreach ($group as $item)
                        @php($plan = $item['plan'])
                        @php($log = $item['log'])
                        <article class="timeline-entry pk-v18-timeline-entry plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
                            <div class="min-w-0">
                                <p class="plan-identity-chip text-xs"><span aria-hidden="true">{{ $plan->displayIcon() }}</span>{{ $plan->title }}</p>
                                <h3 class="mt-1 truncate font-bold text-slate-100">{{ $log->task?->title ?? $log->task_title_snapshot ?? '計画全体' }}</h3>
                                @if ($log->memo)<p class="mt-1 text-sm leading-6 text-slate-400">{{ $log->memo }}</p>@endif
                            </div>
                            <span class="badge badge-slate shrink-0">{{ $log->actual_minutes }}分</span>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="empty-state page-card p-8 text-center">
                <div class="text-4xl" aria-hidden="true">◷</div>
                <h2 class="mt-3 text-xl font-bold text-slate-100">まだ履歴はありません</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">作業を終えると、ここに記録が残ります。</p>
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
