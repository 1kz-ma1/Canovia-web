@php
    $kindCounts = $overview['kind_counts'];
    $workflowCounts = $overview['workflow_counts'];
    $recentItems = $overview['recent_items'];
    $linkedTasks = $overview['linked_tasks'];
@endphp

<article
    class="overflow-hidden rounded-3xl border border-violet-300/15 bg-slate-950/50 shadow-xl shadow-slate-950/20"
    data-github-repository-overview
    data-github-repository="{{ $overview['repo_full_name'] }}"
>
    <div class="border-b border-white/8 bg-gradient-to-r from-violet-300/[0.055] via-cyan-300/[0.025] to-transparent p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-violet-300/20 bg-violet-300/[0.07] px-2 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-violet-200">
                        Repository
                    </span>
                    @if (! $overview['repository_registered'])
                        <span class="rounded-full border border-slate-700 px-2 py-1 text-[10px] text-slate-500">関連URLから認識</span>
                    @endif
                </div>
                <h3 class="mt-3 break-words text-lg font-black text-slate-50 md:text-xl">{{ $overview['repo_full_name'] }}</h3>
                <p class="mt-1 text-xs text-slate-500">{{ $overview['plan_icon'] }} {{ $overview['plan_title'] }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ $overview['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn-secondary px-3 py-2 text-xs"
                >Repositoryを開く ↗</a>
                @if ($overview['details_url'])
                    <a href="{{ $overview['details_url'] }}" class="btn-secondary px-3 py-2 text-xs">Artifact詳細</a>
                @endif
            </div>
        </div>

        <p class="mt-4 max-w-3xl text-xs leading-6 text-slate-400">
            Repositoryは「今やる」項目ではなく、この開発のルートとして表示します。
            下の作業レーンにはPR / Issue / Branchなど、次の判断が必要なものだけを出します。
        </p>
    </div>

    <div class="grid gap-0 lg:grid-cols-[1fr_1fr_1.15fr]">
        <section class="border-b border-white/8 p-5 lg:border-b-0 lg:border-r">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">KNOWN STRUCTURE</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">Canoviaが把握しているGitHub構造</h4>

            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach ([
                    ['PR', $kindCounts['pull_request']],
                    ['Issue', $kindCounts['issue']],
                    ['Branch', $kindCounts['branch']],
                    ['Commit', $kindCounts['commit']],
                    ['Actions', $kindCounts['actions_run']],
                    ['Other', $kindCounts['other']],
                ] as [$label, $count])
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] px-3 py-2">
                        <strong class="block text-base text-slate-100">{{ $count }}</strong>
                        <span class="text-[10px] text-slate-600">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            @if ($overview['work_count'] === 0)
                <div class="mt-4 rounded-xl border border-dashed border-cyan-300/15 bg-cyan-300/[0.02] p-3">
                    <p class="text-xs font-bold text-cyan-100">Repositoryだけ登録されています</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        PR / Issue / BranchのURLを追加すると、同じRepositoryの構造としてここへまとまり、作業レーンにも反映されます。
                    </p>
                </div>
            @endif
        </section>

        <section class="border-b border-white/8 p-5 lg:border-b-0 lg:border-r">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CANOVIA FLOW</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">今どこで止まっているか</h4>

            <div class="mt-4 space-y-2">
                @foreach ([
                    ['now', '今やる'],
                    ['review', 'レビュー待ち'],
                    ['changes', '修正必要'],
                    ['merge', 'マージ待ち'],
                    ['done', '完了'],
                    ['unclassified', '未整理'],
                ] as [$key, $label])
                    <div class="flex items-center justify-between rounded-xl border border-white/8 bg-white/[0.02] px-3 py-2">
                        <span class="text-xs text-slate-400">{{ $label }}</span>
                        <strong class="text-xs text-slate-100">{{ $workflowCounts[$key] }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="p-5">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">CONNECTED WORK</p>
            <h4 class="mt-1 text-sm font-black text-slate-100">このRepositoryにつながる作業</h4>

            @if ($linkedTasks->isNotEmpty())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($linkedTasks->take(5) as $task)
                        <span class="rounded-full border border-emerald-300/12 bg-emerald-300/[0.025] px-2.5 py-1.5 text-[11px] text-slate-300">
                            {{ $task['title'] }}
                        </span>
                    @endforeach
                    @if ($linkedTasks->count() > 5)
                        <span class="rounded-full border border-slate-800 px-2.5 py-1.5 text-[11px] text-slate-500">+{{ $linkedTasks->count() - 5 }}</span>
                    @endif
                </div>
            @else
                <p class="mt-4 text-xs leading-5 text-slate-500">まだTaskとの明示的な紐付けはありません。</p>
            @endif

            @if ($recentItems->isNotEmpty())
                <div class="mt-5 space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">RECENT REFERENCES</p>
                    @foreach ($recentItems as $item)
                        <a
                            href="#github-item-{{ $item['id'] }}"
                            class="flex items-center justify-between gap-3 rounded-xl border border-white/8 bg-white/[0.02] px-3 py-2 transition hover:border-cyan-300/20"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-semibold text-slate-300">{{ $item['reference'] }}</span>
                                <span class="mt-0.5 block truncate text-[10px] text-slate-600">{{ $item['title'] }}</span>
                            </span>
                            <span class="shrink-0 text-[10px] text-slate-500">{{ $item['workflow_state_label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <div class="border-t border-white/8 px-5 py-3">
        <p class="text-[10px] leading-5 text-slate-600">
            現在の全体像はCanoviaへ登録済みのGitHub URLから構成しています。GitHub API未接続のため、Repository URLだけから未登録のBranch / PR / Issueやremote statusを推測しません。
        </p>
    </div>
</article>
