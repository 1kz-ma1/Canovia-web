@php
    $presentation = $intelligencePresentation ?? null;
    $history = is_array($intelligenceHistory ?? null)
        ? $intelligenceHistory
        : [];
    $recentActions = collect($history['actions'] ?? []);
    $recentDecisions = collect($history['decisions'] ?? []);
    $recentStates = collect($history['states'] ?? []);
    $canExecute = (bool) ($canExecuteIntelligence ?? false);
    $anchor = trim((string) ($intelligenceAnchor ?? 'plan-intelligence'));
@endphp

@if ($presentation)
<section
    @if ($anchor !== '') id="{{ $anchor }}" @endif
    class="page-card border-cyan-300/20 bg-cyan-300/[0.025] p-5 sm:p-6"
    data-intelligence-summary
    data-intelligence-domain="{{ $presentation->domain->value }}"
>
    <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="max-w-3xl">
            <span class="plan-identity-chip text-[11px]">
                <span aria-hidden="true">{{ $presentation->plan->displayIcon() }}</span>
                {{ $presentation->plan->title }}
            </span>
            <p class="mt-3 text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">{{ $presentation->eyebrow }}</p>
            <h2 class="mt-1 text-xl font-black text-slate-50">{{ $presentation->headline }}</h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">{{ $presentation->sourceNote }}</p>
        </div>

        <div class="grid min-w-0 {{ $presentation->hasDistinctStateDisplay() ? 'grid-cols-3 xl:min-w-[24rem]' : 'grid-cols-2 xl:min-w-[16rem]' }} gap-2">
            <div class="rounded-2xl border border-white/8 bg-slate-950/45 p-3 text-center" data-intelligence-readiness-display="{{ $presentation->qualitativeReadiness ? 'qualitative' : 'numeric' }}">
                <strong class="block text-xl text-slate-100">{{ $presentation->readinessDisplay() }}</strong>
                <span class="text-[10px] text-slate-500">{{ $presentation->readinessLabel }}</span>
            </div>
            @if ($presentation->hasDistinctStateDisplay())
                <div class="rounded-2xl border border-white/8 bg-slate-950/45 p-3 text-center" data-intelligence-current-state>
                    <strong class="block text-sm text-slate-100">{{ $presentation->stateLabel }}</strong>
                    <span class="text-[10px] text-slate-500">CURRENT STATE</span>
                </div>
            @endif
            <div class="rounded-2xl border border-white/8 bg-slate-950/45 p-3 text-center">
                <strong class="block text-xl text-slate-100">{{ $presentation->confidenceDisplay() }}</strong>
                <span class="text-[10px] text-slate-500">CONFIDENCE</span>
            </div>
        </div>
    </div>

    @if (! empty($presentation->metrics))
        <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($presentation->metrics as $metric)
                <div class="rounded-2xl border border-white/8 bg-slate-950/30 p-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">{{ $metric['label'] }}</p>
                    <p class="mt-1 text-lg font-black text-slate-100">{{ $metric['value'] }}</p>
                    @if (! empty($metric['detail']))
                        <p class="mt-0.5 text-[10px] text-slate-600">{{ $metric['detail'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,0.72fr)_minmax(0,1.28fr)]">
        <div class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-amber-200">BIGGEST GAP</p>
            <h3 class="mt-1 text-sm font-black text-slate-100">{{ $presentation->gapLabel }}</h3>
            <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ $presentation->gapDetail }}</p>
        </div>

        <div class="rounded-2xl border border-violet-300/15 bg-violet-300/[0.04] p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-violet-300">CURRENT ACTION</p>
            <h3 class="mt-1 text-base font-black text-slate-100">{{ $presentation->action->title }}</h3>
            <p class="mt-2 text-xs leading-5 text-slate-400">{{ $presentation->action->intent }}</p>

            <div class="mt-3 flex flex-wrap gap-2">
                @if ($presentation->actionMethod === 'POST')
                    @if ($canExecute)
                        <form method="POST" action="{{ $presentation->actionUrl }}">
                            @csrf
                            <button type="submit" class="btn-primary min-h-10 px-3 py-2 text-xs">{{ $presentation->actionLabel }}</button>
                        </form>
                    @endif
                @else
                    <a href="{{ $presentation->actionUrl }}" class="btn-primary min-h-10 px-3 py-2 text-xs">{{ $presentation->actionLabel }}</a>
                @endif

                @if ($presentation->detailUrl !== $presentation->actionUrl)
                    <a href="{{ $presentation->detailUrl }}" class="btn-secondary min-h-10 px-3 py-2 text-xs">詳細を見る</a>
                @endif
            </div>
        </div>
    </div>

    <details class="pk-action-details mt-4" data-intelligence-explainability>
        <summary>なぜ今これ？・判断履歴</summary>

        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                <p class="text-[11px] text-slate-500">現在の判断</p>
                <p class="mt-1 text-sm font-bold text-slate-100">{{ $presentation->decision->summary }}</p>
            </div>
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                <p class="text-[11px] text-slate-500">Evidence</p>
                <p class="mt-1 text-sm font-bold text-slate-100">{{ count($presentation->state->evidenceReferences) }}件</p>
                <p class="mt-1 text-[10px] text-slate-600">現在Stateへ寄与した参照数</p>
            </div>
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                <p class="text-[11px] text-slate-500">関連Task</p>
                <p class="mt-1 text-sm font-bold text-slate-100">{{ $presentation->targetTask?->title ?? 'Taskに固定しないAction' }}</p>
                @if ($presentation->requiresTaskProjection)
                    <p class="mt-1 text-[10px] text-slate-600">実行を選んだ時だけTaskへ投影</p>
                @endif
            </div>
        </div>

        @if ($recentDecisions->isNotEmpty() || $recentActions->isNotEmpty() || $recentStates->isNotEmpty())
            <div class="mt-4 grid gap-4 border-t border-white/8 pt-4 lg:grid-cols-3">
                <div>
                    <p class="text-xs font-black text-slate-300">Decision</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentDecisions->take(4) as $trace)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">{{ $trace->decision_summary }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">
                                    {{ $trace->readiness_score !== null ? 'Readiness '.$trace->readiness_score.'/100' : 'Readiness未判定' }}
                                    · {{ $trace->created_at?->format('m/d H:i') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">保存済みDecisionはまだありません。</p>
                        @endforelse
                    </div>
                </div>

                <div>
                    <p class="text-xs font-black text-slate-300">Action</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentActions->take(4) as $historyAction)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-xs font-bold text-slate-200">{{ $historyAction->title }}</p>
                                    <span class="badge {{ $historyAction->status === 'active' ? 'badge-green' : 'badge-slate' }}">
                                        {{ $historyAction->status === 'active' ? '現在' : '変更済み' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-[10px] leading-4 text-slate-600">{{ $historyAction->intent }}</p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">保存済みActionはまだありません。</p>
                        @endforelse
                    </div>
                </div>

                <div>
                    <p class="text-xs font-black text-slate-300">State / Evidence</p>
                    <div class="mt-2 space-y-2">
                        @forelse ($recentStates->take(4) as $snapshot)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-xs font-bold text-slate-200">
                                    Evidence {{ count((array) $snapshot->evidence_references) }}件
                                </p>
                                <p class="mt-1 text-[10px] text-slate-600">
                                    {{ $snapshot->captured_at?->format('m/d H:i') ?? $snapshot->created_at?->format('m/d H:i') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-[11px] text-slate-600">保存済みStateはまだありません。</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </details>
</section>
@endif
