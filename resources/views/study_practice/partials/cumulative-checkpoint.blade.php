@php
    $checkpoint = (array) ($checkpoint ?? []);
    $count = (int) ($checkpoint['assessed_question_count'] ?? 0);
    $next = $checkpoint['next_checkpoint'] ?? null;
    $remaining = (int) ($checkpoint['questions_to_next_checkpoint'] ?? 0);
    $rate = $checkpoint['observed_correct_rate_percent'] ?? null;
    $checkpoint50 = (array) data_get($checkpoint, 'checkpoints.50', []);
    $checkpoint100 = (array) data_get($checkpoint, 'checkpoints.100', []);
@endphp

<section
    class="mt-4 rounded-2xl border border-sky-300/15 bg-sky-300/[0.035] p-4"
    data-study-practice-cumulative-checkpoint
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.14em] text-sky-300">
                CUMULATIVE CHECKPOINT
            </p>
            <h2 class="mt-1 text-base font-black text-slate-100">
                50問 / 100問の累積確認
            </h2>
            <p class="mt-1 text-xs leading-5 text-slate-500">
                このTaskで実際に採点された問題だけを数えます。Checkpointは分析用で、Task進捗や弱点補強を自動変更しません。
            </p>
        </div>

        @if ($next !== null)
            <span class="badge badge-slate">
                {{ min($count, (int) $next) }} / {{ (int) $next }}問
            </span>
        @else
            <span class="badge badge-green">100問達成</span>
        @endif
    </div>

    <div class="mt-4 grid gap-2 sm:grid-cols-3">
        <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
            <p class="text-[10px] text-slate-600">採点済み</p>
            <p class="mt-1 text-lg font-black text-slate-100">
                {{ $count }}問
            </p>
            @if ($next !== null)
                <p class="mt-1 text-[10px] text-slate-500">
                    次のCheckpointまであと {{ $remaining }}問
                </p>
            @else
                <p class="mt-1 text-[10px] text-emerald-300">
                    100問Checkpoint到達
                </p>
            @endif
        </div>

        <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
            <p class="text-[10px] text-slate-600">現在の正答観測</p>
            <p class="mt-1 text-lg font-black text-slate-100">
                {{ $rate !== null ? (int) $rate.'%' : '—' }}
            </p>
            <p class="mt-1 text-[10px] text-slate-500">
                ○ {{ (int) ($checkpoint['correct_count'] ?? 0) }}
                / △ {{ (int) ($checkpoint['partial_count'] ?? 0) }}
                / × {{ (int) ($checkpoint['incorrect_count'] ?? 0) }}
            </p>
        </div>

        <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
            <p class="text-[10px] text-slate-600">Question Bank</p>
            @if ((bool) ($checkpoint['has_bank_provenance'] ?? false))
                <p class="mt-1 text-sm font-black text-slate-100">
                    unique {{ (int) ($checkpoint['unique_bank_question_count'] ?? 0) }}
                </p>
                <p class="mt-1 text-[10px] text-slate-500">
                    再出題 {{ (int) ($checkpoint['repeated_bank_exposure_count'] ?? 0) }}問
                </p>
            @else
                <p class="mt-1 text-sm font-black text-slate-400">Bank履歴なし</p>
                <p class="mt-1 text-[10px] text-slate-600">
                    AI問題は累積問数には入ります
                </p>
            @endif
        </div>
    </div>

    @if ((bool) ($checkpoint50['reached'] ?? false) || (bool) ($checkpoint100['reached'] ?? false))
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @if ((bool) ($checkpoint50['reached'] ?? false))
                <div class="rounded-xl border border-cyan-300/10 bg-slate-950/20 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-black text-cyan-200">50問 Checkpoint</p>
                        <span class="badge badge-slate">
                            {{ (int) ($checkpoint50['observed_correct_rate_percent'] ?? 0) }}%
                        </span>
                    </div>
                    <p class="mt-2 text-[10px] text-slate-500">
                        ○ {{ (int) ($checkpoint50['correct_count'] ?? 0) }}
                        / △ {{ (int) ($checkpoint50['partial_count'] ?? 0) }}
                        / × {{ (int) ($checkpoint50['incorrect_count'] ?? 0) }}
                    </p>
                    @if (($checkpoint50['reached_at'] ?? null) instanceof DateTimeInterface)
                        <p class="mt-1 text-[10px] text-slate-600">
                            {{ $checkpoint50['reached_at']->format('m/d H:i') }} 到達
                        </p>
                    @endif
                </div>
            @endif

            @if ((bool) ($checkpoint100['reached'] ?? false))
                <div class="rounded-xl border border-emerald-300/10 bg-slate-950/20 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-black text-emerald-200">100問 Checkpoint</p>
                        <span class="badge badge-green">
                            {{ (int) ($checkpoint100['observed_correct_rate_percent'] ?? 0) }}%
                        </span>
                    </div>
                    <p class="mt-2 text-[10px] text-slate-500">
                        ○ {{ (int) ($checkpoint100['correct_count'] ?? 0) }}
                        / △ {{ (int) ($checkpoint100['partial_count'] ?? 0) }}
                        / × {{ (int) ($checkpoint100['incorrect_count'] ?? 0) }}
                    </p>
                    @if (($checkpoint100['reached_at'] ?? null) instanceof DateTimeInterface)
                        <p class="mt-1 text-[10px] text-slate-600">
                            {{ $checkpoint100['reached_at']->format('m/d H:i') }} 到達
                        </p>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if ((bool) ($checkpoint['history_truncated'] ?? false))
        <p class="mt-3 text-[10px] leading-4 text-amber-300/80">
            このTaskには500 Attemptを超える履歴があります。50/100問Checkpointは保持していますが、それ以降の厳密な累積総数はこの表示の対象外です。
        </p>
    @endif
</section>
