@extends('layouts.app')

@section('title', 'プラン・機能プレビュー | Canovia')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 pb-10" data-product-preview>
        <header class="rounded-[1.8rem] border border-cyan-300/15 bg-slate-950/55 p-5 shadow-[0_20px_60px_rgba(2,6,23,.22)] sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">CANOVIA EARLY ACCESS</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">Canoviaが引き受ける範囲が、どう広がるか</h1>
                    <p class="mt-3 text-sm leading-7 text-slate-400">
                        FreeでもGoalから実行・記録まで進められます。上位プランでは、判断、Context理解、公開後の改善まで、
                        Canoviaがより多くの負担を引き受ける方向で設計しています。
                    </p>
                </div>

                <div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-6 text-amber-100 lg:max-w-sm">
                    <p class="font-black">Early Access</p>
                    <p class="mt-1 text-amber-100/75">
                        現在実利用できるのはFreeです。Premium / Pro / Dev ProはComing Soonで、価格・正式提供時期は未定です。
                    </p>
                </div>
            </div>
        </header>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" aria-label="Canoviaプラン">
            @foreach ($tiers as $key => $tier)
                @php
                    $isAvailable = ($tier['status'] ?? null) === 'available';
                    $isDevPro = $key === 'dev_pro';
                @endphp
                <article
                    class="page-card relative overflow-hidden p-5 sm:p-6 {{ $isAvailable ? 'border-emerald-300/25' : ($isDevPro ? 'border-violet-300/20' : 'border-cyan-300/12') }}"
                    data-product-preview-tier="{{ $key }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] {{ $isAvailable ? 'text-emerald-300' : ($isDevPro ? 'text-violet-300' : 'text-cyan-300') }}">
                                {{ $tier['tagline'] }}
                            </p>
                            <h2 class="mt-1 text-xl font-black text-slate-50">{{ $tier['name'] }}</h2>
                        </div>

                        <span class="badge {{ $isAvailable ? 'badge-green' : 'badge-slate' }}">
                            {{ $isAvailable ? '利用可能' : 'Coming Soon' }}
                        </span>
                    </div>

                    <p class="mt-4 min-h-20 text-sm leading-6 text-slate-400">{{ $tier['summary'] }}</p>

                    <div class="mt-5 space-y-2">
                        @foreach (($tier['highlights'] ?? []) as $highlight)
                            <div class="flex items-start gap-2 text-xs leading-5 text-slate-300">
                                <span class="{{ $isAvailable ? 'text-emerald-300' : 'text-slate-600' }}" aria-hidden="true">•</span>
                                <span>{{ $highlight }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 rounded-xl border border-slate-800 bg-slate-950/35 p-3 text-[10px] leading-5 text-slate-500">
                        @if ($isAvailable)
                            Early Accessで利用できます。FreeでもCanoviaのCore Loopは成立します。
                        @elseif ($isDevPro)
                            Proの上位版ではなく、Development専用の追加レイヤーとして検討中です。
                        @else
                            このプレビューは将来の方向を示すもので、現在の利用権や購入を意味しません。
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <section class="space-y-4">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">EXPERIENCE PREVIEW</p>
                <h2 class="mt-1 text-xl font-black text-slate-50 sm:text-2xl">機能名ではなく、体験の差を見る</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                    将来的にはこの枠を短い動画プレビューへ置き換えられるよう、今は同じ情報構造をステップ表示で先に定義しています。
                </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($experiences as $experienceKey => $experience)
                    <article class="page-card p-5 sm:p-6" data-product-preview-experience="{{ $experienceKey }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">{{ strtoupper($experienceKey) }}</p>
                                <h3 class="mt-1 text-lg font-black text-slate-100">{{ $experience['label'] }}</h3>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $experience['description'] }}</p>
                            </div>
                            <span class="rounded-full border border-slate-800 bg-slate-950/45 px-3 py-1 text-[10px] font-bold text-slate-500">Preview</span>
                        </div>

                        <div class="mt-5 space-y-3">
                            @foreach (($experience['tiers'] ?? []) as $tierKey => $steps)
                                @php
                                    $tier = $tiers->get($tierKey, []);
                                    $isAvailable = ($tier['status'] ?? null) === 'available';
                                @endphp
                                <div class="rounded-2xl border border-slate-800 bg-slate-950/30 p-4" data-preview-flow="{{ $tierKey }}">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <p class="text-xs font-black text-slate-200">{{ $tier['name'] ?? ucfirst($tierKey) }}</p>
                                            <span class="text-[9px] font-bold uppercase tracking-[0.12em] {{ $isAvailable ? 'text-emerald-300' : 'text-slate-600' }}">
                                                {{ $tier['tagline'] ?? '' }}
                                            </span>
                                        </div>
                                        <span class="text-[9px] font-bold {{ $isAvailable ? 'text-emerald-300' : 'text-slate-600' }}">
                                            {{ $isAvailable ? 'NOW' : 'SOON' }}
                                        </span>
                                    </div>

                                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                                        @foreach ($steps as $step)
                                            <div class="flex min-w-0 flex-1 items-center gap-2 sm:block">
                                                <div class="rounded-xl border border-white/5 bg-white/[0.025] px-3 py-3 text-xs leading-5 text-slate-300">
                                                    {{ $step }}
                                                </div>
                                                @if (! $loop->last)
                                                    <span class="shrink-0 text-center text-slate-700 sm:mt-2 sm:block" aria-hidden="true">↓</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="page-card border-slate-800 p-5 sm:p-6">
            <div class="grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">EARLY ACCESS POLICY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">まだ決めていないことは、決めたように見せない</h2>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach ($disclosures as $disclosure)
                            <div class="rounded-xl border border-slate-800 bg-slate-950/30 px-3 py-3 text-xs leading-5 text-slate-400">
                                {{ $disclosure }}
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 lg:justify-end">
                    @auth
                        <a href="{{ route('auth.account') }}" class="btn-secondary">アカウント設定へ戻る</a>
                    @else
                        <a href="{{ route('auth.register.form') }}" class="btn-primary">Freeで始める</a>
                    @endauth
                    <a href="{{ route('home') }}" class="btn-secondary">Canoviaへ戻る</a>
                </div>
            </div>
        </section>

        <p class="text-center text-[10px] leading-5 text-slate-600">
            この画面には購入・決済・申込機能はありません。表示内容はEarly Access中に変更される場合があります。
        </p>
    </div>
@endsection
