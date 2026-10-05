<section class="page-card border-sky-300/20 bg-sky-300/[0.025] p-5 sm:p-6" data-study-surface="missing_context" data-study-workspace-missing-context="{{ $kind ?? 'unknown' }}">
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">{{ $eyebrow ?? 'MISSING CONTEXT' }}</p>
    <h2 class="mt-2 text-xl font-black text-slate-50">{{ $title ?? '追加情報が必要です' }}</h2>
    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-400">{{ $detail ?? '' }}</p>
    @if (! empty($action_url))
        <div class="mt-5">
            <a href="{{ $action_url }}" class="btn-primary min-h-11 px-4">{{ $action_label ?? '確認する' }}</a>
        </div>
    @endif
</section>
