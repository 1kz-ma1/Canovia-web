@php
    $summaryClass = $summaryClass ?? 'mb-8 mobile-metric-strip md:grid md:grid-cols-2 lg:grid-cols-4';
    $summaryStatus = (string) ($progress['status'] ?? '予定通り');
    $summaryStatusClass = match ($summaryStatus) {
        '順調' => 'status-green',
        '予定通り' => 'status-blue',
        '遅れ気味' => 'status-amber',
        '期限切れ', '作業時間不足' => 'status-red',
        default => 'status-slate',
    };
@endphp

<section class="{{ $summaryClass }}" data-plan-summary-metrics>
    <div class="info-card p-5">
        <p class="text-sm text-slate-500">期間</p>
        <p class="mt-2 font-bold text-slate-900">{{ $plan->start_date->format('Y-m-d') }} 〜 {{ $plan->deadline?->format('Y-m-d') ?? '期限未設定' }}</p>
    </div>
    <div class="info-card p-5">
        <p class="text-sm text-slate-500">残り日数</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ $progress['remaining_days'] === null ? '—' : $progress['remaining_days'] . '日' }}</p>
    </div>
    <div class="info-card p-5">
        <p class="text-sm text-slate-500">{{ ($progress['availability_configured'] ?? false) ? '今日の作業目安' : '1日あたり必要時間' }}</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ $progress['remaining_days'] === null ? '—' : $progress['daily_required_minutes'] . '分' }}</p>
        @if (($progress['availability_configured'] ?? false))
            <p class="mt-1 text-xs text-slate-500">作業可能 {{ $progress['today_available_minutes'] ?? 0 }}分</p>
        @endif
    </div>
    <div class="info-card p-5">
        <p class="text-sm text-slate-500">状態</p>
        <p class="mt-3"><span class="status-pill {{ $summaryStatusClass }}">{{ $summaryStatus }}</span></p>
    </div>
</section>
