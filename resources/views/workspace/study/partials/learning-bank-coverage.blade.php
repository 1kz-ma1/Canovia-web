@php($coverage = $learningBankTopics ?? ['available' => false])
@if(($canEdit ?? false) && ($coverage['available'] ?? false))
<section class="page-card p-5 sm:p-6" data-study-learning-bank-coverage
    data-study-bank-source-count="{{ $coverage['source_question_total'] }}">
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">AP SUBJECT A / BANK TAG SAMPLE</p>
    <h2 class="mt-1 text-lg font-bold text-slate-50">未回答の分野を見落とさないための問題集マップ</h2>
    @if($coverage['scope_confirmed'])
        <p class="mt-2 text-xs text-slate-300" data-study-bank-scope-confirmed>
            このPlan・Taskの記録からAP科目A向けと判定しました。
        </p>
    @else
        <p class="mt-2 text-xs text-amber-200" data-study-bank-scope-unconfirmed>
            Plan名からAPの可能性を検出しました。科目Aが対象かは未確定のため、参考情報として表示しています。
        </p>
    @endif
    <p class="mt-2 text-xs leading-6 text-slate-400">
        この表は公開済みAP科目A Question Bankのタグを集計したもので、
        公式シラバスの網羅率・本試験の分野別出題比率・習熟度を示すものではありません。
        「未回答」はこのPlanでの同一問題IDの回答記録がないという意味です。
        外部教材で学習済みでも未回答と表示されます。
    </p>
    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-300" data-study-bank-preflight-summary>
        <span>対象Bank {{ $coverage['eligible_pack_count'] }}件</span>
        <span>対応する問題ID {{ $coverage['source_question_total'] }}問</span>
        <span>公開前チェックに不適合 {{ $coverage['blocked_pack_count'] }}件</span>
        <span>人的監修の承認をこの画面では確認できないBank {{ $coverage['unattested_pack_count'] }}件</span>
    </div>
    @if($coverage['exam_question_count'] !== null)
        <p class="mt-2 text-xs text-slate-400" data-study-bank-verified-format>
            参考：確認済みのAP科目A試験プロフィールでは{{ $coverage['exam_question_count'] }}問。
            下の問題集の構成比を本試験の出題配分とみなすことはできません。
        </p>
    @endif
    @if(empty($coverage['rows']))
        <p class="mt-4 text-sm text-slate-300" data-study-bank-coverage-empty>
            学習候補として集計できる公開問題・分野タグがありません。
            問題を作成したり、未回答を苦手と推定したりはしません。
        </p>
    @else
        <ol class="mt-4 space-y-2">
            @foreach($coverage['rows'] as $item)
                <li class="rounded-xl border border-slate-700 p-3" data-study-bank-topic
                    data-study-bank-topic-state="{{ $item['state'] }}">
                    <div class="flex items-center justify-between gap-3">
                        <p class="break-words text-sm font-semibold text-slate-100">{{ $item['topic'] }}</p>
                        <span class="shrink-0 text-xs text-slate-300">
                            @if($item['state'] === 'no_record')
                                回答記録なし
                            @elseif($item['state'] === 'seen_all')
                                集計内の問題IDは回答済み
                            @else
                                一部に回答記録あり
                            @endif
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-400" data-study-bank-topic-evidence>
                        公開前チェックを通過した対応問題 {{ $item['available_questions'] }}問
                        · 回答記録のある問題ID {{ $item['answered_source_questions'] }}問
                        · このPlanで未回答の問題ID {{ $item['unanswered_source_questions'] }}問
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        問題集サンプル内のタグ付与率 {{ $item['sample_percent'] ?? 0 }}%（複数タグは重複計上）。
                        公式試験の出題率ではありません。
                    </p>
                </li>
            @endforeach
        </ol>
        @if($coverage['has_more_topics'])
            <p class="mt-3 text-xs text-slate-400" data-study-bank-coverage-bounded>
                上位{{ $coverage['topic_limit'] }}分野のみ表示。
                問題集と問題の走査には上限があるため、全範囲を評価したとは扱いません。
            </p>
        @endif
    @endif
    <p class="mt-3 text-xs text-slate-400">
        公開前チェックはデータ構造・出典欄などの整合性を確認しますが、
        問題の正確性、ライセンス、教育効果について人的監修の完了を証明するものではありません。
        この表から学習RunやTask進捗は自動作成・更新されません。
    </p>
    <a href="{{ route('plans.study_scope.index', $plan) }}"
        class="btn-secondary mt-4 inline-flex min-h-10 items-center px-3 text-xs font-semibold"
        data-study-bank-review-scope>
        学習範囲の記録・教材を確認する →
    </a>
</section>
@endif
