@extends('layouts.app')

@section('title', 'Early Access | Canovia')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">EARLY ACCESS OBSERVABILITY</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">先行公開の行動を観測</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                        登録から最初の実行までをDB上の事実で追い、D1 / D7再訪、Feedback、Product Preview閲覧を確認します。
                        Plan名やTask本文などのユーザー入力内容はこの画面では集計しません。
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ([7, 30] as $range)
                        <a
                            href="{{ route('admin.early_access.index', ['days' => $range]) }}"
                            class="{{ $summary['days'] === $range ? 'btn-primary' : 'btn-secondary' }}"
                        >{{ $range }}日</a>
                    @endforeach
                </div>
            </div>

            <div class="mt-5 grid gap-2 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                    <p class="text-[10px] text-slate-500">Public</p>
                    <p class="mt-1 text-sm font-black text-slate-100">L{{ $publicLevel->value }} {{ $publicLevel->label() }}</p>
                </div>
                <div class="rounded-xl border border-cyan-300/15 bg-cyan-300/[0.04] p-3">
                    <p class="text-[10px] text-cyan-300">Target</p>
                    <p class="mt-1 text-sm font-black text-slate-100">L{{ $targetLevel->value }} {{ $targetLevel->label() }}</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                    <p class="text-[10px] text-slate-500">Release Gate</p>
                    <p class="mt-1 text-sm font-black {{ $targetAssessment['automatic_ready'] ? 'text-emerald-200' : 'text-rose-200' }}">
                        {{ $targetAssessment['automatic_ready'] ? 'Auto checks passed' : 'Blocked' }}
                    </p>
                </div>
            </div>
        </header>

        <section class="page-card overflow-hidden">
            <div class="border-b border-slate-800 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-sky-300">ACTIVATION</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">登録 → 実行完了</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">
                    選択期間中に登録したAccountをcohortとして、現在どこまで到達したかを集計します。
                </p>
            </div>

            <div class="grid gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-3 xl:grid-cols-6">
                @foreach ($summary['activation'] as $stage)
                    <article class="rounded-2xl border border-slate-800 bg-slate-950/30 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">{{ $stage['key'] }}</p>
                        <p class="mt-2 text-sm font-black text-slate-200">{{ $stage['label'] }}</p>
                        <p class="mt-3 text-3xl font-black text-slate-50">{{ $stage['users'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $stage['conversion_percent'] === null ? '—' : number_format($stage['conversion_percent'], 1).'%' }} of registered
                        </p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'D1 retention', 'data' => $summary['retention']['d1']],
                ['label' => 'D7 retention', 'data' => $summary['retention']['d7']],
            ] as $retention)
                <article class="page-card p-5">
                    <p class="text-[10px] font-black uppercase tracking-[.14em] text-violet-300">{{ $retention['label'] }}</p>
                    <p class="mt-3 text-3xl font-black text-slate-50">
                        {{ $retention['data']['rate'] === null ? '—' : number_format($retention['data']['rate'], 1).'%' }}
                    </p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        {{ $retention['data']['returned'] }} returned / {{ $retention['data']['eligible'] }} eligible
                    </p>
                </article>
            @endforeach

            <article class="page-card p-5">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">PRODUCT PREVIEW</p>
                <p class="mt-3 text-3xl font-black text-slate-50">{{ $summary['product_preview']['actors'] }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    unique actors / {{ $summary['product_preview']['views'] }} views
                </p>
            </article>

            <article class="page-card p-5">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-emerald-300">FEEDBACK</p>
                <p class="mt-3 text-3xl font-black text-slate-50">{{ $summary['feedback']['total'] }}</p>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    {{ $summary['feedback']['actors'] }} users
                    @if ($summary['feedback']['average_rating'] !== null)
                        · avg {{ number_format($summary['feedback']['average_rating'], 1) }}/5
                    @endif
                </p>
                <a href="{{ route('admin.feedback.index') }}" class="mt-3 inline-flex text-xs font-bold text-cyan-300 hover:text-cyan-200">内容を確認 →</a>
            </article>
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.1fr_.9fr]">
            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">DAILY ACTIVE</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">Early Accessの日次再訪</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">同一actorは1日1回に圧縮しています。</p>

                @if ($summary['daily_active']->isEmpty())
                    <p class="mt-4 text-sm text-slate-500">まだ日次訪問データはありません。</p>
                @else
                    @php
                        $maxActive = max(1, (int) $summary['daily_active']->max());
                    @endphp
                    <div class="mt-5 space-y-3">
                        @foreach ($summary['daily_active'] as $date => $actors)
                            <div>
                                <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                                    <span class="text-slate-500">{{ $date }}</span>
                                    <strong class="text-slate-200">{{ $actors }}</strong>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-900">
                                    <div class="h-full rounded-full bg-cyan-300/70" style="width: {{ max(3, round(($actors / $maxActive) * 100, 1)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>

            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-amber-300">FEEDBACK MIX</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">何が返ってきているか</h2>

                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    @foreach ([
                        ['label' => 'Bug', 'value' => $summary['feedback']['bugs']],
                        ['label' => '使いにくい', 'value' => $summary['feedback']['usability']],
                        ['label' => '欲しい機能', 'value' => $summary['feedback']['requests']],
                        ['label' => 'よかった点', 'value' => $summary['feedback']['positive']],
                    ] as $item)
                        <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">
                            <p class="text-xs text-slate-500">{{ $item['label'] }}</p>
                            <p class="mt-1 text-2xl font-black text-slate-100">{{ $item['value'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/30 p-4 text-xs leading-5 text-slate-500">
                    Feedback本文はこの集計画面では表示しません。詳細はFeedback管理から確認します。
                </div>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4 text-xs leading-6 text-slate-500">
            RetentionはEarly Access登録時のactor tokenと日次訪問イベントを使います。Cookie削除や端末変更では別actorになるため、厳密なAccount retentionではなくEarly Access行動の運用指標として扱います。
        </section>
    </div>
@endsection
