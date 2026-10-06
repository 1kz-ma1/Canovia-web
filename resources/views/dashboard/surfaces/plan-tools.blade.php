<section class="page-card pk-v18-section-card p-4 sm:p-5" data-surface-id="plan_tools">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-300">PLAN TOOLS</p>
            <h2 class="mt-1 text-base font-black text-slate-100 sm:text-lg">進めるための入口</h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">必要な機能はここに残し、今必要なものだけ上のカードへ出します。</p>
        </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        @if (
            $categoryProfile->key === 'career'
            && app(\App\Services\ReleaseLevelService::class)->allowsWorkspace(
                \App\Enums\WorkspaceMode::Career,
                auth()->user(),
                request(),
            )
        )
            <a href="{{ route('plans.career.index', $item['plan']) }}" class="btn-primary px-3 py-2 text-xs">◆ Career</a>
        @endif
        @if ($planCanEdit)
            <a href="{{ route('plans.review_assistant.show', $item['plan']) }}" class="btn-secondary px-3 py-2 text-xs">計画を更新</a>
        @endif
        <a href="{{ route('plans.resources.index', $item['plan']) }}" class="btn-secondary px-3 py-2 text-xs">関連資料{{ $item['plan']->resources->isNotEmpty() ? ' · '.$item['plan']->resources->count() : '' }}</a>
        @if ($item['plan']->artifacts->isNotEmpty() || $executionTools->contains(fn ($tool) => ($tool['id'] ?? null) === 'artifacts'))
            <a href="{{ route('plans.artifacts.index', $item['plan']) }}" class="btn-secondary px-3 py-2 text-xs">制作ファイル{{ $item['plan']->artifacts->isNotEmpty() ? ' · '.$item['plan']->artifacts->count() : '' }}</a>
        @endif
        <a href="{{ route('roadmap.index', ['plan_id' => $item['plan']->id]) }}" class="btn-secondary px-3 py-2 text-xs">{{ $categoryProfile->roadmapTitle }}</a>
        <a href="{{ route('plans.show', $item['plan']) }}" class="btn-secondary px-3 py-2 text-xs">詳細・履歴</a>
    </div>
</section>
