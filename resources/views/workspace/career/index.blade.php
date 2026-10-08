@extends('layouts.app')

@section('title', 'Career Workspace | Canovia')

@section('content')
@php
    $presentation = $intelligencePresentation ?? null;
    $adaptive = $careerAdaptiveAction ?? null;
    $state = $adaptive?->intelligence?->state;
    $metrics = $state?->metrics ?? [];
    $facts = $state?->facts ?? [];
    $recentActions = collect(data_get($intelligenceHistory ?? [], 'actions', []));
    $recentDecisions = collect(data_get($intelligenceHistory ?? [], 'decisions', []));
    $recentStates = collect(data_get($intelligenceHistory ?? [], 'states', []));
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-career-workspace>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-emerald-300/[0.08] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-300">CAREER WORKSPACE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">{{ $plan ? '選考の事実から、次に処理すべきActionを見る。' : 'やりたい仕事が決まっていなくても、就活を始められます。' }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        {{ $plan ? '内定確率や市場価値は評価しません。Career Capture・応募・面接・ReviewのStateから、今のProcess Gapと次Actionだけを判断します。' : '職種が決まっている方も、これから探す方も、自分の現在地から始められます。' }}
                    </p>
                </div>

                @if ($careerPlans->isNotEmpty())
                    <form method="GET" action="{{ route('workspace.career.index') }}" class="min-w-0 rounded-2xl border border-white/8 bg-slate-950/35 p-3 lg:w-[22rem]">
                        <label for="career-workspace-plan" class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">CAREER PLAN</label>
                        <div class="mt-2 flex gap-2">
                            <select id="career-workspace-plan" name="plan_id" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-sm font-bold text-slate-100">
                                @foreach ($careerPlans as $careerPlan)
                                    <option value="{{ $careerPlan->id }}" @selected($plan && (int) $plan->id === (int) $careerPlan->id)>
                                        {{ $careerPlan->title }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">表示</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <nav class="flex gap-2 overflow-x-auto px-5 py-3 sm:px-6" aria-label="Career Workspace navigation">
            <a href="#career-current-action" class="badge badge-slate whitespace-nowrap">Current Action</a>
            <a href="#career-readiness" class="badge badge-slate whitespace-nowrap">Process Readiness</a>
            @if ($plan)
                <a href="{{ route('plans.career.index', $plan) }}#career-inbox" class="badge badge-slate whitespace-nowrap">Career Inbox</a>
                <a href="{{ route('plans.career.index', $plan) }}#career-pipeline" class="badge badge-slate whitespace-nowrap">Pipeline</a>
            @else
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Career Inbox</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Pipeline</span>
            @endif
            <a href="#career-history" class="badge badge-slate whitespace-nowrap">History</a>
        </nav>
    </section>

    @if (! $plan)
        <section class="page-card p-5 sm:p-6" data-career-workspace-no-plan data-career-first-use-choices>
            <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">START YOUR CAREER</p>
            <h2 class="mt-2 text-xl font-black text-slate-50">今の状況に近いものを選んでください</h2>
            <p class="mt-2 text-xs leading-5 text-slate-400">診断は任意です。あとから診断を追加したり、職種への関心を変更したりできます。</p>
            <div class="mt-5 grid gap-3 md:grid-cols-3">
                <a
                    href="{{ route('plans.create.manual', ['workspace_mode' => 'career']) }}"
                    class="block rounded-2xl border border-white/12 bg-slate-950/30 p-5 transition hover:border-emerald-300/40"
                    data-career-first-use-known
                >
                    <h3 class="text-base font-black text-slate-100">やりたい職種が決まっている</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-400">希望の仕事をもとにCareer Planを作り、企業・応募・面接を整理します。</p>
                    <span class="mt-4 inline-block text-xs font-bold text-emerald-300">Planを作る →</span>
                </a>
                <a
                    href="{{ route('career.explore.show') }}"
                    class="block rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.035] p-5 transition hover:border-emerald-300/40"
                    data-career-first-use-canovia
                >
                    <h3 class="text-base font-black text-slate-100">Canoviaで診断する</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-400">興味・価値観・希望条件を短く整理し、最初に調べる職種の方向性を見つけます。</p>
                    <span class="mt-4 inline-block text-xs font-bold text-emerald-300">職種探索を始める →</span>
                </a>
                <a
                    href="https://job.mynavi.jp/conts/2028/cs/matchplus_consent_2/"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block rounded-2xl border border-white/12 bg-slate-950/30 p-5 transition hover:border-emerald-300/40"
                    data-career-first-use-match-plus
                >
                    <h3 class="text-base font-black text-slate-100">MATCH plusで診断する</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-400">マイナビの公式サイトで診断します。結果PDFは保存しておけます。外部サイトで開きます。</p>
                    <span class="mt-4 inline-block text-xs font-bold text-emerald-300">公式診断を開く ↗</span>
                </a>
            </div>
            <p class="mt-4 text-[11px] leading-5 text-slate-500">
                MATCH plusはマイナビが提供する別サービスです。診断結果PDFの解析・取り込みは次の実装段階で対応します。現時点ではPDFのアップロードを求めません。
            </p>
        </section>
    @elseif ($modeOnboarding ?? null)
        <div
            @if (data_get($modeOnboarding, 'current_step.key') === 'capture_career_signal')
                data-career-workspace-signal-first
            @endif
        >
            <p class="mb-3 text-xs text-slate-400">まだ職種が決まっていなければ、<a href="{{ route('career.explore.show') }}" class="font-bold text-emerald-300">Canoviaの職種探索</a>から始めても構いません。</p>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding,
            ])
        </div>
    @else
        <section id="career-readiness" class="page-card border-emerald-300/15 p-5 sm:p-6" data-career-workspace-readiness>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
                <div class="rounded-3xl border border-emerald-300/15 bg-gradient-to-br from-emerald-300/[0.08] to-transparent p-5 sm:p-6">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-emerald-300">PROCESS READINESS</p>
                    <div class="mt-3">
                        <strong class="text-4xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未観測' }}</strong>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-400">
                        現在のCareer Processを判断できる構造化Stateが観測されています。
                    </p>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        Confidence {{ $presentation?->confidenceDisplay() ?? '—' }}
                        · Evidence / State Ref {{ count($state?->evidenceReferences ?? []) }}件
                    </p>
                    <p class="mt-4 border-t border-white/8 pt-4 text-[11px] leading-5 text-slate-600">
                        この表示は就職成功率・市場価値・企業評価ではありません。
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    @foreach ($presentation?->metrics ?? [] as $metric)
                        <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">{{ $metric['detail'] ?? $metric['label'] }}</p>
                            <p class="mt-2 text-2xl font-black text-slate-100">{{ $metric['value'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $metric['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        @include('intelligence.partials.state-change', [
            'feedback' => $intelligenceStateChange ?? null,
        ])

        <section class="grid gap-4 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)]">
            <article class="page-card border-amber-300/15 p-5 sm:p-6" data-career-workspace-gap>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST PROCESS GAP</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->gapLabel ?? 'Career Pipelineを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail }}</p>
                <p class="mt-4 border-t border-white/8 pt-4 text-[11px] leading-5 text-slate-600">
                    企業選択やオファー承諾はCanoviaが決めません。記録済みの事実からProcess上の不足だけを示します。
                </p>
            </article>

            <article id="career-current-action" class="page-card border-violet-300/15 bg-violet-300/[0.025] p-5 sm:p-6" data-career-workspace-action>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CURRENT ACTION</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->action?->title ?? '次のCareer Actionを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->action?->intent }}</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($presentation)
                        <a href="{{ $presentation->actionUrl }}" class="btn-primary min-h-11 px-4">{{ $presentation->actionLabel }}</a>
                    @endif
                    <a href="{{ route('plans.career.index', $plan) }}" class="btn-secondary min-h-11 px-4">Career管理を開く</a>
                </div>

                @if ($presentation?->decision)
                    <details class="pk-action-details mt-5">
                        <summary>なぜ今これ？</summary>
                        <p class="mt-3 text-xs leading-5 text-slate-400">{{ $presentation->decision->summary }}</p>
                    </details>
                @endif
            </article>
        </section>

        <section class="page-card p-5 sm:p-6" data-career-workspace-process-state>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">PROCESS STATE</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">選考プロセスの構造化State</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">会社名や面接回答ではなく、Pipelineの状態だけをWorkspaceで俯瞰します。</p>
                </div>
                <a href="{{ route('plans.career.index', $plan) }}" class="text-xs font-bold text-emerald-300 hover:text-emerald-200">詳細を管理する →</a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">PENDING CAPTURE</p>
                    <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($metrics, 'pending_capture_count', 0) }}</p>
                </div>
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">ACTIVE</p>
                    <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($metrics, 'active_application_count', 0) }}</p>
                </div>
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">RESULT WAITING</p>
                    <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($metrics, 'result_waiting_count', 0) }}</p>
                </div>
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">OFFER</p>
                    <p class="mt-2 text-2xl font-black text-slate-100">{{ (int) data_get($metrics, 'offer_application_count', 0) }}</p>
                </div>
            </div>
        </section>
    @endif

    <section id="career-history" class="page-card p-5 sm:p-6" data-career-workspace-history>
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">HISTORY</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">Career判断とStateの変化</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">保存済みのDecision / Action / Stateを、自由記述を複製せず確認します。</p>
        </div>

        @if (! $plan)
            <p class="mt-4 text-sm text-slate-600">Career Planを作ると、ここに履歴が蓄積されます。</p>
        @elseif ($recentDecisions->isEmpty() && $recentActions->isEmpty() && $recentStates->isEmpty())
            <p class="mt-4 text-sm text-slate-600">保存済みのCareer Intelligence履歴はまだありません。</p>
        @else
            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div>
                    <p class="text-xs font-black text-slate-300">Decision</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentDecisions->take(3) as $trace)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">{{ $trace->decision_summary }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $trace->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="text-xs font-black text-slate-300">Action</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentActions->take(3) as $historyAction)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">{{ $historyAction->title }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $historyAction->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <p class="text-xs font-black text-slate-300">State</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentStates->take(3) as $snapshot)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">State Ref {{ count((array) $snapshot->evidence_references) }}件</p>
                                <p class="mt-1 text-[10px] text-slate-600">{{ $snapshot->captured_at?->format('m/d H:i') ?? $snapshot->created_at?->format('m/d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">まだありません。</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </section>
</div>
@endsection
