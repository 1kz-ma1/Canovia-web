@extends('layouts.app')
@section('title', '1問から始める学習 | Canovia')
@section('content')
<div class="mx-auto max-w-3xl space-y-5">
    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="学習の移動先">
        <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="font-semibold text-sky-400">← 従来のAI演習へ</a>
        <a href="{{ route('plans.tasks.learning.history', [$plan, $task]) }}" class="font-semibold text-sky-300" data-learning-history-link>学習履歴・復習のヒント →</a>
    </nav>
    @if($activeRuns->isNotEmpty())
        <section class="page-card p-5 sm:p-7" data-adaptive-learning-resume>
            <h2 class="text-lg font-bold text-slate-50">前回の続き</h2>
            <div class="mt-3 space-y-3">
                @foreach($activeRuns as $run)
                    <a class="block rounded-xl border border-slate-600 p-3 text-sm text-sky-300" href="{{ $run->mode === 'exam' ? route('plans.tasks.learning.exam.show', [$plan, $task, $run]) : route('plans.tasks.learning.show', [$plan, $task, $run]) }}">
                        {{ $run->pack_title_snapshot }} · {{ match ($run->mode) { 'understanding' => '理解', 'practice' => '演習', 'exam' => '模擬試験', default => '学習' } }} · {{ $run->created_at?->format('Y/m/d H:i') }} → 続きを開く
                    </a>
                @endforeach
            </div>
        </section>
    @endif
    <section class="page-card p-5 sm:p-7" data-adaptive-learning-start>
        <p class="text-xs font-bold tracking-widest text-cyan-400">ADAPTIVE LEARNING / EARLY PILOT</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">1問から始める学習</h1>
        <p class="mt-2 text-sm leading-7 text-slate-300">{{ $plan->title }} / {{ $task->title }}</p>
        <p class="mt-3 text-sm leading-7 text-slate-300">問題集の選択問題を1問ずつ解けます。回答はその都度保存。1問で終了しても、途中でページを閉じても記録は消えません。以前のAI演習も引き続き利用できます。</p>
        <div class="mt-4 rounded-xl border border-amber-700/35 bg-amber-900/10 p-3 text-sm text-amber-100">
            現在の1問ずつ学習はQuestion Bankの単一選択・複数選択・数値問題に対応。開始直後はBank順で、複数回の誤答が確認できた場合のみ先の候補を再検討します。学力を断定する高度な推論ではありません。模擬試験モードは試験別仕様の確認後に提供します。
        </div>
        @if ($errors->any())<p role="alert" class="mt-3 text-sm text-red-300">{{ $errors->first() }}</p>@endif
        @if ($packs->isEmpty())
            <p class="mt-5 text-sm text-slate-300" data-adaptive-learning-no-packs>現在、1問ごとに採点できる公開済みQuestion Bankがありません。問題集の公開が必要です。</p>
            <p class="mt-2 text-sm text-slate-400">今すぐ取り組む場合は、従来のAI演習で問題を準備すると、1問ずつ画面を切り替えて回答できます（採点はセット終了時です）。</p>
            <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="btn-primary mt-4 inline-flex">AI演習で1問ずつ回答する</a>
        @else
            <form method="POST" action="{{ route('plans.tasks.learning.start', [$plan, $task]) }}" class="mt-5 space-y-5">
                @csrf
                <input type="hidden" name="start_request_id" value="{{ old('start_request_id', $startRequestId) }}">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-bold text-slate-100">学習モード（ユーザーが選択）</legend>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-600 p-3 text-sm text-slate-200">
                        <input type="radio" name="mode" value="understanding" @checked(old('mode', 'understanding') === 'understanding')>
                        <span><strong>理解モード</strong><br>採点後に解説まで表示</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-600 p-3 text-sm text-slate-200">
                        <input type="radio" name="mode" value="practice" @checked(old('mode') === 'practice')>
                        <span><strong>演習モード</strong><br>正答をすぐ表示し、解説は任意で開けます</span>
                    </label>
                    <p class="text-xs text-slate-400">模擬試験モード：試験別の固定問題セット・制限時間が検証できるまで未提供。検証済み試験プロファイルと対応Question Bankがある場合だけ開始できます。</p>
                </fieldset>
                <label class="block text-sm font-bold text-slate-100" for="question-pack">問題集</label>
                <select class="form-control w-full" name="question_pack_id" id="question-pack" required>
                    @foreach($packs as $pack)
                        <option value="{{ $pack->id }}" @selected(old('question_pack_id') == $pack->id)>{{ $pack->title }} / {{ $pack->subject ?: '分野未指定' }}（v{{ $pack->version }}）</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400">問題の出典は選択した公開Question Bankに従います。現時点では試験本番の問題数・範囲を保証しません。</p>
                <button type="submit" class="btn-primary">問題を始める</button>
            </form>
        @endif
    </section>

    <section class="page-card p-5 sm:p-7" data-learning-history-overview>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-bold text-slate-50">これまでの学習</h2>
            <a href="{{ route('plans.tasks.learning.history', [$plan, $task]) }}" class="text-sm font-semibold text-sky-300">履歴を振り返る →</a>
        </div>
        @if($learningHistory['saved_count'] === 0)
            <p class="mt-3 text-sm leading-6 text-slate-300">まだ新方式の回答履歴はありません。1問解くと、回答や考え方をここから見直せます。</p>
        @else
            <p class="mt-2 text-sm text-slate-300">直近の回答記録：{{ $learningHistory['saved_count'] }}件・記録上の正解：{{ $learningHistory['correct_count'] }}件 <span class="text-xs text-slate-400">（最大{{ $learningHistory['window'] }}件）</span></p>
            @if($learningHistory['review_topics']->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($learningHistory['review_topics']->take(3) as $topic)
                        <span class="rounded-full border border-amber-700/50 px-3 py-1 text-xs text-amber-200">{{ $topic['name'] }}：復習候補</span>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-xs text-slate-400">繰り返しの誤答に基づく復習候補はまだありません。</p>
            @endif
        @endif
        <p class="mt-2 text-xs leading-5 text-slate-400">復習候補は直近の記録を整理したもので、苦手分野や習熟度の判定ではありません。</p>
    </section>

    <details class="page-card p-5 sm:p-7" data-learning-mode-ranking>
        <summary class="cursor-pointer text-base font-semibold text-slate-100">おすすめモードと、その根拠を見る</summary>
        <h2 class="mt-4 text-base font-bold text-slate-50">今日のおすすめ（参考順位）</h2>
        <p class="mt-2 text-xs leading-6 text-slate-300">{{ $learningRecommendations['evidence'] }}</p>
        <p class="mt-1 text-xs leading-6 text-slate-400">{{ $learningRecommendations['diagnostic_hint'] }}</p>
        <ol class="mt-4 space-y-3">
            @foreach($learningRecommendations['ranking'] as $rank => $suggestedMode)
                <li class="rounded-xl border border-slate-600 p-3 text-sm text-slate-200" data-learning-recommendation="{{ $suggestedMode['mode'] }}">
                    <p class="font-bold">{{ $rank + 1 }}位：{{ $suggestedMode['label'] }}{{ $suggestedMode['available'] ? '' : '（現在は未対応）' }}</p>
                    <p class="mt-1 leading-6 text-slate-300">{{ $suggestedMode['reason'] }}</p>
                </li>
            @endforeach
        </ol>
        <p class="mt-3 text-xs leading-6 text-slate-400">順位は説明可能な暫定ルールです。必ずしもこの順番で学習する必要はありません。どのモードを使うかは下の操作で自由に選べます。</p>
    </details>

    @if(($examProfiles ?? collect())->isNotEmpty())
        <section class="page-card p-5 sm:p-7" data-adaptive-exam-format>
            <h2 class="text-lg font-bold text-slate-50">確認済みの試験形式</h2>
            @foreach($examProfiles as $profile)
                <p class="mt-2 text-sm text-slate-300">
                    {{ $profile['exam_code'] }} {{ $profile['subject'] }}：
                    {{ $profile['question_count'] }}問 / {{ $profile['duration_minutes'] }}分
                    （{{ ($profile['response_format'] ?? '') === 'single_choice' ? '単一選択' : 'その他' }}）
                </p>
            @endforeach
            <p class="mt-2 text-xs leading-6 text-slate-400">
                試験形式の検証と、実際に出題する問題集の品質・出典・利用条件の検証は別です。
                管理者が当該版の問題集を明示確認するまでは本番形式の模試を開始できません。
                科目Bなど記述式の採点が未対応の試験は有効化しません。
            </p>
            @if(($examOptions ?? collect())->isEmpty())
                <p class="mt-3 text-sm font-semibold text-amber-300" data-adaptive-exam-no-ready-pack>
                    現在、上記試験形式で開始できる検証済みの問題セットはありません。通常の1問ずつ学習をご利用ください。
                </p>
            @endif
        </section>
    @endif
    @if(($examOptions ?? collect())->isNotEmpty())
        <section class="page-card p-5 sm:p-7" data-adaptive-exam-entry>
            <h2 class="text-lg font-bold text-slate-50">模擬試験モード（本番形式）</h2>
            <p class="mt-2 text-sm leading-6 text-slate-300">
                開始前に全問と時間を固定し、終了まで正答・解説を表示しません。
                途中離脱しても制限時間は進みます。
            </p>
            @foreach($examOptions as $option)
                @php($profile = $option['profile'])
                @php($pack = $option['pack'])
                <form method="POST" action="{{ route('plans.tasks.learning.exam.start', [$plan, $task]) }}"
                      class="mt-4 space-y-3 rounded-xl border border-slate-700 p-4"
                      data-adaptive-exam-option="{{ $profile['key'] }}">
                    @csrf
                    <input type="hidden" name="start_request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="exam_profile_key" value="{{ $profile['key'] }}">
                    <input type="hidden" name="question_pack_id" value="{{ $pack->id }}">
                    <p class="text-sm font-semibold text-slate-100">
                        {{ $profile['exam_code'] }} {{ $profile['subject'] }}
                        · {{ $profile['question_count'] }}問 / {{ $profile['duration_minutes'] }}分
                    </p>
                    <p class="text-xs text-slate-400">{{ $pack->title }}（v{{ $pack->version }}）</p>
                    <button type="submit" class="btn-primary">この問題集で本番形式を始める</button>
                </form>
            @endforeach
        </section>
    @endif

</div>
@endsection
