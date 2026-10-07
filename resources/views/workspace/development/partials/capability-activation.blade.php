@php
    $activation = is_array($activation ?? null) ? $activation : null;
    $stage = (string) data_get($activation, 'stage', '');
    $stageLabel = match ($stage) {
        'preview' => '価値を確認',
        'readiness' => '準備を確認',
        'setup_started' => '設定中',
        'completed' => '接続済み',
        default => '準備',
    };
    $stageClass = match ($stage) {
        'completed' => 'text-emerald-200 border-emerald-300/20 bg-emerald-300/[0.05]',
        'setup_started' => 'text-violet-200 border-violet-300/20 bg-violet-300/[0.05]',
        default => 'text-cyan-200 border-cyan-300/20 bg-cyan-300/[0.05]',
    };
@endphp

@if ($plan && data_get($activation, 'visible'))
    <section
        class="page-card overflow-hidden border-violet-300/15"
        data-capability-activation="github_integration"
        data-capability-stage="{{ $stage }}"
    >
        <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">
                        CAPABILITY ACTIVATION
                    </p>
                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-black {{ $stageClass }}">
                        {{ $stageLabel }}
                    </span>
                </div>
                <h2 class="mt-2 text-base font-black text-slate-100">GitHub連携を段階的に準備</h2>
                <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">
                    いきなり設定を要求せず、必要性・準備状況・接続状態を順番に確認します。DevelopmentはGitHubなしでも利用できます。
                </p>
            </div>

            @if ($stage === 'completed')
                <a
                    href="{{ $activation['next_url'] }}"
                    class="btn-secondary shrink-0 px-3 py-2 text-xs"
                >GitHub画面を開く</a>
            @endif
        </div>

        <div class="border-t border-slate-800/80 px-4 py-4 sm:px-5">
            <div class="grid gap-2 sm:grid-cols-5" data-capability-steps>
                @foreach ((array) data_get($activation, 'steps', []) as $step)
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                        <div class="flex items-center gap-2">
                            <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full border text-[10px] font-black {{ $step['done'] ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : 'border-slate-700 text-slate-600' }}">
                                {{ $step['done'] ? '✓' : $loop->iteration }}
                            </span>
                            <span class="text-[10px] font-black uppercase tracking-[.08em] {{ $step['done'] ? 'text-slate-300' : 'text-slate-600' }}">
                                {{ $step['label'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-[10px] font-black uppercase tracking-[.12em] text-slate-600">NEXT OWNER</span>
                        <strong class="text-xs text-slate-200">{{ data_get($activation, 'owner', 'YOU') }}</strong>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ data_get($activation, 'detail') }}</p>

                    @if (data_get($activation, 'repository'))
                        <p class="mt-2 text-[11px] text-slate-500">
                            Repository候補:
                            <strong class="text-slate-300">{{ data_get($activation, 'repository.title') }}</strong>
                            @if (data_get($activation, 'repository.auto_candidate'))
                                <span class="ml-1 rounded-full border border-violet-300/15 px-2 py-0.5 text-[9px] font-bold text-violet-200">1件だけなので候補化</span>
                            @endif
                        </p>
                    @elseif ((int) data_get($activation, 'repository_count', 0) > 1)
                        <p class="mt-2 text-[11px] text-slate-500">複数Repositoryがあるため、接続画面で対象を選びます。</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($stage === 'preview')
                        <a href="{{ $activation['next_url'] }}" class="btn-secondary px-3 py-2 text-xs">価値を確認する</a>
                    @elseif ($stage === 'setup_started')
                        <a href="{{ $activation['next_url'] }}" class="btn-primary px-3 py-2 text-xs">設定を続ける</a>
                    @elseif (data_get($activation, 'can_start'))
                        <form method="POST" action="{{ route('capabilities.setup.start', ['capability' => 'github_integration', 'plan' => $plan]) }}">
                            @csrf
                            <button type="submit" class="btn-primary px-3 py-2 text-xs">
                                {{ $stage === 'readiness' ? 'GitHub連携の準備を進める' : '設定を開始' }}
                            </button>
                        </form>
                    @endif

                    @if (data_get($activation, 'can_abandon'))
                        <form method="POST" action="{{ route('capabilities.setup.abandon', ['capability' => 'github_integration', 'plan' => $plan]) }}">
                            @csrf
                            <button type="submit" class="btn-secondary px-3 py-2 text-xs">今はやめる</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endif
