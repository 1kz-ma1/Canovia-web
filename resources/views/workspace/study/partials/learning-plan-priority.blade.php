@php($priority = $learningPlanCandidates ?? ['items' => [], 'has_history' => false])
@if(($canEdit ?? false) && data_get($priority, 'has_history', false))
<section class="page-card p-5 sm:p-6" data-study-learning-plan-priority
    data-study-plan-deadline-days="{{ data_get($priority, 'deadline_days', '') }}">
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">PLAN-WIDE NEXT LEARNING / ANSWER RECORDS</p>
    <h2 class="mt-1 text-lg font-bold text-slate-50">次に確認する学習候補</h2>
    <p class="mt-2 text-xs leading-6 text-slate-400">
        このPlanの回答実績がある最大{{ data_get($priority, 'task_limit', 12) }} Taskから、
        今すぐ再確認できる内容、後日の復習、既存Task優先度を比べます。
        これは保存済みの記録からの補助的な並びで、Study Intelligenceの判断を置き換えません。
        合格率・習熟度・Task完了は推定せず、選ぶまで計画や進捗を変更しません。
    </p>
    <p class="mt-2 text-xs font-semibold text-slate-300" data-study-plan-exam-deadline>
        @if(data_get($priority, 'deadline_days') === null)
            Plan期限：未登録（残り日数は判断に使用しません）
        @elseif($priority['deadline_days'] < 0)
            Plan期限：{{ abs($priority['deadline_days']) }}日前に経過
        @elseif($priority['deadline_days'] === 0)
            Plan期限：今日
        @else
            Plan期限：あと{{ $priority['deadline_days'] }}日
        @endif
    </p>
    <p class="mt-1 text-xs leading-5 text-slate-500">
        期限はこのPlanの全Taskに共通するため、同一Plan内のTask間順位には加点しません。
        急いで出題するために問題品質や出題可能数の条件を緩めることもありません。
    </p>

    @if(empty($priority['items']))
        <p class="mt-4 text-sm text-slate-300" data-study-plan-priority-empty>
            現在の対象回答からは順位を付けられません。通常の学習Actionを利用できます。
        </p>
    @else
        <ol class="mt-4 space-y-3">
            @foreach($priority['items'] as $position => $candidate)
                <li class="rounded-xl border border-slate-700 p-4"
                    data-study-plan-priority-item
                    data-study-plan-priority-task="{{ $candidate['task_id'] }}"
                    data-study-plan-priority-state="{{ $candidate['state'] }}"
                    data-study-plan-priority-actionable="{{ $candidate['actionable'] ? 'true' : 'false' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs font-bold text-slate-200">
                            候補 {{ $position + 1 }} · {{ $candidate['task_title'] }}
                        </p>
                        <span class="text-xs text-slate-400">既存Task優先度 {{ $candidate['task_priority'] }}</span>
                    </div>
                    <p class="mt-2 text-sm font-semibold text-slate-100">
                        {{ $candidate['topic'] }}：{{ $candidate['action'] }}
                    </p>
                    <p class="mt-2 text-xs leading-6 text-slate-300" data-study-plan-priority-reason>
                        {{ $candidate['reason'] }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        確認された異なる誤答問題 {{ $candidate['distinct_missed_questions'] }}問
                        · 条件を満たす公開問題集 {{ $candidate['pack_count'] }}件（表示上限あり）
                    </p>
                    <div class="mt-3">
                        <a href="{{ $candidate['url'] }}"
                            data-study-plan-priority-open
                            class="{{ $candidate['actionable'] ? 'btn-primary' : 'btn-secondary' }} inline-flex min-h-10 items-center px-3 text-xs font-semibold">
                            {{ $candidate['actionable'] ? '問題集を確認して本人が始める →' : '履歴と利用条件を確認する →' }}
                        </a>
                    </div>
                </li>
            @endforeach
        </ol>
        @if(data_get($priority, 'has_more'))
            <p class="mt-3 text-xs text-slate-400">表示は上位3件まで。ほかのTaskは各Taskの学習履歴で確認できます。</p>
        @endif
    @endif
    <p class="mt-3 text-xs text-slate-500">
        24時間の復習目安は学習効率の保証ではありません。新しい問題が足りない候補は開始できず、
        自動採点結果だけでTask進捗・Evidence・次Actionは更新しません。
    </p>
</section>
@endif
