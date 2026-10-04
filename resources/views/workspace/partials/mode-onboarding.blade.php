@php
    $setup = is_array($modeOnboarding ?? null) ? $modeOnboarding : null;
    $modeKey = (string) ($setup['mode'] ?? '');
    [$accentText, $accentBorder, $accentBg] = match ($modeKey) {
        'study' => ['text-amber-300', 'border-amber-300/20', 'bg-amber-300/[0.025]'],
        'career' => ['text-emerald-300', 'border-emerald-300/20', 'bg-emerald-300/[0.025]'],
        default => ['text-cyan-300', 'border-cyan-300/20', 'bg-cyan-300/[0.025]'],
    };
@endphp

@if ($setup)
    <section
        class="page-card {{ $accentBorder }} {{ $accentBg }} p-5 sm:p-6"
        data-workspace-mode-onboarding="{{ $setup['mode'] }}"
        data-workspace-mode-onboarding-step="{{ data_get($setup, 'current_step.key') }}"
    >
        <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div class="max-w-2xl">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] {{ $accentText }}">
                    {{ strtoupper($setup['mode']) }} / GETTING STARTED
                </p>
                <h2 class="mt-2 text-xl font-black text-slate-50 sm:text-2xl">
                    {{ data_get($setup, 'current_step.title') }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">
                    {{ data_get($setup, 'current_step.description') }}
                </p>

                @if (is_array($setup['action'] ?? null))
                    <div class="mt-5">
                        @if (($setup['action']['method'] ?? 'GET') === 'POST')
                            <form method="POST" action="{{ $setup['action']['url'] }}">
                                @csrf
                                <button type="submit" class="btn-primary min-h-11 px-4">
                                    {{ $setup['action']['label'] }}
                                </button>
                            </form>
                        @else
                            <a href="{{ $setup['action']['url'] }}" class="btn-primary inline-flex min-h-11 items-center px-4">
                                {{ $setup['action']['label'] }}
                            </a>
                        @endif
                    </div>
                @endif

                <p class="mt-4 text-[11px] leading-5 text-slate-600">
                    この案内に完了ボタンはありません。必要なState / Evidenceが揃うと自動で消え、通常のReadinessとCurrent Actionが主役になります。
                </p>
            </div>

            <div class="w-full xl:max-w-xl">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-500">SETUP PROGRESS</p>
                    <span class="text-xs font-bold text-slate-400">
                        {{ $setup['completed_count'] }} / {{ $setup['total_count'] }}
                    </span>
                </div>

                <div class="mt-3 grid gap-2">
                    @foreach ($setup['steps'] as $step)
                        @php
                            $state = (string) ($step['state'] ?? 'upcoming');
                            $complete = $state === 'complete';
                            $current = $state === 'current';
                        @endphp
                        <div
                            class="flex items-start gap-3 rounded-2xl border p-3
                                {{ $complete ? 'border-emerald-300/15 bg-emerald-300/[0.025]' : ($current ? $accentBorder.' '.$accentBg : 'border-white/8 bg-slate-950/20') }}"
                            data-workspace-onboarding-step="{{ $step['key'] }}"
                            data-workspace-onboarding-step-state="{{ $state }}"
                        >
                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full border text-[10px] font-black
                                {{ $complete ? 'border-emerald-300/30 bg-emerald-300/10 text-emerald-300' : ($current ? $accentBorder.' '.$accentText : 'border-slate-700 text-slate-600') }}">
                                {{ $complete ? '✓' : $step['position'] }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-black {{ $complete ? 'text-slate-400' : ($current ? 'text-slate-100' : 'text-slate-500') }}">
                                    {{ $step['title'] }}
                                </p>
                                @if ($current)
                                    <p class="mt-1 text-[11px] leading-5 text-slate-500">現在のセットアップStep</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
