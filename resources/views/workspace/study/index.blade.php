@extends('layouts.app')

@section('title', '学習 Workspace | Canovia')

@section('content')
@php
    $presentation = $intelligencePresentation ?? null;
    $adaptive = $studyAdaptiveAction ?? null;
    $state = $adaptive?->intelligence?->state;
    $readiness = $adaptive?->intelligence?->readiness;
    $metrics = $state?->metrics ?? [];
    $facts = $state?->facts ?? [];
    $priorityScope = collect(data_get($facts, 'priority_remaining_scope', []));
    $recentActions = collect(data_get($intelligenceHistory ?? [], 'actions', []));
    $recentDecisions = collect(data_get($intelligenceHistory ?? [], 'decisions', []));
    $recentStates = collect(data_get($intelligenceHistory ?? [], 'states', []));
    $pressure = (string) data_get($facts, 'deadline_pressure', 'unknown');
    $pressureLabel = match ($pressure) {
        'low' => '余裕あり',
        'medium' => 'やや詰まり気味',
        'high' => '負荷高め',
        'overdue' => '期限超過',
        default => '未判定',
    };
    $pressureClass = match ($pressure) {
        'low' => 'text-emerald-300',
        'medium' => 'text-amber-200',
        'high', 'overdue' => 'text-rose-300',
        default => 'text-slate-400',
    };
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-study-workspace>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-amber-300/[0.08] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-300">STUDY WORKSPACE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">学習の現在地から、次の一手まで。</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        試験範囲とPractice / Recall Evidenceから、Readiness・最大Gap・Current Actionを一つの流れで見ます。
                    </p>
                </div>

                @if ($studyPlans->isNotEmpty())
                    <form method="GET" action="{{ route('workspace.study.index') }}" class="min-w-0 rounded-2xl border border-white/8 bg-slate-950/35 p-3 lg:w-[22rem]">
                        <label for="study-workspace-plan" class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">STUDY PLAN</label>
                        <div class="mt-2 flex gap-2">
                            <select id="study-workspace-plan" name="plan_id" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/70 px-3 py-2 text-sm font-bold text-slate-100">
                                @foreach ($studyPlans as $studyPlan)
                                    <option value="{{ $studyPlan->id }}" @selected($plan && (int) $plan->id === (int) $studyPlan->id)>
                                        {{ $studyPlan->title }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary min-h-10 px-3 text-xs">表示</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <nav class="flex gap-2 overflow-x-auto px-5 py-3 sm:px-6" aria-label="学習Workspace navigation">
            <a href="#study-current-action" class="badge badge-slate whitespace-nowrap">Current Action</a>
            <a href="#study-readiness" class="badge badge-slate whitespace-nowrap">Readiness</a>
            @if ($plan)
                <a href="{{ route('plans.study_scope.index', $plan) }}" class="badge badge-slate whitespace-nowrap">Study Scope</a>
                @if ($navigationTask)
                    <a href="{{ route('plans.tasks.study_practice.show', [$plan, $navigationTask]) }}" class="badge badge-slate whitespace-nowrap">Practice</a>
                    <a href="{{ route('plans.tasks.study_recall.show', [$plan, $navigationTask]) }}" class="badge badge-slate whitespace-nowrap">Recall</a>
                @else
                    <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Practice</span>
                    <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Recall</span>
                @endif
            @else
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Study Scope</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Practice</span>
                <span class="badge badge-slate whitespace-nowrap opacity-45" aria-disabled="true">Recall</span>
            @endif
            <a href="#study-history" class="badge badge-slate whitespace-nowrap">History</a>
        </nav>
    </section>

    @if (! $plan)
        <section class="page-card border-dashed border-amber-300/25 p-7 sm:p-9" data-study-workspace-no-plan>
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-amber-300">START STUDY WORKSPACE</p>
                <h2 class="mt-2 text-2xl font-black text-slate-50">まず、学習Planを一つ作る</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">
                    Study WorkspaceはPlanごとの試験範囲・Evidence・Readinessを扱います。
                    学習Planを作れば、試験範囲の取り込みから始められます。
                </p>
                <a href="{{ route('plans.create') }}" class="btn-primary mt-5 inline-flex min-h-11 items-center px-4">学習Planを作る</a>
            </div>
        </section>
    @elseif (! $hasConfirmedScope)
        <section class="page-card border-amber-300/20 bg-amber-300/[0.025] p-6 sm:p-8" data-study-workspace-capture-first>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,0.8fr)] lg:items-center">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-300">FIRST EVIDENCE</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-50">試験範囲から始める</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-400">
                        Readinessを意味のある数字にするには、まず「何が試験範囲か」を確定する必要があります。
                        範囲表・スクリーンショット・PDFを追加して、人の確認後にStudy Stateへ反映します。
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('plans.study_scope.index', $plan) }}" class="btn-primary min-h-11 px-4">試験範囲を追加</a>
                        <a href="{{ route('plans.show', $plan) }}" class="btn-secondary min-h-11 px-4">Planを見る</a>
                    </div>
                </div>
                <div class="rounded-2xl border border-white/8 bg-slate-950/35 p-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">SELECTED PLAN</p>
                    <p class="mt-2 text-lg font-black text-slate-100">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
                    <div class="mt-4 space-y-3 text-xs text-slate-500">
                        <p>1. Scopeを確定</p>
                        <p>2. Practice / RecallでEvidenceを増やす</p>
                        <p>3. ReadinessとCurrent Actionが更新</p>
                    </div>
                </div>
            </div>
        </section>
    @else
        <section id="study-readiness" class="page-card border-amber-300/15 p-5 sm:p-6" data-study-workspace-readiness>
            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div class="rounded-3xl border border-amber-300/15 bg-gradient-to-br from-amber-300/[0.08] to-transparent p-5 sm:p-6">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-300">EXAM READINESS</p>
                    <div class="mt-3 flex items-end gap-3">
                        <strong class="text-5xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未判定' }}</strong>
                        <span class="pb-1 text-sm font-bold text-slate-400">{{ $presentation?->stateLabel }}</span>
                    </div>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        Confidence {{ $presentation?->confidenceDisplay() ?? '—' }}
                        @if (data_get($facts, 'exam_date'))
                            · 試験日 {{ data_get($facts, 'exam_date') }}
                        @endif
                        @if (data_get($metrics, 'days_until_exam') !== null)
                            · あと {{ (int) data_get($metrics, 'days_until_exam') }}日
                        @endif
                    </p>
                    <div class="mt-5 rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">DEADLINE PRESSURE</p>
                        <p class="mt-1 text-lg font-black {{ $pressureClass }}">{{ $pressureLabel }}</p>
                        <p class="mt-1 text-[11px] text-slate-600">
                            残り {{ data_get($metrics, 'remaining_effort_units') !== null ? number_format((float) data_get($metrics, 'remaining_effort_units'), 2) : '—' }} Study Units
                        </p>
                    </div>
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

        <section class="grid gap-4 lg:grid-cols-[minmax(0,0.78fr)_minmax(0,1.22fr)]">
            <article class="page-card border-amber-300/15 p-5 sm:p-6" data-study-workspace-gap>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST GAP</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->gapLabel ?? '現在のGapを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail }}</p>
                <p class="mt-4 border-t border-white/8 pt-4 text-[11px] leading-5 text-slate-600">
                    Task進捗ではなく、確定したScopeとPractice / Recall Evidenceから判断しています。
                </p>
            </article>

            <article id="study-current-action" class="page-card border-violet-300/15 bg-violet-300/[0.025] p-5 sm:p-6" data-study-workspace-action>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CURRENT ACTION</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">{{ $presentation?->action?->title ?? '次のActionを確認中' }}</h2>
                <p class="mt-3 text-sm leading-6 text-slate-400">{{ $presentation?->action?->intent }}</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($presentation && $canEdit)
                        <form method="POST" action="{{ $presentation->actionUrl }}">
                            @csrf
                            <button type="submit" class="btn-primary min-h-11 px-4">{{ $presentation->actionLabel }}</button>
                        </form>
                    @elseif ($presentation)
                        <span class="btn-secondary inline-flex min-h-11 items-center px-4 opacity-60">閲覧のみ</span>
                    @endif
                    <a href="{{ route('plans.study_scope.index', $plan) }}" class="btn-secondary min-h-11 px-4">範囲・Evidenceを見る</a>
                </div>

                @if ($presentation?->decision)
                    <details class="pk-action-details mt-5">
                        <summary>なぜ今これ？</summary>
                        <p class="mt-3 text-xs leading-5 text-slate-400">{{ $presentation->decision->summary }}</p>
                    </details>
                @endif
            </article>
        </section>

        @if ($priorityScope->isNotEmpty())
            <section class="page-card p-5 sm:p-6" data-study-workspace-priority-scope>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">NEXT SCOPE</p>
                        <h2 class="mt-1 text-lg font-black text-slate-50">残り負荷が大きい範囲</h2>
                    </div>
                    <a href="{{ route('plans.study_scope.index', $plan) }}" class="text-xs font-bold text-sky-300 hover:text-sky-200">全範囲を見る →</a>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($priorityScope->take(6) as $item)
                        <article class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                            <p class="text-sm font-black text-slate-100">
                                {{ $item['subject'] ?? '科目未設定' }}{{ ! empty($item['unit']) ? ' · '.$item['unit'] : '' }}
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                残り {{ number_format((float) ($item['remaining_unit'] ?? 0), 2) }} units
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2 text-[10px] text-slate-500">
                                <span>Mastery {{ isset($item['mastery_score_percent']) ? $item['mastery_score_percent'].'%' : '未測定' }}</span>
                                <span>Retention {{ isset($item['retention_score_percent']) ? $item['retention_score_percent'].'%' : '未測定' }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    <section id="study-history" class="page-card p-5 sm:p-6" data-study-workspace-history>
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">HISTORY</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">判断とStateの変化</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">保存済みのDecision / Action / Stateを、直近の変化だけ確認できます。</p>
        </div>

        @if (! $plan)
            <p class="mt-4 text-sm text-slate-600">学習Planを作ると、ここに履歴が蓄積されます。</p>
        @elseif ($recentDecisions->isEmpty() && $recentActions->isEmpty() && $recentStates->isEmpty())
            <p class="mt-4 text-sm text-slate-600">保存済みのStudy Intelligence履歴はまだありません。</p>
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
                    <p class="text-xs font-black text-slate-300">State / Evidence</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentStates->take(3) as $snapshot)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">Evidence {{ count((array) $snapshot->evidence_references) }}件</p>
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
