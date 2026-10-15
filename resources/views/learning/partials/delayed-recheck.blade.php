@if($cue ?? null)
    <section class="page-card p-5 sm:p-7" data-learning-delayed-recheck
        data-learning-delayed-state="{{ $cue['state'] }}">
        <p class="text-xs font-bold tracking-widest text-cyan-400">LATER REVIEW / RECORDED EVIDENCE</p>
        <h2 class="mt-2 text-lg font-bold text-slate-50">後日の再確認の目安：{{ $cue['topic'] }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-300">
            このTaskで、異なる問題の過去の誤答と、その後の直近2件の正解が記録されています。
            24時間は表示上の目安であり、理解度・記憶定着・試験の合格率を予測するものではありません。
        </p>
        <p class="mt-2 text-xs leading-6 text-slate-400">
            最終回答：{{ $cue['last_answered_at']->format('Y/m/d H:i') }}
            · 再確認の目安：{{ $cue['available_at']->format('Y/m/d H:i') }}
        </p>
        @if($cue['state'] === 'later')
            <p class="mt-3 text-sm font-semibold text-slate-300" data-learning-delayed-not-yet>
                まだ24時間経過していません。別問題での後日確認は、この画面を次回開いた際に再判定します。
            </p>
        @else
            <p class="mt-3 text-sm font-semibold text-emerald-300" data-learning-delayed-due>
                最終回答から24時間以上経過しました。別問題があれば、本人が選んで再確認できます。
            </p>
            @if($isHistory ?? false)
                <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}"
                    data-learning-delayed-open
                    class="mt-3 inline-flex text-sm font-semibold text-sky-300">別問題の候補を見に行く →</a>
            @elseif(($delayedPacks ?? collect())->isEmpty())
                <p class="mt-3 rounded-lg border border-amber-700/40 p-3 text-sm text-amber-200"
                    data-learning-delayed-no-pack>
                    現在、このTaskに未回答の問題を2問確保できる公開問題集はありません。
                    出題を捏造せず、追加された後に再確認してください。
                </p>
            @else
                <form method="POST" action="{{ route('plans.tasks.learning.start', [$plan, $task]) }}"
                    class="mt-4 space-y-3" data-learning-delayed-start>
                    @csrf
                    <input type="hidden" name="start_request_id" value="{{ $delayedStartRequestId }}">
                    <input type="hidden" name="mode" value="understanding">
                    <input type="hidden" name="delayed_distinct" value="1">
                    <input type="hidden" name="delayed_topic" value="{{ $cue['topic'] }}">
                    <input type="hidden" name="delayed_source_signature" value="{{ $cue['source_signature'] }}">
                    <label for="delayed-review-pack" class="block text-sm font-semibold text-slate-200">
                        別問題が2問以上ある公開問題集
                    </label>
                    <select id="delayed-review-pack" name="question_pack_id" class="form-control w-full" required>
                        @foreach($delayedPacks as $pack)
                            <option value="{{ $pack->id }}">{{ $pack->title }} / {{ $pack->subject ?: '分野未指定' }}（v{{ $pack->version }}）</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-primary">後日の別問題2問を始める</button>
                </form>
            @endif
        @endif
        <p class="mt-3 text-xs leading-6 text-slate-400">
            予告通知・定期実行・Task進捗更新は行いません。回答記録が変わった場合は開始時にも確認し直します。
        </p>
    </section>
@endif
