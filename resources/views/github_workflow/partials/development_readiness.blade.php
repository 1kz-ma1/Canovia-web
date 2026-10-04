@php
    $readiness = $development_action?->intelligence?->readiness;
    $state = $development_action?->intelligence?->state;
    $gates = is_array($readiness?->components['gates'] ?? null)
        ? $readiness->components['gates']
        : [];
    $gateLabels = [
        'implementation' => '実装',
        'ci' => 'CI / Test',
        'review' => 'Review',
        'merge' => 'Merge',
        'deploy' => 'Production Deploy',
        'verification' => '実機・本番確認',
        'spec_sync' => '仕様同期',
    ];
    $statusLabels = [
        'passed' => '確認済み',
        'failed' => '問題あり',
        'pending' => '進行中',
        'unknown' => '未確認',
    ];
    $focusTaskId = (int) data_get($state?->facts, 'focus_task_id', 0);
    $focusState = data_get($state?->facts, 'focus_task_state');
    $focusState = is_array($focusState) ? $focusState : [];
    $deployPassed = data_get($focusState, 'gates.deploy.status') === 'passed';
    $implementationPassed = data_get($focusState, 'gates.implementation.status') === 'passed';
    $verificationStale = (bool) data_get($focusState, 'verification_stale', false);
    $specSyncStale = (bool) data_get($focusState, 'spec_sync_stale', false);
@endphp

@if ($development_action)
    @if ($intelligencePresentation ?? null)
        @include('intelligence.partials.summary', [
            'intelligencePresentation' => $intelligencePresentation,
            'intelligenceHistory' => $intelligenceHistory ?? [],
            'canExecuteIntelligence' => $can_edit_development_readiness,
            'intelligenceAnchor' => 'development-intelligence',
        ])
    @endif

    <section
        id="development-quality-gates"
        class="rounded-3xl border border-slate-800 bg-slate-950/45 p-5 md:p-6"
        data-development-readiness
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">RELEASE QUALITY GATES</p>
                <h2 class="mt-1 text-lg font-black text-slate-100">Release判断の内訳</h2>
                <p class="mt-2 text-xs leading-6 text-slate-500">
                    上のReadinessを構成する7 Gateです。GitHub由来Gateはauthoritative stateから観測し、
                    実機・本番確認と仕様同期だけは人が明示した結果をEvidenceとして扱います。
                </p>
            </div>

            @if ($development_focus_task)
                <div class="rounded-2xl border border-white/8 bg-slate-950/40 px-4 py-3">
                    <p class="text-[10px] text-slate-500">RELEASE CANDIDATE</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">{{ $development_focus_task->title }}</p>
                </div>
            @elseif ($focusTaskId > 0)
                <div class="rounded-2xl border border-white/8 bg-slate-950/40 px-4 py-3">
                    <p class="text-[10px] text-slate-500">RELEASE CANDIDATE</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">Task #{{ $focusTaskId }}</p>
                </div>
            @endif
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($gateLabels as $gate => $label)
                @php
                    $gateState = is_array($gates[$gate] ?? null) ? $gates[$gate] : [];
                    $status = (string) ($gateState['status'] ?? 'unknown');
                @endphp
                <div
                    class="rounded-2xl border border-slate-800 bg-slate-950/55 p-4"
                    data-development-gate="{{ $gate }}"
                    data-development-gate-status="{{ $status }}"
                >
                    <div class="flex items-center justify-between gap-3">
                        <strong class="text-sm text-slate-100">{{ $label }}</strong>
                        <span class="rounded-full border border-slate-700 px-2 py-1 text-[10px] font-black text-slate-400">
                            {{ $statusLabels[$status] ?? $status }}
                        </span>
                    </div>

                    @if ($gate === 'verification' && $development_focus_task && $can_edit_development_readiness)
                        @if ($deployPassed)
                            @if ($verificationStale)
                                <p class="mt-2 text-[10px] leading-5 text-amber-300">
                                    Deployが変わったため、現在のProductionで再確認が必要です。
                                </p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (['passed' => '確認済み', 'failed' => '問題あり'] as $value => $buttonLabel)
                                    <form method="POST" action="{{ route('plans.development_readiness.quality_gate.confirm', [$selected_plan, $development_focus_task]) }}">
                                        @csrf
                                        <input type="hidden" name="quality_gate" value="verification">
                                        <input type="hidden" name="gate_status" value="{{ $value }}">
                                        <input type="hidden" name="confirmation_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button type="submit" class="btn-secondary px-2 py-1 text-[10px]">{{ $buttonLabel }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-2 text-[10px] leading-5 text-slate-600">
                                Production Deploy確認後に、このReleaseを実機・本番で確認します。
                            </p>
                        @endif
                    @elseif ($gate === 'spec_sync' && $development_focus_task && $can_edit_development_readiness)
                        @if ($implementationPassed)
                            @if ($specSyncStale)
                                <p class="mt-2 text-[10px] leading-5 text-amber-300">
                                    実装SHAが変わったため、現在の実装に対して再確認が必要です。
                                </p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (['passed' => '同期済み', 'failed' => '未同期', 'not_required' => '対象外'] as $value => $buttonLabel)
                                    <form method="POST" action="{{ route('plans.development_readiness.quality_gate.confirm', [$selected_plan, $development_focus_task]) }}">
                                        @csrf
                                        <input type="hidden" name="quality_gate" value="spec_sync">
                                        <input type="hidden" name="gate_status" value="{{ $value }}">
                                        <input type="hidden" name="confirmation_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <button type="submit" class="btn-secondary px-2 py-1 text-[10px]">{{ $buttonLabel }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-2 text-[10px] leading-5 text-slate-600">
                                実装Evidenceが確認できてから仕様同期を確定します。
                            </p>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
