@php
    $readiness = $development_action?->intelligence?->readiness;
    $state = $development_action?->intelligence?->state;
    $currentAction = $development_action?->primaryAction();
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
<section
    class="rounded-3xl border border-cyan-300/20 bg-cyan-300/[0.035] p-5 md:p-6"
    data-development-readiness
>
    <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="max-w-3xl">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">DEVELOPMENT INTELLIGENCE / V53.8</p>
            <h2 class="mt-2 text-xl font-black text-slate-100">Release Readiness</h2>
            <p class="mt-2 text-xs leading-6 text-slate-500">
                Commit数やTask進捗を完了率に変換せず、同じTaskに結びついた実装・CI・Review・Merge・Deploy・確認・仕様同期のEvidenceで判断します。
            </p>

            @if ($development_focus_task)
                <p class="mt-4 text-sm text-slate-300">
                    Release candidate:
                    <strong class="text-slate-100">{{ $development_focus_task->title }}</strong>
                </p>
            @elseif ($focusTaskId > 0)
                <p class="mt-4 text-sm text-slate-300">Release candidate: Task #{{ $focusTaskId }}</p>
            @else
                <p class="mt-4 text-sm text-slate-400">Taskへ結びついたDevelopment Evidenceがまだありません。</p>
            @endif
        </div>

        <div class="grid min-w-0 grid-cols-3 gap-2 xl:min-w-[24rem]">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-3 text-center">
                <strong class="block text-xl text-slate-100">
                    {{ $readiness?->score !== null ? $readiness->score.'%' : '—' }}
                </strong>
                <span class="text-[10px] text-slate-500">READINESS</span>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-3 text-center">
                <strong class="block text-sm uppercase text-slate-100">{{ $readiness?->level?->value ?? 'unknown' }}</strong>
                <span class="text-[10px] text-slate-500">STATE</span>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/70 p-3 text-center">
                <strong class="block text-xl text-slate-100">{{ (int) round(($readiness?->confidence?->value ?? 0) * 100) }}%</strong>
                <span class="text-[10px] text-slate-500">CONFIDENCE</span>
            </div>
        </div>
    </div>

    @if ($currentAction)
        <div class="mt-5 rounded-2xl border border-violet-300/15 bg-violet-300/[0.04] p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">CURRENT ACTION</p>
            <h3 class="mt-1 text-base font-black text-slate-100">{{ $currentAction->title }}</h3>
            <p class="mt-2 text-xs leading-6 text-slate-400">{{ $currentAction->intent }}</p>
        </div>
    @endif

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

    <p class="mt-4 text-[11px] leading-5 text-slate-600">
        GitHub由来Gateはauthoritative stateから自動観測します。実機・本番確認と仕様同期だけは、人が明示した結果をEvidenceとして扱います。
    </p>
</section>
@endif
