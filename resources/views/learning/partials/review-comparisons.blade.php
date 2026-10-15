@if(! empty($history['review_comparisons']))
    <section class="page-card p-5 sm:p-7" data-learning-review-comparisons>
        <p class="text-xs font-bold tracking-widest text-cyan-400">REVIEW COMPARISON / RECORDED ANSWERS</p>
        <h2 class="mt-2 text-lg font-bold text-slate-50">復習前後の回答を見比べる</h2>
        <p class="mt-2 text-xs leading-6 text-slate-400">
            同じ出題元の問題IDを使って、復習前と復習時の採点記録を照合しています。
            直近{{ $history['window'] }}件以内の回答に限るため、過去の記録が見つからなくても初回答とは限りません。
            正解の増加は記録上の変化であり、理解度・定着・合格率の改善を示すものではありません。
        </p>
        <div class="mt-4 space-y-4">
            @foreach($history['review_comparisons'] as $comparison)
                <article class="rounded-xl border border-slate-700 p-4"
                    data-learning-review-comparison
                    data-learning-review-run-id="{{ $comparison['run_id'] }}"
                    data-learning-review-topic-state="{{ $comparison['topic_state'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-slate-100">
                                {{ $comparison['pack_title'] }} / 分野を指定した復習
                            </h3>
                            @if($comparison['started_at'])
                                <p class="mt-1 text-xs text-slate-400">
                                    開始 {{ $comparison['started_at']->format('Y/m/d H:i') }}
                                </p>
                            @endif
                            <p class="mt-1 text-xs text-slate-300">
                                共通する分野タグ：{{ $comparison['topic'] ?? '一意に確認できません' }}
                            </p>
                        </div>
                        <p class="text-xs text-slate-300" data-learning-review-comparison-count>
                            最初の2問のうち回答 {{ $comparison['answered_initial_count'] }}件
                            · 同一問題の比較 {{ $comparison['comparable_count'] }}件
                        </p>
                    </div>
                    <div class="mt-3 rounded-lg border border-slate-700 p-3 text-xs leading-6 text-slate-300"
                        data-learning-review-source-spacing
                        data-learning-review-spacing="{{ $comparison['source_spacing']['spacing'] }}">
                        <p data-learning-review-source-counts>
                            別問題での再確認 {{ $comparison['source_spacing']['different_count'] }}問
                            · 同じ問題の解き直し {{ $comparison['source_spacing']['same_count'] }}問
                            · 区別できない回答 {{ $comparison['source_spacing']['unknown_count'] }}問
                        </p>
                        @if($comparison['source_spacing']['spacing'] === 'spaced_24h')
                            <p class="mt-1 text-emerald-300" data-learning-review-spaced>
                                同じ分野の直前の記録から24時間以上経過（約{{ $comparison['source_spacing']['hours_since_previous'] }}時間）。
                                時間を空けた回答記録であり、記憶の定着は未判定です。
                            </p>
                        @elseif($comparison['source_spacing']['spacing'] === 'within_24h')
                            <p class="mt-1" data-learning-review-within-day>
                                同じ分野の直前の記録から24時間未満（約{{ $comparison['source_spacing']['hours_since_previous'] }}時間）。
                                時間を空けた復習とは区別します。
                            </p>
                        @else
                            <p class="mt-1" data-learning-review-spacing-unknown>
                                比較できる前回の回答日時・分野記録が不足し、復習間隔は判定できません。
                            </p>
                        @endif
                    </div>
                    <ol class="mt-3 space-y-2">
                        @foreach($comparison['answers'] as $answer)
                            <li class="rounded-lg border border-slate-700/80 p-3" data-learning-review-comparison-answer
                                data-learning-review-question-id="{{ $answer['question_id'] }}">
                                <p class="text-xs text-slate-400">復習Run 第{{ $answer['ordinal'] }}問 · 出題元の問題ID {{ $answer['question_id'] }}</p>
                                <p class="mt-1 whitespace-pre-line break-words text-sm font-semibold text-slate-100">{{ $answer['prompt'] }}</p>
                                <p class="mt-1 text-xs text-slate-300" data-learning-review-source-relation
                                    data-learning-review-source-type="{{ $answer['source_relation'] }}">
                                    @if($answer['source_relation'] === 'same_source')
                                        同じ問題の解き直し（記録範囲内に先行する出題あり）
                                    @elseif($answer['source_relation'] === 'different_source')
                                        別問題での再確認（同じ分野で先行する別の問題あり）
                                    @else
                                        出題の違いは、この記録範囲では判定できません
                                    @endif
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="text-slate-300" data-learning-review-comparison-before>
                                        前回：{{ $answer['before'] === null ? '比較できる過去回答なし' : ($answer['before'] ? '正解' : '不正解') }}
                                    </span>
                                    <span class="text-slate-500" aria-hidden="true">→</span>
                                    <span class="font-semibold {{ $answer['after'] === true ? 'text-emerald-300' : ($answer['after'] === false ? 'text-amber-300' : 'text-slate-400') }}"
                                        data-learning-review-comparison-after>
                                        今回：{{ $answer['after'] === null ? '評価から除外' : ($answer['after'] ? '正解' : '不正解') }}
                                    </span>
                                </div>
                                @if($answer['before'] === null)
                                    <p class="mt-2 text-xs text-slate-500">この記録範囲に、比較できる同一問題の先行回答はありません。</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    @if($comparison['topic_state'] === 'two_recent_correct')
                        <p class="mt-3 text-xs leading-6 text-emerald-300" data-learning-review-topic-rechecked>
                            この分野の直近2件は正解です。繰り返し誤答による復習候補は現時点で表示されません。
                            ただし同じ問題の解き直しを含み、定着は未検証です。
                        </p>
                    @elseif($comparison['topic_state'] === 'still_suggested')
                        <p class="mt-3 text-xs leading-6 text-amber-300" data-learning-review-topic-still-suggested>
                            この分野は現在も繰り返し誤答による復習候補です。
                        </p>
                    @else
                        <p class="mt-3 text-xs leading-6 text-slate-400">
                            この復習と現在の候補の関係は、保存済みの情報からは一意に判断できません。
                        </p>
                    @endif
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs leading-6"
                        data-learning-review-followup-guidance>
                        @if($comparison['topic_state'] === 'still_suggested')
                            <a class="font-semibold text-sky-300"
                                data-learning-review-next-distinct
                                href="{{ route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]) }}">
                                次の確認：別問題があるか調べる →
                            </a>
                            <p class="mt-1 text-slate-400">未回答の別問題が2問以上ある問題集なら、本人の操作で開始できます。</p>
                        @else
                            <a class="font-semibold text-sky-300"
                                data-learning-review-next-ordinary
                                href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}">
                                次の確認：問題集から自分で選ぶ →
                            </a>
                            <p class="mt-1 text-slate-400">翌日以降に再確認する場合も、開始は手動です。現在の復習候補がないときは別問題の優先出題を保証しません。</p>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-slate-400">
                        同一問題での「不正解→正解」{{ $comparison['wrong_to_correct_count'] }}件。
                        別問題や24時間以上の間隔が記録されても、応用力や長期的な定着はこの比較だけでは確認できません。
                    </p>
                </article>
            @endforeach
        </div>
    </section>
@endif
