@php
    $coverage = (array) ($coverage ?? []);
    $domains = collect($coverage['domains'] ?? []);
    $referencePack = (array) ($coverage['reference_pack'] ?? []);
    $statusLabels = [
        'unobserved' => ['label' => '未観測', 'class' => 'badge-slate'],
        'observing' => ['label' => '観測中', 'class' => 'badge-slate'],
        'needs_attention' => ['label' => '要確認', 'class' => 'badge-rose'],
        'developing' => ['label' => '伸ばし中', 'class' => 'badge-slate'],
        'stable' => ['label' => '安定', 'class' => 'badge-green'],
        'low_confidence' => ['label' => '参考1件', 'class' => 'badge-slate'],
    ];
@endphp

<section
    class="page-card border-cyan-300/15 p-5 sm:p-6"
    data-study-surface="ap_subject_a_coverage"
    data-ap-subject-a-coverage
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
                AP SUBJECT A COVERAGE
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                科目Aの分野横断Coverage
            </h2>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                Plan内のTaskを横断し、採点済みQuestion Bank問題だけを集計します。
                公式Coverageと正答観測は別指標で、Task進捗やMasteryではありません。
            </p>
        </div>

        @if (($coverage['official_coverage_percent'] ?? null) !== null)
            <span class="badge badge-slate">
                公式Pack {{ (int) $coverage['official_coverage_percent'] }}%
            </span>
        @endif
    </div>

    @if (! (bool) ($coverage['has_assessed_activity'] ?? false))
        <div class="mt-4 rounded-xl border border-white/8 bg-slate-950/20 p-4">
            <p class="text-sm font-black text-slate-200">観測待ち</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">
                Question Bank問題を採点すると、テクノロジ・マネジメント・ストラテジのCoverageと正答観測がここへ蓄積されます。
                未採点SessionやAI生成問題だけではCoverageを作りません。
            </p>
            @if (! empty($referencePack))
                <p class="mt-2 text-[10px] text-slate-600">
                    基準Pack: {{ $referencePack['title'] ?? 'AP科目A公式Pack' }}
                </p>
            @endif
        </div>
    @else
        <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                <p class="text-[10px] text-slate-500">公式ユニークCoverage</p>
                <strong class="mt-1 block text-lg text-slate-100">
                    {{ (int) ($coverage['official_unique_question_count'] ?? 0) }}
                    @if (($referencePack['question_count'] ?? null) !== null)
                        / {{ (int) $referencePack['question_count'] }}
                    @endif
                </strong>
            </div>
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                <p class="text-[10px] text-slate-500">採点exposure</p>
                <strong class="mt-1 block text-lg text-slate-100">
                    {{ (int) ($coverage['assessed_exposure_count'] ?? 0) }}
                </strong>
            </div>
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                <p class="text-[10px] text-slate-500">ユニークBank問題</p>
                <strong class="mt-1 block text-lg text-slate-100">
                    {{ (int) ($coverage['unique_question_count'] ?? 0) }}
                </strong>
            </div>
            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                <p class="text-[10px] text-slate-500">採点Session</p>
                <strong class="mt-1 block text-lg text-slate-100">
                    {{ (int) ($coverage['assessed_session_count'] ?? 0) }}
                </strong>
            </div>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            @foreach ($domains as $domain)
                @php
                    $status = $statusLabels[$domain['status'] ?? 'unobserved']
                        ?? $statusLabels['unobserved'];
                    $parents = collect($domain['parent_topics'] ?? []);
                @endphp
                <article
                    class="rounded-2xl border border-white/8 bg-slate-950/25 p-4"
                    data-ap-subject-a-domain="{{ $domain['label'] ?? '' }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-black text-slate-100">
                                {{ $domain['label'] ?? '分野' }}
                            </p>
                            <p class="mt-1 text-[10px] text-slate-600">
                                採点 {{ (int) ($domain['assessed_exposure_count'] ?? 0) }}問
                                · unique {{ (int) ($domain['unique_question_count'] ?? 0) }}
                            </p>
                        </div>
                        <span class="badge {{ $status['class'] }}">
                            {{ $status['label'] }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <div class="rounded-lg border border-white/6 bg-black/10 p-2">
                            <p class="text-[10px] text-slate-600">公式Coverage</p>
                            <p class="mt-1 text-sm font-black text-slate-200">
                                {{ (int) ($domain['official_unique_question_count'] ?? 0) }}
                                @if (($domain['official_expected_count'] ?? null) !== null)
                                    / {{ (int) $domain['official_expected_count'] }}
                                @endif
                            </p>
                            @if (($domain['official_coverage_percent'] ?? null) !== null)
                                <p class="mt-1 text-[10px] text-cyan-300">
                                    {{ (int) $domain['official_coverage_percent'] }}%
                                </p>
                            @endif
                        </div>
                        <div class="rounded-lg border border-white/6 bg-black/10 p-2">
                            <p class="text-[10px] text-slate-600">正答観測</p>
                            <p class="mt-1 text-sm font-black text-slate-200">
                                @if (($domain['observed_correct_rate_percent'] ?? null) !== null)
                                    {{ (int) $domain['observed_correct_rate_percent'] }}%
                                @else
                                    —
                                @endif
                            </p>
                            <p class="mt-1 text-[10px] text-slate-600">
                                ○ {{ (int) ($domain['correct_count'] ?? 0) }}
                                / △ {{ (int) ($domain['partial_count'] ?? 0) }}
                                / × {{ (int) ($domain['incorrect_count'] ?? 0) }}
                            </p>
                        </div>
                    </div>

                    @if ($parents->isNotEmpty())
                        <div class="mt-3 border-t border-white/8 pt-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-600">
                                TOPIC OBSERVATION
                            </p>
                            <div class="mt-2 grid gap-2">
                                @foreach ($parents as $parent)
                                    @php
                                        $parentStatus = $statusLabels[$parent['status'] ?? 'low_confidence']
                                            ?? $statusLabels['low_confidence'];
                                    @endphp
                                    <div class="flex items-center justify-between gap-3 rounded-lg border border-white/6 bg-black/10 px-2.5 py-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-[11px] font-bold text-slate-300">
                                                {{ $parent['label'] ?? 'Topic' }}
                                            </p>
                                            <p class="mt-0.5 text-[10px] text-slate-600">
                                                {{ (int) ($parent['assessed_exposure_count'] ?? 0) }}問
                                                @if (($parent['observed_correct_rate_percent'] ?? null) !== null)
                                                    · {{ (int) $parent['observed_correct_rate_percent'] }}%
                                                @endif
                                            </p>
                                        </div>
                                        <span class="badge {{ $parentStatus['class'] }}">
                                            {{ $parentStatus['label'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-4 rounded-xl border border-white/8 bg-slate-950/20 p-3">
            <p class="text-[10px] leading-4 text-slate-600">
                公式Coverageは基準公式Packのユニーク採点問題数です。同じ問題を再度解いてもCoverageは増えません。
                正答観測はCore Packを含むQuestion Bank採点exposureを使うため、補強演習の実績も反映されます。
                3問未満の分野は弱点断定せず「観測中」とします。
            </p>
            @if (! empty($referencePack))
                <p class="mt-2 text-[10px] text-slate-600">
                    基準Pack: {{ $referencePack['title'] ?? '' }}
                    · 最新最大 {{ (int) ($coverage['session_limit'] ?? 200) }} Sessionを集計
                </p>
            @endif
        </div>
    @endif
</section>
