@extends('layouts.app')

@section('title', 'Goal Pattern Demand | Canovia Admin')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">TOOL DISCOVERY LOOP</p>
            <div class="mt-2 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h1 class="text-2xl font-black text-slate-50 sm:text-3xl">Goal Pattern Demand</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                        Goal DiscoveryとGuided Executionの実利用から、専用Toolを検討する価値がある領域を観測します。
                        AIがToolを作成・公開する画面ではありません。集計Signalを見て、最後は人がProduct Decisionを行います。
                    </p>
                </div>
                <span class="badge badge-slate">Human decision only</span>
            </div>
        </header>

        <section class="page-card p-5 sm:p-6">
            <form method="GET" action="{{ route('admin.goal_pattern_demand.index') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                <label class="text-xs font-bold text-slate-400">
                    集計期間
                    <select name="period" class="form-control mt-2">
                        @foreach (['7' => '7日', '30' => '30日', '90' => '90日', 'all' => '全期間'] as $value => $label)
                            <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-bold text-slate-400">
                    Goal領域
                    <select name="pattern" class="form-control mt-2">
                        <option value="">すべて</option>
                        @foreach ($availablePatterns as $key => $label)
                            <option value="{{ $key }}" @selected($selectedPattern === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn-primary">表示</button>
                    <a href="{{ route('admin.goal_pattern_demand.index') }}" class="btn-secondary">解除</a>
                </div>
            </form>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="page-card p-4">
                <p class="text-[10px] font-bold uppercase tracking-[.12em] text-slate-500">GOAL CONTEXT</p>
                <p class="mt-2 text-2xl font-black text-slate-100">{{ number_format($summary['goal_contexts']) }}</p>
                <p class="mt-1 text-xs text-slate-500">この期間に作られたGoal</p>
            </div>
            <div class="page-card p-4">
                <p class="text-[10px] font-bold uppercase tracking-[.12em] text-amber-300">GUIDED EXECUTION</p>
                <p class="mt-2 text-2xl font-black text-amber-100">{{ number_format($summary['guided_executions']) }}</p>
                <p class="mt-1 text-xs text-slate-500">汎用伴走が使われた回数</p>
            </div>
            <div class="page-card p-4">
                <p class="text-[10px] font-bold uppercase tracking-[.12em] text-emerald-300">REFLECTION</p>
                <p class="mt-2 text-2xl font-black text-emerald-100">{{ number_format($summary['guided_completed']) }}</p>
                <p class="mt-1 text-xs text-slate-500">完了率 {{ number_format($summary['guided_completion_rate'], 1) }}%</p>
            </div>
            <div class="page-card p-4">
                <p class="text-[10px] font-bold uppercase tracking-[.12em] text-cyan-300">PATTERNS</p>
                <p class="mt-2 text-2xl font-black text-cyan-100">{{ number_format($pattern_stats->count()) }}</p>
                <p class="mt-1 text-xs text-slate-500">決定論Ruleによる領域分類</p>
            </div>
        </section>

        @if ($analysis_capped)
            <div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-6 text-amber-100/80">
                詳細分析は表示負荷を抑えるためGoal Context / Guided Executionそれぞれ最新{{ number_format(AppServicesGoalPatternDemandService::ANALYSIS_LIMIT) }}件を対象にしています。上部の総数は全対象データから集計しています。
            </div>
        @endif

        @if ($selectedPatternStat)
            <section class="page-card border-cyan-300/15 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">SELECTED PATTERN</p>
                <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-black text-slate-50">{{ $selectedPatternStat['label'] }}</h2>
                        <p class="mt-2 text-xs leading-5 text-slate-500">
                            Opportunity Scoreは自動実装判断ではなく、複数利用者・Guided Execution反復・Measurement Unknownをまとめた探索用Signalです。
                        </p>
                    </div>
                    <strong class="text-3xl font-black text-cyan-100">{{ $selectedPatternStat['opportunity_score'] }}<small class="ml-1 text-xs text-slate-500">/100</small></strong>
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">Goal</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['goal_contexts'] }}</strong></div>
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">利用Identity</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['unique_identities'] }}</strong></div>
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">Guided</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['guided_executions'] }}</strong></div>
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">反復Task</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['repeat_tasks'] }}</strong></div>
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">Measurement不明</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['measurement_unknowns'] }}</strong></div>
                    <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3"><small class="text-slate-500">Known Unknown</small><strong class="mt-1 block text-slate-100">{{ $selectedPatternStat['known_unknowns'] }}</strong></div>
                </div>
            </section>
        @endif

        <section class="page-card overflow-hidden">
            <div class="border-b border-slate-800 p-5">
                <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-300">GOAL PATTERNS</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">専用Tool候補を探す</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    Scoreは探索順を決める補助値です。成果の良し悪しやユーザーの能力を評価する値ではなく、Tool公開を自動決定しません。
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="bg-slate-950/45 text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Pattern</th>
                            <th class="px-4 py-3 text-right">Score</th>
                            <th class="px-4 py-3 text-right">Identity</th>
                            <th class="px-4 py-3 text-right">Goal</th>
                            <th class="px-4 py-3 text-right">Guided</th>
                            <th class="px-4 py-3 text-right">反復Task</th>
                            <th class="px-4 py-3 text-right">Measurement?</th>
                            <th class="px-4 py-3 text-right">Readiness L/M/H</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        @forelse ($pattern_stats as $stat)
                            <tr class="{{ $selectedPattern === $stat['key'] ? 'bg-cyan-300/[0.035]' : '' }}">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.goal_pattern_demand.index', ['period' => $period, 'pattern' => $stat['key']]) }}" class="font-semibold text-slate-200 hover:text-cyan-200">{{ $stat['label'] }}</a>
                                </td>
                                <td class="px-4 py-3 text-right font-black text-cyan-100">{{ $stat['opportunity_score'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-300">{{ $stat['unique_identities'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-400">{{ $stat['goal_contexts'] }}</td>
                                <td class="px-4 py-3 text-right text-amber-200">{{ $stat['guided_executions'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-300">{{ $stat['repeat_tasks'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-300">{{ $stat['measurement_unknowns'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-500">
                                    {{ $stat['readiness']['low'] }}/{{ $stat['readiness']['medium'] }}/{{ $stat['readiness']['high'] }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-slate-500">この期間のGoal Patternはまだありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="page-card overflow-hidden">
                <div class="border-b border-slate-800 p-5">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-amber-300">CONTEXT SIGNALS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">現在地・成功基準・制約・観測方法</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">自由記述本文は表示せず、構造化された種類だけを集計します。</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-slate-950/45 text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Signal</th>
                                <th class="px-4 py-3 text-right">Identity</th>
                                <th class="px-4 py-3 text-right">回数</th>
                                <th class="px-4 py-3 text-right">Unknown</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80">
                            @forelse ($signal_stats as $stat)
                                <tr>
                                    <td class="px-4 py-3"><span class="font-semibold text-slate-200">{{ $stat['label'] }}</span><small class="ml-2 text-slate-600">{{ $stat['dimension'] }}</small></td>
                                    <td class="px-4 py-3 text-right text-slate-300">{{ $stat['unique_identities'] }}</td>
                                    <td class="px-4 py-3 text-right text-slate-400">{{ $stat['count'] }}</td>
                                    <td class="px-4 py-3 text-right {{ $stat['unknown_count'] > 0 ? 'text-amber-200' : 'text-slate-600' }}">{{ $stat['unknown_count'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-slate-500">構造化Signalはまだありません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="page-card overflow-hidden">
                <div class="border-b border-slate-800 p-5">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-300">GUIDED STRUCTURES</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">現実Actionで繰り返される型</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">結果の良し悪しではなく、どの実行構造が何度使われたかを見ます。</p>
                </div>
                <div class="divide-y divide-slate-800/80">
                    @forelse ($guided_structure_stats as $stat)
                        <article class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-slate-200">{{ $stat['label'] }}</h3>
                                    <p class="mt-1 text-[11px] text-slate-500">Identity {{ $stat['unique_identities'] }} · Reflection {{ $stat['completed'] }}/{{ $stat['executions'] }} ({{ number_format($stat['completion_rate'], 1) }}%)</p>
                                </div>
                                <span class="badge badge-slate">{{ $stat['executions'] }}回</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                                <span class="badge badge-slate">Focus {{ $stat['with_focus'] }}</span>
                                <span class="badge badge-slate">Observe {{ $stat['with_observation'] }}</span>
                                <span class="badge badge-slate">Next adjustment {{ $stat['with_adjustment'] }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="p-6 text-sm text-slate-500">Guided Executionの利用はまだありません。</div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="page-card p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[.14em] text-cyan-300">INPUT INTERACTION</p>
            <h2 class="mt-1 text-xl font-black text-slate-50">Goal Discoveryで自然に使われた入力スタイル</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">性格診断ではありません。Quick / text / skipという実際の操作だけを集計します。</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @forelse ($input_stats as $stat)
                    <div class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                        <p class="font-bold text-slate-200">{{ $stat['label'] }}</p>
                        <p class="mt-2 text-2xl font-black text-slate-100">{{ $stat['contexts'] }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">回答 {{ $stat['answers'] }} · Skip {{ $stat['skips'] }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Goal Discoveryの操作データはまだありません。</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.035] p-5 text-xs leading-6 text-slate-400">
            <strong class="text-amber-100">Product Decision boundary:</strong>
            この画面は需要Signalの観測だけを行います。新しい専用Tool・カテゴリ・課金Featureを自動生成、公開、Entitlement接続する処理はありません。
        </section>
    </div>
@endsection
