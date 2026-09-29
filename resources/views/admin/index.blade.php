@extends('layouts.app')

@section('title', 'Admin | Canovia')

@section('content')
    <div class="mx-auto max-w-6xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 shadow-[0_20px_60px_rgba(2,6,23,.22)] sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">CANOVIA ADMIN</p>
            <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">運営ダッシュボード</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
                        ユーザーの声、計画作成・更新の動作状況、演習問題の需要、未知Goalから見えるTool需要をここから確認できます。
                    </p>
                </div>
                <a href="{{ route('home') }}" class="btn-secondary shrink-0">Canoviaへ戻る</a>
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <a href="{{ route('admin.feedback.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-sky-400/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-sky-400/20 bg-sky-400/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-sky-300">FEEDBACK</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">フィードバック管理</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">評価、不具合、要望を確認し、対応状況や更新情報への紐付けを管理します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-sky-300" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                    <p class="text-xs text-slate-500">未対応</p>
                    <p class="mt-1 text-3xl font-black text-slate-100">{{ $feedbackNew }}</p>
                </div>
            </a>

            <a href="{{ route('admin.telemetry.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-cyan-400/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-cyan-400/20 bg-cyan-400/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-cyan-300">DIAGNOSTICS</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">計画作成・更新の診断</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">初期計画と計画更新のファネル、Web/PWA差、失敗理由を確認します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-cyan-300" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">7日間<br>作成試行</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $generationAttempts }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">作成<br>失敗</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $generationFailures }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">更新<br>失敗</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $updateFailures }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('admin.github.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-emerald-400/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-emerald-300">GITHUB INTEGRATION</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">GitHub連携の診断</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">App / Webhook / Queue / Repository接続とReturn Evidenceの処理状態を確認します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-emerald-300" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/35 p-4 text-xs leading-5 text-slate-500">
                    本番セットアップ後の「動いているか分からない」をここで確認できます。
                </div>
            </a>

            <a href="{{ route('admin.question_packs.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-violet-400/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-violet-400/20 bg-violet-400/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-violet-300">QUESTION BANK</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">資格問題集</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">Question Packの取込・確認・公開状態を管理します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-violet-300" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] text-slate-500">全Pack</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $questionPackCount }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] text-slate-500">公開中</p>
                        <p class="mt-1 text-xl font-black text-emerald-200">{{ $publishedQuestionPackCount }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('admin.practice_demand.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-amber-300/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-amber-300/20 bg-amber-300/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-amber-200">PRACTICE SUPPLY</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">演習需要とCandidate</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">Question Bankの不足分野と、Native AI生成問題の確認キューを管理します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-amber-200" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">30日<br>Session</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $practiceDemand30d }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">Bank<br>不足枠</p>
                        <p class="mt-1 text-xl font-black text-amber-100">{{ $practiceGapQuestions30d }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">確認待ち<br>Candidate</p>
                        <p class="mt-1 text-xl font-black text-cyan-100">{{ $pendingQuestionCandidateCount }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('admin.goal_pattern_demand.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-cyan-300/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-cyan-200">TOOL DISCOVERY</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">Goal Pattern Demand</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">未知GoalとGuided Executionの実利用から、専用Tool化を検討する領域を観測します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-cyan-200" aria-hidden="true">→</span>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">30日<br>Goal</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $goalContext30d }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">Guided<br>Execution</p>
                        <p class="mt-1 text-xl font-black text-amber-100">{{ $guidedExecution30d }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <p class="text-[10px] leading-4 text-slate-500">Reflection<br>完了</p>
                        <p class="mt-1 text-xl font-black text-emerald-100">{{ $guidedReflection30d }}</p>
                    </div>
                </div>
            </a>

            <a href="{{ route('admin.economy.index') }}" class="group page-card block p-5 transition hover:-translate-y-0.5 hover:border-emerald-400/35 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <span class="inline-flex rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2.5 py-1 text-[10px] font-black tracking-[.12em] text-emerald-300">ECONOMY</span>
                        <h2 class="mt-3 text-xl font-black text-slate-50">Economy Inspector</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-400">Product Grant・Feature Access・AI Capacity・推薦を決済なしで検証します。</p>
                    </div>
                    <span class="text-2xl text-slate-600 transition group-hover:translate-x-1 group-hover:text-emerald-300" aria-hidden="true">→</span>
                </div>
            </a>
        </section>

        <section class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/45 p-5 sm:p-6" data-map-validation-telemetry>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">LIVING MAP VALIDATION</p>
                    <h2 class="mt-2 text-xl font-black text-slate-50">MapをPrimary Home候補として検証</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-6 text-slate-500">
                        Flow単位でFocus・Classic遷移・Companion・実際のWork開始を追います。
                        Homeの開始時間は既存dashboard起点WorkStartedとの参考比較で、A/Bテストではありません。
                    </p>
                </div>
                <div class="rounded-full border border-slate-800 bg-slate-950/55 px-3 py-1.5 text-[10px] font-bold text-slate-500">
                    30日Flow {{ $mapTelemetry30d['views'] }}
                </div>
            </div>

            @foreach ([7 => $mapTelemetry7d, 30 => $mapTelemetry30d] as $days => $metrics)
                <div class="mt-5">
                    <p class="text-xs font-black text-slate-300">{{ $days }}日</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Map Flow</p>
                            <p class="mt-1 text-xl font-black text-slate-100">{{ $metrics['views'] }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Node Focus率</p>
                            <p class="mt-1 text-xl font-black text-cyan-100">{{ number_format($metrics['focus_rate'], 1) }}%</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Focus→Classic</p>
                            <p class="mt-1 text-xl font-black text-sky-100">{{ number_format($metrics['classic_action_rate'], 1) }}%</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Companion利用</p>
                            <p class="mt-1 text-xl font-black text-violet-100">{{ number_format($metrics['companion_rate'], 1) }}%</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Classic Home fallback</p>
                            <p class="mt-1 text-xl font-black text-amber-100">{{ number_format($metrics['home_fallback_rate'], 1) }}%</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <p class="text-[10px] text-slate-500">Work開始率</p>
                            <p class="mt-1 text-xl font-black text-emerald-100">{{ number_format($metrics['execution_rate'], 1) }}%</p>
                        </div>
                    </div>

                    <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                            <p class="text-[10px] text-slate-500">Primary Focus中央値</p>
                            <p class="mt-1 text-sm font-black text-slate-200">
                                {{ $metrics['median_primary_focus_ms'] === null ? '—' : number_format($metrics['median_primary_focus_ms'] / 1000, 1).'秒' }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                            <p class="text-[10px] text-slate-500">Map→Work開始中央値</p>
                            <p class="mt-1 text-sm font-black text-slate-200">
                                {{ $metrics['median_execution_ms'] === null ? '—' : number_format($metrics['median_execution_ms'] / 1000, 1).'秒' }}
                                @if ($metrics['median_execution_steps'] !== null)
                                    <span class="text-slate-500">/ {{ $metrics['median_execution_steps'] }} steps</span>
                                @endif
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                            <p class="text-[10px] text-slate-500">Back / Flow</p>
                            <p class="mt-1 text-sm font-black text-slate-200">{{ number_format($metrics['back_per_flow'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                            <p class="text-[10px] text-slate-500">Classic Home→Work参考値</p>
                            <p class="mt-1 text-sm font-black text-slate-200">
                                {{ $metrics['home_median_start_latency_ms'] === null ? '—' : number_format($metrics['home_median_start_latency_ms'] / 1000, 1).'秒' }}
                                <span class="text-slate-500">({{ $metrics['home_start_count'] }} starts)</span>
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach

            <p class="mt-4 text-[10px] leading-5 text-slate-600">
                Primary Homeへの昇格は自動化しません。十分なFlow数と実利用を確認し、到達時間・fallback・Back・Work開始を合わせて判断します。
            </p>
        </section>

        <section class="rounded-2xl border border-slate-800/90 bg-slate-950/35 p-4 text-xs leading-6 text-slate-500">
            管理機能が増えた場合も、この画面を入口として追加していく想定です。個別URLを覚える必要はありません。
        </section>
    </div>
@endsection
