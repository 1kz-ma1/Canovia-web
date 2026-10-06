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
    <section class="page-card overflow-hidden p-0" data-study-workspace-shell>
        <div class="px-4 py-3 sm:px-5">
            <a
                href="{{ route('workspace.study.top') }}"
                class="specialized-workspace-top-link"
                data-study-top-link
            >
                <span aria-hidden="true">←</span>
                <span>学習トップへ</span>
            </a>

            <div class="mt-3 flex min-w-0 items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-300/18 bg-amber-300/[0.06] text-amber-300">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current" aria-hidden="true">
                        <path d="M5 5.5h9.5a2 2 0 0 1 2 2V19H7a2 2 0 0 1-2-2V5.5Zm11.5 2H19v9.5a2 2 0 0 1-2 2h-.5" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>

                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-300">STUDY</p>
                    <p class="truncate text-sm font-black text-slate-100">
                        {{ $plan?->title ?? '学習Workspace' }}
                    </p>
                    <p class="mt-0.5 text-[10px] text-slate-600">
                        {{ $plan ? '現在のPlanで学習を進めます。Plan切り替え・準備は学習トップから。' : '学習Planを作ると、ここに現在の学習Contextが表示されます。' }}
                    </p>
                </div>
            </div>
        </div>

        @if ($plan)
            <nav class="flex gap-2 overflow-x-auto border-t border-white/8 px-4 py-2.5 sm:px-5" aria-label="学習Workspace navigation">
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
                <a href="#study-history" class="badge badge-slate whitespace-nowrap">History</a>
            </nav>
        @endif
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
