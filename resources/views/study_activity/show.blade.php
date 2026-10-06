@extends('layouts.app')

@section('title', '学習方法 | Canovia')

@section('content')
    @php
        $stateMethod = $methodRecommendation ?? null;
        $primary = (array) (
            data_get($stateMethod, 'primary')
            ?: ($activity['primary'] ?? [])
        );
        $allActivities = $stateMethod
            ? collect([
                $primary,
                ...((array) data_get($stateMethod, 'alternatives', [])),
            ])
            : collect($activity['all'] ?? []);
        $primaryKey = (string) ($primary['key'] ?? 'question_practice');
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="page-card border-emerald-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">STUDY ACTIVITY</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">このTaskに合う学習方法</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ $plan->displayIcon() }} {{ $plan->title }} / {{ $task->title }}</p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Planへ戻る</a>
            </div>

            <div class="mt-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.045] p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.14em] text-emerald-300">RECOMMENDED</p>
                        <h2 class="mt-1 text-xl font-black text-slate-50">{{ $primary['icon'] ?? '◉' }} {{ $primary['label'] ?? 'Study Activity' }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">{{ $primary['reason'] ?? '' }}</p>
                    </div>
                    <span class="badge badge-green">適合度 {{ (int) ($primary['fit_score'] ?? 0) }}</span>
                </div>

                <div class="mt-4">
                    @if ($primaryKey === 'question_practice')
                        @if ($canUseAiPractice)
                            <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="btn-primary">✦ AI演習で進める</a>
                        @else
                            <p class="text-sm text-slate-400">このTaskには問題演習が合っています。利用可能な問題集や過去問で確認してください。</p>
                        @endif
                    @elseif ($primaryKey === 'recall')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                <p class="text-[10px] font-black text-slate-500">1</p>
                                <p class="mt-1 text-sm font-bold text-slate-100">少量に絞る</p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">一度に覚える語彙・用語を小さく区切ります。</p>
                            </div>
                            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                <p class="text-[10px] font-black text-slate-500">2</p>
                                <p class="mt-1 text-sm font-bold text-slate-100">見ずに思い出す</p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">答えを読むより、先に意味・用語を想起します。</p>
                            </div>
                            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                <p class="text-[10px] font-black text-slate-500">3</p>
                                <p class="mt-1 text-sm font-bold text-slate-100">間違いだけ再確認</p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">覚えている項目より、思い出せなかった項目を優先します。</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('plans.tasks.study_recall.show', [$plan, $task]) }}" class="btn-primary" data-guide-target="study-recall">◉ Recallを始める</a>
                            @if ($resources->isNotEmpty())
                                <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">⌘ 教材を開く</a>
                            @else
                                <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">⌘ 単語帳・教材を登録</a>
                            @endif
                        </div>
                    @elseif (in_array($primaryKey, ['listening', 'dictation', 'shadowing'], true))
                        <p class="text-sm leading-6 text-slate-300">{{ $primary['description'] ?? '' }}</p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            @foreach ([
                                ['label' => '教材を開く', 'text' => '登録済みの音声・動画・transcriptを使います。'],
                                ['label' => 'Activityを実施', 'text' => 'Taskに合う手順でListening / Dictation / Shadowingを行います。'],
                                ['label' => '結果を記録', 'text' => '感触を自己評価Evidenceとして残します。進捗は自動変更しません。'],
                            ] as $index => $step)
                                <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                    <p class="text-[10px] font-black text-slate-500">{{ $index + 1 }}</p>
                                    <p class="mt-1 text-sm font-bold text-slate-100">{{ $step['label'] }}</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-400">{{ $step['text'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ $primary['url'] }}" class="btn-primary">
                                {{ $primary['action_label'] ?? 'Language Activityを始める' }}
                            </a>
                            <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">
                                教材を確認
                            </a>
                        </div>
                    @elseif (in_array($primaryKey, ['scope_organization', 'practical_evidence'], true))
                        <p class="text-sm leading-6 text-slate-300">{{ $primary['description'] ?? '' }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ $primary['url'] ?? route('plans.show', $plan) }}" class="btn-primary">
                                {{ $primary['action_label'] ?? 'この方法で進める' }}
                            </a>
                        </div>
                    @else
                        <p class="text-sm leading-6 text-slate-300">まず教材・解説から知識を入れ、理解できた箇所をあとで問題演習へつなげます。</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ $primary['url'] ?? route('plans.resources.index', $plan) }}" class="btn-primary">{{ $primary['action_label'] ?? '⌘ 関連資料を開く' }}</a>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="page-card p-5 sm:p-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300">METHOD FIT</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">学習方法の相性</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    {{ $stateMethod ? 'Taskの意味に加えて、現在State・Practice履歴・Retention・Scope状況を反映した相対的な優先度です。' : 'Taskの文脈から決める相対的な適合度です。' }}
                    試験の得点予測ではありません。
                </p>
            </div>
            <div class="mt-4 space-y-4">
                @foreach ($allActivities as $method)
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-bold text-slate-200">{{ $method['icon'] }} {{ $method['label'] }}</span>
                            <span class="text-xs text-slate-400">{{ (int) $method['fit_score'] }} / 100</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-800">
                            <div class="h-full rounded-full bg-current text-emerald-300" style="width: {{ max(0, min(100, (int) $method['fit_score'])) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] leading-5 text-slate-500">{{ $method['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($resources->isNotEmpty())
            <section class="page-card p-5 sm:p-6">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">AVAILABLE RESOURCES</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">この学習に使える資料</h2>
                <div class="mt-4 grid gap-2">
                    @foreach ($resources->take(5) as $resource)
                        <a href="{{ $resource->url }}" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-white/8 bg-white/[0.025] p-3 transition hover:border-violet-300/25">
                            <span class="text-sm font-bold text-slate-100">{{ $resource->title }}</span>
                            <span class="ml-2 text-[10px] text-slate-500">{{ $resource->providerLabel() }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($primaryKey !== 'question_practice' && $canUseAiPractice)
            <details class="page-card p-4 sm:p-5">
                <summary class="cursor-pointer text-sm font-black text-slate-200">AI演習も使う</summary>
                <p class="mt-3 text-xs leading-5 text-slate-400">AI演習を禁止しているわけではありません。このTaskでは別のActivityを先に行う方が合うとCanoviaが判断しています。</p>
                <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="btn-secondary mt-3">✦ AI演習を開く</a>
            </details>
        @endif
    </div>
@endsection
