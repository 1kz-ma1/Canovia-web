<section
            id="development-readiness"
            class="page-card border-cyan-300/15 p-5 sm:p-6"
            data-development-workspace-readiness
            data-development-home-readiness
        >
            <div class="grid gap-4 lg:grid-cols-[minmax(0,.7fr)_minmax(0,1.3fr)]">
                <div class="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-5">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">RELEASE READINESS</p>
                    <div class="mt-2 flex items-end gap-3">
                        <strong class="text-4xl font-black tracking-tight text-slate-50">{{ $presentation?->readinessDisplay() ?? '未判定' }}</strong>
                        <span class="pb-1 text-xs font-bold text-slate-400">{{ $presentation?->stateLabel ?? '判定準備中' }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2 text-[10px] text-slate-500">
                        <span class="rounded-full border border-white/8 px-2 py-1">Gate {{ $passedGateCount }}/7</span>
                        <span class="rounded-full border border-white/8 px-2 py-1">Evidence {{ count($state?->evidenceReferences ?? []) }}件</span>
                    </div>
                </div>

                <article class="rounded-2xl border border-amber-300/15 bg-amber-300/[0.025] p-5" data-development-workspace-gap>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-amber-200">BIGGEST RELEASE GAP</p>
                    <h2 class="mt-2 text-lg font-black text-slate-50">{{ $presentation?->gapLabel ?? '現在のRelease Gapを確認中' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">{{ $presentation?->gapDetail ?? 'GitHub Evidenceや明示確認が増えると、Release状態をより具体的に判断できます。' }}</p>
                </article>
            </div>

            @if ((bool) data_get($focusState, 'verification_stale') || (bool) data_get($focusState, 'spec_sync_stale'))
                <div class="mt-4 rounded-2xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-5 text-amber-100" data-development-workspace-stale-warning>
                    @if ((bool) data_get($focusState, 'verification_stale'))
                        <p>Production Deployが変わったため、実機・本番確認を現在Releaseに対してやり直す必要があります。</p>
                    @endif
                    @if ((bool) data_get($focusState, 'spec_sync_stale'))
                        <p>実装SHAが変わったため、現在実装に対する仕様同期を再確認する必要があります。</p>
                    @endif
                </div>
            @endif

            <details id="development-quality-gates" class="mt-4 rounded-2xl border border-white/8 bg-slate-950/20 p-4" data-development-workspace-quality-gates>
                <summary class="cursor-pointer list-none text-sm font-black text-slate-200">
                    7 Quality Gatesを確認
                    <span class="ml-2 text-xs font-normal text-slate-500">{{ $passedGateCount }}/7 確認済み</span>
                </summary>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($gateLabels as $gate => $label)
                        @php
                            $gateState = is_array($gates[$gate] ?? null) ? $gates[$gate] : [];
                            $status = (string) ($gateState['status'] ?? 'unknown');
                            $tone = match ($status) {
                                'passed' => 'border-emerald-300/20 bg-emerald-300/[0.035]',
                                'failed' => 'border-rose-300/20 bg-rose-300/[0.035]',
                                'pending' => 'border-amber-300/20 bg-amber-300/[0.035]',
                                default => 'border-white/8 bg-slate-950/25',
                            };
                            $textTone = match ($status) {
                                'passed' => 'text-emerald-300',
                                'failed' => 'text-rose-300',
                                'pending' => 'text-amber-200',
                                default => 'text-slate-500',
                            };
                        @endphp
                        <article class="rounded-2xl border p-4 {{ $tone }}" data-development-workspace-gate="{{ $gate }}" data-development-workspace-gate-status="{{ $status }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-black text-slate-100">{{ $label }}</p>
                                <span class="text-[10px] font-black {{ $textTone }}">{{ $statusLabels[$status] ?? $status }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="mt-4 text-right">
                    <a href="{{ route('github_workflow.index', ['plan_id' => $plan->id]) }}#development-quality-gates" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">
                        Gateを操作する →
                    </a>
                </div>
            </details>
        </section>

        @include('intelligence.partials.state-change', [
            'feedback' => $intelligenceStateChange ?? null,
        ])

        