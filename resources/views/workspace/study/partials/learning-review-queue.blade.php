@php($reviewItems = (array) data_get($learningReviewQueue ?? [], 'items', []))
@if($canEdit && $reviewItems !== [])
<section class="page-card p-5 sm:p-6" data-study-learning-review-queue>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">LEARNING REVIEW / RECORDED ANSWERS</p>
            <h2 class="mt-1 text-lg font-bold text-slate-50">回答から見つかった復習候補</h2>
            <p class="mt-2 text-xs leading-5 text-slate-400">
                異なる問題での誤答記録を優先し、未反映・既存Task優先度で並べています。
                確認画面で採用するまで次Actionは変わりません。合格率・習熟度・Task進捗の判定ではありません。
            </p>
        </div>
    </div>
    <ol class="mt-4 space-y-3">
        @foreach($reviewItems as $index => $candidate)
            <li class="rounded-xl border border-slate-700 p-4" data-study-learning-review-item
                data-study-review-task-id="{{ $candidate['task_id'] }}"
                data-study-review-adopted="{{ $candidate['already_adopted'] ? 'true' : 'false' }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-slate-400">候補 {{ $index + 1 }} · Task：{{ $candidate['task_title'] }}</p>
                        <p class="mt-1 text-sm font-semibold leading-6 text-slate-100">{{ $candidate['action'] }}</p>
                    </div>
                    @if($candidate['already_adopted'])
                        <span class="shrink-0 text-xs font-semibold text-emerald-300" data-study-review-applied>反映済み</span>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-400">
                        異なる誤答問題 {{ $candidate['distinct_missed_questions'] }}問
                        · 記録された誤答 {{ $candidate['incorrect'] }}件
                    </p>
                    <a href="{{ $candidate['review_url'] }}"
                        class="btn-primary inline-flex min-h-10 items-center px-3 text-xs font-semibold"
                        data-study-learning-review-practice>この分野を復習する</a>
                    <a href="{{ $candidate['history_url'] }}"
                        class="btn-secondary inline-flex min-h-10 items-center px-3 text-xs font-semibold"
                        data-study-learning-review-open>
                        {{ $candidate['already_adopted'] ? '回答履歴を確認する' : '復習候補を確認する' }}
                    </a>
                </div>
            </li>
        @endforeach
    </ol>
    @if(data_get($learningReviewQueue, 'has_more', false))
        <p class="mt-3 text-xs text-slate-400">表示は上位3件です。各Taskの学習履歴から、ほかの復習候補も確認できます。</p>
    @endif
</section>
@elseif($canEdit && data_get($learningReviewQueue ?? [], 'has_history', false))
<section class="page-card p-4 sm:p-5" data-study-learning-review-cleared>
    <p class="text-sm font-semibold text-slate-100">現在表示できる繰り返し誤答の復習候補はありません。</p>
    <p class="mt-2 text-xs leading-5 text-slate-400">最近の回答を再集計した結果です。直近で同じ分野に2回続けて正解した場合も候補から外れます。習熟度やTask完了の判定ではなく、対象件数には上限があります。</p>
</section>
@endif
