<section id="study-current-action" class="page-card border-violet-300/15 bg-violet-300/[0.025] p-5 sm:p-6" data-study-surface="current_action" data-study-workspace-action>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">{{ $eyebrow ?? 'CURRENT ACTION' }}</p>
    <h2 class="mt-2 text-xl font-black text-slate-50">{{ $title ?? '次のActionを確認中' }}</h2>
    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{{ $detail ?? '' }}</p>

    <div class="mt-5">
        @if (($enabled ?? true) && ($method ?? 'GET') === 'POST')
            <form method="POST" action="{{ $url }}">
                @csrf
                <button type="submit" class="btn-primary min-h-11 px-4">{{ $label ?? '進める' }}</button>
            </form>
        @elseif ($enabled ?? true)
            <a href="{{ $url }}" class="btn-primary min-h-11 px-4">{{ $label ?? '進める' }}</a>
        @else
            <span class="btn-secondary inline-flex min-h-11 items-center px-4 opacity-60">閲覧のみ</span>
        @endif
    </div>

    @if (! empty($reason))
        <details class="pk-action-details mt-5">
            <summary>なぜ今これ？</summary>
            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $reason }}</p>
        </details>
    @endif
</section>
