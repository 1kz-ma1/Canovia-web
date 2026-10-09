@extends('layouts.app')

@section('title', 'Question Pack管理 | Canovia')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">QUESTION BANK</p>
            <h1 class="mt-2 text-2xl font-black text-slate-50">Question Pack管理</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                資格別の問題データをDraftとして取り込み、確認後に公開します。公開中PackはJSON再インポートで直接上書きせず、新versionを別slugで準備してください。
            </p>
        </header>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">{{ session('status') }}</div>
        @endif

        @error('status')
            <div role="alert" class="rounded-xl border border-rose-300/30 bg-rose-950/20 p-4 text-sm text-rose-200" data-question-pack-status-error>
                {{ $message }}
            </div>
        @enderror

        <section class="page-card border-violet-300/15 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-300">BUNDLED PACKS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">Canovia同梱問題集</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                        リポジトリで管理している検証済みPackです。まずDraftへ取り込み、内容を確認してからpublishedへ変更します。
                    </p>
                </div>
                <span class="badge badge-slate">{{ ($bundledPacks ?? collect())->count() }} packs</span>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse (($bundledPacks ?? collect()) as $bundled)
                    <article class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="text-sm text-slate-100">{{ $bundled['title'] }}</strong>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    {{ $bundled['exam_code'] ?: '—' }}
                                    @if ($bundled['subject']) · {{ $bundled['subject'] }} @endif
                                    @if ($bundled['version']) · v{{ $bundled['version'] }} @endif
                                </p>
                            </div>
                            <span class="badge badge-slate">{{ $bundled['question_count'] }}問</span>
                        </div>

                        @if (data_get($bundled, 'metadata.source_period'))
                            <p class="mt-3 text-xs leading-5 text-slate-400">
                                出典：{{ data_get($bundled, 'metadata.source_period') }}
                            </p>
                        @endif

                        @if (data_get($bundled, 'metadata.license_note'))
                            <p class="mt-2 text-[11px] leading-5 text-slate-500">
                                {{ data_get($bundled, 'metadata.license_note') }}
                            </p>
                        @endif

                        <form method="POST" action="{{ route('admin.question_packs.import_bundled') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="catalog_key" value="{{ $bundled['key'] }}">
                            <button type="submit" class="btn-secondary">Draftへ取り込む</button>
                        </form>
                    </article>
                @empty
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4 text-sm text-slate-500">
                        同梱Question Packはまだありません。
                    </div>
                @endforelse
            </div>

            @error('catalog_key')
                <p class="mt-3 text-sm font-semibold text-rose-300">{{ $message }}</p>
            @enderror
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.05fr_.95fr]">
            <div class="page-card p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-cyan-300">IMPORT</p>
                        <h2 class="mt-1 text-xl font-black text-slate-50">Question Pack JSONを取り込む</h2>
                    </div>
                    <span class="badge badge-slate">Draft</span>
                </div>

                <form method="POST" action="{{ route('admin.question_packs.import') }}" class="mt-5">
                    @csrf
                    <textarea
                        name="pack_json"
                        class="form-control min-h-[560px] font-mono text-xs leading-5"
                        spellcheck="false"
                    >{{ old('pack_json', $importTemplate) }}</textarea>
                    @error('pack_json')
                        <p class="mt-3 text-sm font-semibold text-rose-300">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="btn-primary mt-4">Draftへ取り込む</button>
                </form>

                <div class="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-6 text-slate-400">
                    <strong class="text-amber-100">公開前確認：</strong>
                    問題文・図表・解説の利用条件、source_reference、正答、grading_rule、learning_metadataを確認してください。
                    <code>official</code> / <code>licensed</code> / <code>derived</code> はsource_reference必須です。
                </div>
            </div>

            <div class="space-y-4">
                <div class="page-card p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-300">PACKS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">登録済みPack</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">AI演習で自動利用されるのはpublishedだけです。</p>
                </div>

                @forelse ($packs as $pack)
                    <article class="page-card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-slate-100">{{ $pack->title }}</strong>
                                    <span class="badge {{ $pack->status === 'published' ? 'badge-green' : 'badge-slate' }}">{{ $pack->status }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $pack->slug }} · v{{ $pack->version }}
                                    @if ($pack->exam_code) · {{ $pack->exam_code }} @endif
                                    @if ($pack->subject) · {{ $pack->subject }} @endif
                                </p>
                            </div>
                            <div class="text-right">
                                <strong class="text-lg text-slate-100">{{ $pack->active_questions_count }}</strong>
                                <p class="text-[10px] text-slate-500">active / {{ $pack->questions_count }} total</p>
                            </div>
                        </div>

                        @if (collect(data_get($pack->metadata, 'match_terms', []))->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (data_get($pack->metadata, 'match_terms', []) as $term)
                                    <span class="badge badge-slate">{{ $term }}</span>
                                @endforeach
                            </div>
                        @endif

                        @php($inspection = ($publicationReadiness ?? collect())->get($pack->id))
                        @if ($inspection)
                            <section class="mt-4 rounded-xl border border-slate-700 p-4" data-question-pack-readiness="{{ $pack->id }}">
                                <p class="text-xs font-bold text-slate-200">公開前チェック · 1問ずつ学習への対応</p>
                                <p class="mt-2 text-sm text-slate-300">
                                    有効 {{ $inspection['active_count'] }}問 /
                                    1問採点対応 <strong>{{ $inspection['one_question_count'] }}問</strong>
                                </p>
                                <p class="mt-1 text-xs {{ $inspection['publishable'] ? 'text-emerald-300' : 'text-rose-300' }}">
                                    {{ $inspection['publishable'] ? '公開条件：充足（内容・権利は管理者が最終確認）' : '公開条件：未充足' }}
                                </p>
                                @if ($inspection['blocking'] !== [])
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-300" data-question-pack-blockers>
                                        @foreach ($inspection['blocking'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($inspection['warnings'] !== [])
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-amber-200" data-question-pack-warnings>
                                        @foreach ($inspection['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-2 text-[11px] leading-5 text-slate-500">
                                    対応問数は現行Learning Runの単一選択・即時採点に基づきます。
                                    問題の著作権・正答の妥当性は自動判定していません。公開は明示操作のみです。
                                </p>
                            </section>
                        @endif

                        <form method="POST" action="{{ route('admin.question_packs.status', $pack) }}" class="mt-4 flex flex-wrap items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <select name="status" class="form-control max-w-[180px]">
                                @foreach (\App\Models\QuestionPack::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($pack->status === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary">状態を更新</button>
                        </form>
                    </article>
                @empty
                    <div class="page-card p-6 text-sm text-slate-500">まだQuestion Packはありません。</div>
                @endforelse

                {{ $packs->links() }}
            </div>
        </section>
    </div>
@endsection
