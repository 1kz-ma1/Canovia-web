@php
    $repositoryTree = is_array($developmentRepositoryTree ?? null)
        ? $developmentRepositoryTree
        : null;
    $repositoryEntries = collect(data_get($repositoryTree, 'entries', []));
    $repositoryTreeError = trim((string) ($developmentRepositoryTreeError ?? ''));
@endphp

<div class="space-y-4" data-development-surface-panel="repository">
    <section class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(18rem,.55fr)]">
        <article class="page-card overflow-hidden p-0">
            <div class="flex flex-col gap-3 border-b border-white/8 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">REPOSITORY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">
                        {{ data_get($repositoryTree, 'repo_full_name') ?: ($githubRepository?->title ?? 'Repository') }}
                    </h2>
                    @if ($repositoryTree)
                        <p class="mt-1 text-xs text-slate-500">
                            {{ data_get($repositoryTree, 'default_branch', 'main') }}
                            @if (data_get($repositoryTree, 'head_sha'))
                                · {{ mb_substr((string) data_get($repositoryTree, 'head_sha'), 0, 10) }}
                            @endif
                            · {{ data_get($repositoryTree, 'visibility', 'unknown') }}
                        </p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">GitHubのdirectory構成をここで確認します。</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @if (data_get($repositoryTree, 'repository_url'))
                        <a href="{{ data_get($repositoryTree, 'repository_url') }}" target="_blank" rel="noopener noreferrer" class="btn-secondary min-h-9 px-3 text-xs">
                            GitHubで開く ↗
                        </a>
                    @endif
                    <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}" class="btn-secondary min-h-9 px-3 text-xs">
                        GitHub / Evidence
                    </a>
                </div>
            </div>

            @if ($repositoryTree)
                <div class="border-b border-white/8 px-4 py-3">
                    <input
                        type="search"
                        class="form-control py-2 text-xs"
                        placeholder="pathを絞り込む"
                        data-development-repository-filter
                    >
                </div>

                <div class="max-h-[66vh] overflow-auto p-2 sm:p-3" data-development-repository-tree>
                    @forelse ($repositoryEntries as $entry)
                        @php
                            $depth = min(10, max(0, (int) data_get($entry, 'depth', 0)));
                            $isDirectory = data_get($entry, 'type') === 'directory';
                            $searchText = mb_strtolower((string) data_get($entry, 'path', ''));
                        @endphp
                        <a
                            href="{{ data_get($entry, 'url') }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="group flex min-h-8 items-center gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-white/[0.04]"
                            style="padding-left: {{ 0.55 + ($depth * 0.9) }}rem"
                            data-development-repository-entry
                            data-repository-path="{{ $searchText }}"
                        >
                            <span class="w-4 shrink-0 text-center {{ $isDirectory ? 'text-cyan-300' : 'text-slate-600' }}" aria-hidden="true">
                                {{ $isDirectory ? '▸' : '·' }}
                            </span>
                            <span class="min-w-0 flex-1 truncate {{ $isDirectory ? 'font-black text-slate-200' : 'text-slate-400' }}">
                                {{ data_get($entry, 'name') }}
                            </span>
                            @if (! $isDirectory && data_get($entry, 'size') !== null)
                                <span class="shrink-0 text-[9px] text-slate-700">{{ number_format((int) data_get($entry, 'size')) }} B</span>
                            @endif
                        </a>
                    @empty
                        <div class="empty-state m-3">表示できるdirectory / fileがありません。</div>
                    @endforelse
                </div>

                @if (data_get($repositoryTree, 'truncated'))
                    <div class="border-t border-white/8 px-4 py-3 text-[10px] leading-4 text-amber-200/80">
                        Repositoryが大きいため、Canoviaでは先頭{{ (int) data_get($repositoryTree, 'limit', 320) }}件まで表示しています。
                    </div>
                @endif
            @elseif ($repositoryTreeError !== '')
                <div class="p-5">
                    <div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.025] p-4">
                        <p class="text-sm font-black text-amber-100">directory構成を取得できませんでした。</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $repositoryTreeError }}</p>
                    </div>
                </div>
            @else
                <div class="p-5">
                    <div class="empty-state">
                        Repositoryを接続すると、source code本文を保存せずにdirectory構成だけをここで確認できます。
                    </div>
                </div>
            @endif
        </article>

        <aside class="space-y-4">
            <article class="page-card p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">CONNECTION</p>
                <div class="mt-2 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-black text-slate-100">
                            {{ data_get($githubConnection, 'label', $githubRepository ? '接続状態を確認' : 'Repository未登録') }}
                        </p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">
                            {{ data_get($githubConnection, 'detail', 'GitHub / EvidenceからRepositoryを登録できます。') }}
                        </p>
                    </div>
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ data_get($githubConnection, 'state') === 'ready' ? 'bg-emerald-300' : 'bg-amber-300' }}"></span>
                </div>
            </article>

            <article class="page-card p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">ACTIVITY</p>
                        <h3 class="mt-1 text-sm font-black text-slate-100">最近のGitHub</h3>
                    </div>
                    <span class="badge badge-slate">{{ $recentActivity->count() }}件</span>
                </div>

                <div class="mt-3 space-y-2">
                    @forelse ($recentActivity->take(6) as $observation)
                        <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] font-black text-cyan-300">
                                    {{ $observationKindLabels[$observation->kind] ?? strtoupper((string) $observation->kind) }}
                                </span>
                                @if ($unresolvedActivityIds->contains((int) $observation->id))
                                    <span class="text-[9px] font-black text-amber-200">未関連付け</span>
                                @endif
                            </div>
                            <p class="mt-1 truncate text-xs font-bold text-slate-300">{{ $observation->title ?: $observation->reference }}</p>
                        </div>
                    @empty
                        <p class="text-xs leading-5 text-slate-600">まだGitHub Activityはありません。</p>
                    @endforelse
                </div>
            </article>
        </aside>
    </section>

    <details class="page-card p-4 sm:p-5">
        <summary class="cursor-pointer list-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">ACTIVITY / ASSOCIATION</p>
                    <h3 class="mt-1 text-sm font-black text-slate-100">GitHub Activityの関連付け</h3>
                </div>
                <span class="badge badge-slate">{{ $unresolvedActivity->count() }}件未整理</span>
            </div>
        </summary>
        <div class="mt-4">
            @include('workspace.development.surfaces.partials.github-activity')
        </div>
    </details>

    <details class="page-card p-4 sm:p-5">
        <summary class="cursor-pointer list-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RELEASE</p>
                    <h3 class="mt-1 text-sm font-black text-slate-100">Release Readiness</h3>
                </div>
                <span class="text-xs font-black text-slate-500">{{ $presentation?->readinessDisplay() ?? '未判定' }}</span>
            </div>
        </summary>
        <div class="mt-4">
            @include('workspace.development.surfaces.partials.readiness')
        </div>
    </details>
</div>

<script>
    (() => {
        const input = document.querySelector('[data-development-repository-filter]');
        if (!input) return;

        const entries = [...document.querySelectorAll('[data-development-repository-entry]')];

        input.addEventListener('input', () => {
            const query = String(input.value || '').trim().toLowerCase();

            entries.forEach((entry) => {
                const path = String(entry.dataset.repositoryPath || '');
                entry.hidden = query !== '' && !path.includes(query);
            });
        });
    })();
</script>
