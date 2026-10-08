@php
    $scopeCount = (int) data_get(
        $studyWorkspaceState ?? [],
        'confirmed_scope_count',
        0,
    );
    $scoreCount = (int) data_get(
        $studyWorkspaceState ?? [],
        'score_observation_count',
        0,
    );
    $resourceCount = $plan->resources()->count();
@endphp

<div class="space-y-5" data-study-view-panel="preparation">
    <section class="page-card p-5 sm:p-6">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-sky-300">PREPARATION</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">このPlanの学習条件を整える。</h2>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                範囲・教材・スコアなど、次の学習判断に使う入力をここで管理します。
            </p>
        </div>

        @if (str_contains($plan->title.' '.($plan->description ?? ''), '簿記'))
            <div class="mt-5 rounded-2xl border border-amber-300/20 bg-amber-300/[0.03] p-4" data-study-bookkeeping-starting-point>
                <p class="text-[10px] font-black uppercase tracking-[.12em] text-amber-300">ADAPTIVE STARTING POINT</p>
                <h3 class="mt-2 text-sm font-black text-slate-100">3級の復習から2級の先取りまで、理解度に合わせて決める</h3>
                <p class="mt-2 text-xs leading-5 text-slate-400">授業で習った範囲を一律にやり直さず、基礎の12問で復習候補を確認します。結果から2級の導入を試すか判断するための参考にします。</p>
                <a href="{{ route('plans.bookkeeping_placement.show', $plan) }}" class="btn-secondary mt-3 inline-flex min-h-11 items-center px-4 text-xs" data-study-bookkeeping-diagnostic-link>現在地を診断する →</a>
                <a href="{{ route('plans.bookkeeping_journal.show', $plan) }}" class="btn-secondary mt-3 ml-2 inline-flex min-h-11 items-center px-4 text-xs" data-study-bookkeeping-journal-link>仕訳を直接入力して練習する →</a>
            </div>
        @endif

        @if (is_array(data_get($studyWorkspaceState ?? [], 'official_exam_reference')))
            @php $official = data_get($studyWorkspaceState, 'official_exam_reference'); @endphp
            <aside class="mt-5 rounded-2xl border border-sky-300/15 bg-sky-300/[0.025] p-4"
                data-study-official-exam-reference="{{ data_get($official, 'key') }}">
                <p class="text-xs font-black text-sky-200">公式試験範囲は公開済みです</p>
                <p class="mt-2 text-xs leading-5 text-slate-300">
                    {{ data_get($official, 'issuer') }}の
                    {{ data_get($official, 'exam_name') }}シラバス
                    Ver.{{ data_get($official, 'syllabus_version') }}
                    （{{ data_get($official, 'applicable_period') }}）を参照します。
                    Canoviaに個別の範囲登録がなくても、試験範囲そのものが未確定という意味ではありません。
                    あなたの理解度は演習Evidenceから確認します。
                </p>
                <a href="{{ data_get($official, 'source_url') }}" target="_blank" rel="noopener noreferrer"
                    class="mt-3 inline-flex text-xs font-bold text-sky-300 underline underline-offset-4"
                    data-study-official-exam-source>IPA公式シラバスを確認 ↗</a>
            </aside>
        @endif

        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <a href="{{ route('plans.study_scope.index', $plan) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-sky-300/30">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-black text-slate-100">学習範囲</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">試験範囲・単元・対象領域を整理します。</p>
                    </div>
                    <span class="badge badge-slate">{{ $scopeCount }}</span>
                </div>
            </a>

            <a href="{{ route('plans.resources.index', $plan) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-cyan-300/30">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-black text-slate-100">教材・資料</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">プリント・PDF・参考資料を管理します。</p>
                    </div>
                    <span class="badge badge-slate">{{ $resourceCount }}</span>
                </div>
            </a>

            <a href="{{ route('plans.study_scores.index', $plan) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-emerald-300/30">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-black text-slate-100">外部スコア</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">模試・公式結果などの現在地を記録します。</p>
                    </div>
                    <span class="badge badge-slate">{{ $scoreCount }}</span>
                </div>
            </a>

            <a href="{{ route('plans.show', $plan) }}" class="rounded-2xl border border-white/8 bg-slate-950/25 p-4 transition hover:border-violet-300/30">
                <p class="text-sm font-black text-slate-100">Plan詳細</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">Task・期限・計画全体を確認します。</p>
            </a>
        </div>
    </section>

    <div
        class="space-y-5"
        data-study-workspace-composed
        data-study-learning-type="{{ data_get($studyLearningType ?? [], 'key', 'general_learning') }}"
    >
        @foreach (data_get($composition, 'surfaces', []) as $surface)
            @include($surface['partial'], $surface['data'] ?? [])
        @endforeach
    </div>
</div>
