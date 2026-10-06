@php
    $outcomes = (array) ($outcomes ?? []);
    $topics = collect($outcomes['topics'] ?? []);
    $statusMap = [
        'reinforcing' => ['label' => '補強中', 'class' => 'badge-slate'],
        'waiting_baseline' => ['label' => '基準不足', 'class' => 'badge-slate'],
        'waiting_recheck' => ['label' => '再確認待ち', 'class' => 'badge-slate'],
        'improved_observation' => ['label' => '改善観測', 'class' => 'badge-green'],
        'stable_observation' => ['label' => '変化小', 'class' => 'badge-slate'],
        'regressed_observation' => ['label' => '再観測推奨', 'class' => 'badge-rose'],
    ];
@endphp

<section
    class="page-card border-violet-300/15 p-5 sm:p-6"
    data-study-surface="weakness_intervention_outcomes"
    data-weakness-intervention-outcomes
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">
                WEAKNESS INTERVENTION OUTCOME
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                弱点補強の前後をBroad Practiceで比較
            </h2>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                補強中の高得点ではなく、補強前と補強終了後の同Topic問題を比較します。
                before / afterの観測であり、補強が原因だったと断定する指標ではありません。
            </p>
        </div>
        <span class="badge badge-slate">
            最大{{ (int) ($outcomes['sample_limit'] ?? 5) }}問ずつ
        </span>
    </div>

    <div class="mt-4 grid gap-3">
        @foreach ($topics as $topic)
            @php
                $status = $statusMap[$topic['status'] ?? 'waiting_recheck']
                    ?? $statusMap['waiting_recheck'];
                $baseline = (array) ($topic['baseline'] ?? []);
                $intervention = (array) ($topic['intervention'] ?? []);
                $after = (array) ($topic['after'] ?? []);
                $delta = $topic['observed_delta_points'] ?? null;
            @endphp
            <article
                class="rounded-2xl border border-white/8 bg-slate-950/25 p-4"
                data-weakness-intervention-topic="{{ $topic['topic'] ?? '' }}"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-black text-slate-100">
                            {{ $topic['topic'] ?? 'Topic' }}
                        </p>
                        <p class="mt-1 text-[10px] text-slate-600">
                            補強cycle {{ (int) ($topic['cycle_count'] ?? 1) }}回
                            · targeted {{ (int) ($intervention['targeted_attempt_count'] ?? 0) }} Session
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge {{ $status['class'] }}">
                            {{ $status['label'] }}
                        </span>
                        @if (($topic['confidence'] ?? 'insufficient') !== 'insufficient')
                            <span class="badge badge-slate">
                                confidence {{ $topic['confidence'] }}
                            </span>
                        @endif
                    </div>
                </div>

                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    <div class="rounded-xl border border-white/6 bg-black/10 p-3">
                        <p class="text-[10px] text-slate-600">補強前 Broad</p>
                        <p class="mt-1 text-lg font-black text-slate-100">
                            @if (($baseline['observed_correct_rate_percent'] ?? null) !== null)
                                {{ (int) $baseline['observed_correct_rate_percent'] }}%
                            @else
                                —
                            @endif
                        </p>
                        <p class="mt-1 text-[10px] text-slate-500">
                            {{ (int) ($baseline['question_count'] ?? 0) }}問
                            · unique {{ (int) ($baseline['unique_question_count'] ?? 0) }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-white/6 bg-black/10 p-3">
                        <p class="text-[10px] text-slate-600">補強中</p>
                        <p class="mt-1 text-lg font-black text-slate-100">
                            @if (($intervention['observed_correct_rate_percent'] ?? null) !== null)
                                {{ (int) $intervention['observed_correct_rate_percent'] }}%
                            @else
                                —
                            @endif
                        </p>
                        <p class="mt-1 text-[10px] text-slate-500">
                            {{ (int) ($intervention['question_count'] ?? 0) }} mapped問
                            · 効果判定には不使用
                        </p>
                    </div>

                    <div class="rounded-xl border border-white/6 bg-black/10 p-3">
                        <p class="text-[10px] text-slate-600">補強後 Broad</p>
                        <div class="mt-1 flex items-baseline gap-2">
                            <p class="text-lg font-black text-slate-100">
                                @if (($after['observed_correct_rate_percent'] ?? null) !== null)
                                    {{ (int) $after['observed_correct_rate_percent'] }}%
                                @else
                                    —
                                @endif
                            </p>
                            @if ($delta !== null)
                                <span class="text-xs font-black {{ (int) $delta >= 15 ? 'text-emerald-300' : ((int) $delta <= -15 ? 'text-rose-300' : 'text-slate-400') }}">
                                    {{ (int) $delta > 0 ? '+' : '' }}{{ (int) $delta }}pt
                                </span>
                            @endif
                        </div>
                        <p class="mt-1 text-[10px] text-slate-500">
                            {{ (int) ($after['question_count'] ?? 0) }}問
                            · unique {{ (int) ($after['unique_question_count'] ?? 0) }}
                        </p>
                    </div>
                </div>

                <p class="mt-3 text-[10px] leading-4 text-slate-500">
                    {{ $topic['note'] ?? '' }}
                </p>
            </article>
        @endforeach
    </div>

    @if ((bool) ($outcomes['history_truncated'] ?? false))
        <p class="mt-3 text-[10px] leading-4 text-amber-300/80">
            最新{{ (int) ($outcomes['attempt_limit'] ?? 120) }} Attemptのみを観測しています。古い補強cycleはこの表示に含まれない場合があります。
        </p>
    @endif

    <p class="mt-4 text-[10px] leading-4 text-slate-600">
        この観測値はWeakness Priority・Routing・Mastery・Task進捗を自動変更しません。
        正式なPolicy変更は別versionで扱います。
    </p>
</section>
