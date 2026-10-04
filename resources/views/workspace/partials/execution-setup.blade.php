@if (($executionSetup ?? null)?->hasChoice())
    @php
        $selectedProvider = $executionSetup->selectedProvider;
        $showChoices = $executionSetup->needsSetup();
    @endphp

    <section
        class="page-card border-sky-300/15 bg-sky-300/[0.025] p-5 sm:p-6"
        data-execution-setup
        @if ($executionSetup->needsSetup()) data-execution-setup-needs-choice @endif
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">EXECUTION SETUP</p>
                @if ($executionSetup->needsSetup())
                    <h2 class="mt-2 text-xl font-black text-slate-50">この学習を、どこで実行する？</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        実行方法が複数あります。ここで一度選ぶと、以後のStartは選択済みProviderへそのまま進みます。
                        選ばなくてもCanovia Nativeで開始できます。
                    </p>
                @else
                    <h2 class="mt-2 text-lg font-black text-slate-50">
                        実行方法: {{ $selectedProvider?->name ?? 'Canovia Native' }}
                    </h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        Start時はこの設定を使います。必要になった時だけ変更できます。
                    </p>
                @endif
            </div>

            @if (! $executionSetup->needsSetup())
                <details class="pk-action-details" data-execution-setup-change>
                    <summary>実行方法を変更</summary>
                    <div class="mt-3 min-w-[16rem] space-y-2">
                        @foreach ($executionSetup->providers as $provider)
                            <form method="POST" action="{{ route('plans.tasks.execution_setup.store', [$plan, $navigationTask]) }}">
                                @csrf
                                <input type="hidden" name="provider_key" value="{{ $provider->key }}">
                                <button
                                    type="submit"
                                    class="{{ $selectedProvider?->key === $provider->key ? 'btn-primary' : 'btn-secondary' }} min-h-10 w-full px-3 text-left text-xs"
                                >
                                    {{ $provider->name }}
                                    <span class="ml-1 text-[10px] opacity-60">{{ strtoupper($provider->kind->value) }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>

        @if ($showChoices)
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ($executionSetup->providers as $provider)
                    <form method="POST" action="{{ route('plans.tasks.execution_setup.store', [$plan, $navigationTask]) }}" class="rounded-2xl border border-white/8 bg-slate-950/30 p-4">
                        @csrf
                        <input type="hidden" name="provider_key" value="{{ $provider->key }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-black text-slate-100">{{ $provider->name }}</p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    {{ $provider->kind->value === 'native' ? 'Canovia内でそのまま実行' : 'External Providerへ引き継ぐValidation' }}
                                </p>
                            </div>
                            <span class="badge badge-slate">{{ strtoupper($provider->kind->value) }}</span>
                        </div>
                        <button type="submit" class="btn-secondary mt-4 min-h-10 w-full px-3 text-xs">
                            この方法を使う
                        </button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>
@endif
