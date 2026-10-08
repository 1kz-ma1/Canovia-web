@php
    $creativeCandidates = collect($developmentCreativeCandidates ?? []);
@endphp

@if ($creativeCandidates->isNotEmpty())
    <section class="page-card border-cyan-300/15 p-4 sm:p-5" data-development-creative-rescue>
        <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">EXISTING CREATIVE PLANS</p>
        <h2 class="mt-2 text-sm font-black text-slate-100">以前「制作活動」で登録した開発計画はありますか？</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">
            制作活動として登録したPlanも、自分で選べば開発Workspaceから作業できます。
            選ぶだけでは元のカテゴリや共同計画の設定を変更しません。開発以外の制作計画は選ぶ必要はありません。
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($creativeCandidates as $creativePlan)
                <form method="POST" action="{{ route('workspace.development.creative.store', $creativePlan) }}"
                    class="min-w-0 max-w-full" data-development-creative-candidate="{{ $creativePlan->id }}">
                    @csrf
                    <button type="submit"
                        class="btn-secondary inline-flex min-h-11 max-w-full items-center gap-2 px-3 text-xs"
                        aria-label="{{ $creativePlan->title }}を開発Workspaceで開く">
                        <span class="truncate">{{ $creativePlan->title }}</span>
                        @if ($creativePlan->is_collaborative)
                            <span class="shrink-0 text-[10px] text-cyan-300">共同</span>
                        @endif
                        <span aria-hidden="true" class="shrink-0">→</span>
                    </button>
                </form>
            @endforeach
        </div>
    </section>
@endif
