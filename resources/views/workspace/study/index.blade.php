@extends('layouts.app')

@section('title', '学習 Workspace | Canovia')

@section('content')
@php
    $presentation = $intelligencePresentation ?? null;
    $composition = $studyWorkspaceComposition ?? ['surfaces' => []];
    $recentActions = collect(data_get($intelligenceHistory ?? [], 'actions', []));
    $recentDecisions = collect(data_get($intelligenceHistory ?? [], 'decisions', []));
    $recentStates = collect(data_get($intelligenceHistory ?? [], 'states', []));
@endphp

<div class="mx-auto max-w-7xl space-y-5" data-study-workspace>
    <section class="page-card overflow-hidden p-0">
        <div class="border-b border-white/8 bg-gradient-to-r from-amber-300/[0.08] via-slate-950/20 to-transparent px-5 py-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-300">STUDY WORKSPACE</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">学習の現在地から、次の一手まで。</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        Planの種類と、Canoviaがすでに持っている学習Stateから、今必要な情報・学習方法・次のActionだけを組み立てます。
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
            @if ($plan)
                <a href="#study-current-state" class="badge badge-slate whitespace-nowrap">Current State</a>
                @if ($studyMethodRecommendation ?? null)
                    <a href="#study-method-recommendation" class="badge badge-slate whitespace-nowrap">Recommended Method</a>
                @else
                    <a href="#study-current-action" class="badge badge-slate whitespace-nowrap">Current Action</a>
                @endif
                @if (data_get($studyWorkspaceState ?? [], 'has_confirmed_scope'))
                    <a href="#study-readiness" class="badge badge-slate whitespace-nowrap">Readiness</a>
                @endif
                <a href="#study-methods" class="badge badge-slate whitespace-nowrap">Study Methods</a>
            @endif
            <a href="#study-history" class="badge badge-slate whitespace-nowrap">History</a>
        </nav>
    </section>

    @if (! $plan)
        <div data-study-workspace-no-plan>
            @include('workspace.partials.mode-onboarding', [
                'modeOnboarding' => $modeOnboarding ?? null,
            ])
        </div>
    @else
        @include('workspace.partials.execution-setup', [
            'executionSetup' => $executionSetup ?? null,
            'plan' => $plan,
            'navigationTask' => $navigationTask,
            'canEdit' => $canEdit,
        ])

        @include('intelligence.partials.state-change', [
            'feedback' => $intelligenceStateChange ?? null,
        ])

        <div class="space-y-5" data-study-workspace-composed data-study-learning-type="{{ data_get($studyLearningType ?? [], 'key', 'general_learning') }}">
            @foreach (data_get($composition, 'surfaces', []) as $surface)
                @include($surface['partial'], $surface['data'] ?? [])
            @endforeach
        </div>
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
