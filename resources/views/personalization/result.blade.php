@extends('layouts.app')

@section('title', 'Canoviaの準備 | Canovia')

@section('content')
@php
    $guidance = (string) data_get($context, 'guidance_level', 'standard');
    $githubInterest = data_get($context, 'feature_readiness.github.interest');
@endphp

<div class="mx-auto max-w-5xl space-y-5 pb-12" data-personalization-result>
    <header class="rounded-[1.7rem] border border-emerald-300/15 bg-slate-950/70 p-5 sm:p-7">
        <p class="text-[10px] font-black uppercase tracking-[.18em] text-emerald-300">READY</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">Canoviaの準備ができました</h1>
        <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-400">
            完成した計画ではなく、始めやすい初期骨格を用意しました。選んだ後もタイトル・期限・進め方は変更できます。
        </p>
    </header>

    @if ($seeds !== [])
        <section class="space-y-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">PLAN SEED</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">最初におすすめ</h2>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($seeds as $seed)
                    <article class="page-card p-5 sm:p-6" data-plan-seed="{{ $seed['key'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[.16em] {{ $seed['domain'] === 'study' ? 'text-amber-300' : 'text-cyan-300' }}">
                                    {{ strtoupper($seed['domain']) }}
                                </p>
                                <h3 class="mt-1 text-lg font-black text-slate-100">{{ $seed['title'] }}</h3>
                            </div>
                            <span class="rounded-full border border-slate-800 bg-slate-950/35 px-2.5 py-1 text-[10px] text-slate-500">編集可能</span>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            @foreach ($seed['phases'] as $phase)
                                <span class="rounded-lg border border-slate-800 bg-slate-950/35 px-2.5 py-1.5 text-[11px] text-slate-400">{{ $phase }}</span>
                                @if (! $loop->last)
                                    <span class="text-slate-700" aria-hidden="true">→</span>
                                @endif
                            @endforeach
                        </div>

                        @if ($seed['deadline'])
                            <p class="mt-4 text-xs text-slate-500">期限: {{ $seed['deadline'] }}</p>
                        @endif

                        <form method="POST" action="{{ route('personalization.seed.accept', ['seedKey' => $seed['key']]) }}" class="mt-5">
                            @csrf
                            <button type="submit" class="btn-primary w-full justify-center">この型からPlanを作る</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>
    @else
        <section class="page-card p-5 sm:p-6">
            <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">START LIGHT</p>
            <h2 class="mt-1 text-lg font-black text-slate-100">まだ決めなくて大丈夫です</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">
                今気になっていることをCanoviaに話すところから始められます。カテゴリは利用しながら後で調整できます。
            </p>
            <a href="{{ route('plans.create') }}" class="btn-primary mt-4">Canoviaに話してみる</a>
        </section>
    @endif

    @if ($githubPreview)
        <section class="page-card border-violet-300/15 p-5 sm:p-6" data-capability-preview="github_integration">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">OPTIONAL CAPABILITY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">{{ $githubPreview['title'] }}</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">設定方法ではなく、まず使った後の変化だけ確認してください。</p>
                </div>
                <span class="rounded-full border border-violet-300/15 bg-violet-300/[0.04] px-3 py-1 text-[10px] font-bold text-violet-200">GitHub</span>
            </div>

            <div class="mt-5 grid gap-2 sm:grid-cols-3">
                @foreach ($githubPreview['steps'] as $step)
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3 text-xs leading-5 text-slate-300">
                        <span class="mb-2 grid h-6 w-6 place-items-center rounded-full border border-violet-300/15 text-[10px] font-black text-violet-200">{{ $loop->iteration }}</span>
                        {{ $step }}
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-[11px] leading-5 text-slate-500">{{ $githubPreview['note'] }}</p>

            <form method="POST" action="{{ route('personalization.capability.interest', ['capability' => 'github_integration']) }}" class="mt-4 flex flex-col gap-2 sm:flex-row">
                @csrf
                <button type="submit" name="interest" value="yes" class="{{ $githubInterest === 'yes' ? 'btn-primary' : 'btn-secondary' }} justify-center">
                    使ってみたい
                </button>
                <button type="submit" name="interest" value="no" class="{{ $githubInterest === 'no' ? 'btn-primary' : 'btn-secondary' }} justify-center">
                    今はいい
                </button>
            </form>
        </section>
    @endif

    <section class="flex flex-col gap-2 border-t border-slate-800/70 pt-5 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('personalization.show') }}" class="text-xs font-bold text-slate-500 hover:text-slate-300">← 回答を調整する</a>
        <div class="flex flex-wrap gap-2">
            @auth
                <a href="{{ route('home') }}" class="btn-secondary">Homeへ</a>
            @endauth
            <a href="{{ route('plans.create') }}" class="btn-secondary">0から相談する</a>
        </div>
    </section>
</div>
@endsection
