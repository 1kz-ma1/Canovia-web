@php
    $roadmapSnapshot = is_array($developmentRoadmap ?? null) ? $developmentRoadmap : null;
    $roadmapRows = collect(data_get($roadmapSnapshot, 'workstreams', []));
    $roadmapSections = collect(data_get($roadmapSnapshot, 'sections', []));
    $roadmapWarnings = collect(data_get($roadmapSnapshot, 'warnings', []));
    $roadmapError = trim((string) ($developmentRoadmapError ?? ''));
    $sourceRepository = (string) data_get($roadmapSnapshot, 'source.repository', '');
    $sourceSha = (string) data_get($roadmapSnapshot, 'source.sha', '');
    $sourceUrl = $sourceRepository !== '' && $sourceSha !== ''
        ? 'https://github.com/'.$sourceRepository.'/blob/'.$sourceSha.'/docs/development/ROADMAP.md'
        : null;
@endphp

<div class="space-y-4" data-development-surface-panel="roadmap" data-development-roadmap>
    <section class="page-card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">GITHUB-NATIVE ROADMAP · READ ONLY</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">開発ロードマップ</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">GitHubの仕様書を参照しています。計画上の記述は実装・CI・デプロイ・実機検証の証拠とは別です。CanoviaのTaskは変更しません。</p>
            </div>
            @if ($sourceUrl)
                <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer" class="btn-secondary min-h-9 px-3 text-xs">仕様書をGitHubで開く ↗</a>
            @endif
        </div>

        @if ($roadmapSnapshot)
            <p class="mt-3 break-all text-[11px] text-slate-500">
                {{ $sourceRepository }} · {{ data_get($roadmapSnapshot, 'source.path') }} · {{ mb_substr($sourceSha, 0, 12) }}
            </p>
            @foreach ($roadmapWarnings as $warning)
                <p class="mt-2 text-xs text-amber-200">{{ $warning }}</p>
            @endforeach
        @elseif ($roadmapError !== '')
            <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[0.025] p-4">
                <p class="text-sm font-bold text-amber-100">ロードマップを読み込めませんでした。</p>
                <p class="mt-1 text-xs leading-5 text-slate-400">{{ $roadmapError }}</p>
            </div>
        @else
            <div class="mt-4 rounded-xl border border-white/10 p-4 text-xs leading-5 text-slate-400">
                Repositoryの登録とGitHub App接続、閲覧権限を確認してください。未接続のリポジトリから計画を推測したり、Taskを作成したりしません。
                @if ($plan)
                    <a class="mt-2 block font-bold text-cyan-300" href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}">GitHub接続を確認する ↗</a>
                @endif
            </div>
        @endif
    </section>

    @if ($roadmapSnapshot)
        <section class="page-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-black text-slate-100">開発項目</h3>
                <span class="badge badge-slate">{{ $roadmapRows->count() }}項目 · 状態は未検証</span>
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse ($roadmapRows as $row)
                    <article class="rounded-xl border border-white/10 bg-slate-950/25 p-4" data-development-roadmap-item>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge badge-slate">{{ data_get($row, 'priority', 'UNSPECIFIED') }}</span>
                            <span class="text-[10px] font-bold text-amber-200">GitHub記述 · 検証前</span>
                        </div>
                        <h4 class="mt-2 text-sm font-black text-slate-100">{{ data_get($row, 'title', '') }}</h4>
                        <p class="mt-3 text-[11px] font-bold text-slate-400">仕様書に記載された実装Evidence</p>
                        <p class="mt-1 text-xs leading-5 text-slate-300">{{ data_get($row, 'evidence', '') }}</p>
                        <p class="mt-3 text-[11px] font-bold text-slate-400">残作業・検証条件</p>
                        <p class="mt-1 text-xs leading-5 text-slate-300">{{ data_get($row, 'next', '') }}</p>
                    </article>
                @empty
                    <p class="text-xs text-slate-400">定義済みの開発項目テーブルはありません。仕様書の箇条書きを以下で確認できます。</p>
                @endforelse
            </div>
        </section>

        @foreach ($roadmapSections as $section)
            @php $entries = collect(data_get($section, 'entries', [])); @endphp
            @if ($entries->isNotEmpty())
                <section class="page-card p-5">
                    <h3 class="text-sm font-black text-slate-100">{{ data_get($section, 'title') }}</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($entries as $entry)
                            <li class="rounded-lg border border-white/8 bg-slate-950/20 px-3 py-2 text-xs leading-5 text-slate-300">{{ data_get($entry, 'text', '') }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    @endif
</div>
