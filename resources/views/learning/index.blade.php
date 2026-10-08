@extends('layouts.app')
@section('title', '1問から始める学習 | Canovia')
@section('content')
<div class="mx-auto max-w-3xl space-y-5">
    <a href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}" class="text-sm font-semibold text-sky-500">← 従来のAI演習へ</a>
    <section class="page-card p-5 sm:p-7" data-adaptive-learning-start>
        <p class="text-xs font-bold tracking-widest text-cyan-400">ADAPTIVE LEARNING / EARLY PILOT</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">1問から始める学習</h1>
        <p class="mt-2 text-sm leading-7 text-slate-300">{{ $plan->title }} / {{ $task->title }}</p>
        <p class="mt-3 text-sm leading-7 text-slate-300">問題集の選択問題を1問ずつ解けます。回答はその都度保存。1問で終了しても、途中でページを閉じても記録は消えません。以前のAI演習も引き続き利用できます。</p>
        <div class="mt-4 rounded-xl border border-amber-700/35 bg-amber-900/10 p-3 text-sm text-amber-100">
            初期版はQuestion Bankの単一選択問題のみ対応。開始直後はBank順で、複数回の誤答が確認できた場合のみ先の候補を再検討します。学力を断定する高度な推論ではありません。模擬試験モードは試験別仕様の確認後に提供します。
        </div>
        @if ($errors->any())<p role="alert" class="mt-3 text-sm text-red-300">{{ $errors->first() }}</p>@endif
        @if ($packs->isEmpty())
            <p class="mt-5 text-sm text-slate-300">現在、対応する公開問題集がありません。従来のAI演習は利用できます。</p>
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
    @if(($examProfiles ?? collect())->isNotEmpty() && ($examPacks ?? collect())->isNotEmpty())
        <section class="page-card p-5 sm:p-7" data-adaptive-exam-entry>
            <h2 class="text-lg font-bold text-slate-50">模擬試験モード（本番形式）</h2>
            <p class="mt-2 text-sm leading-6 text-slate-300">検証済みの試験別設定だけを使用し、開始前に全問と時間を確定します。終了するまで正誤・解説は表示しません。途中離脱中も制限時間は進みます。</p>
            <form method="POST" action="{{ route('plans.tasks.learning.exam.start', [$plan, $task]) }}" class="mt-4 space-y-4">
                @csrf <input type="hidden" name="start_request_id" value="{{ $examStartRequestId }}">
                <label class="block text-sm font-semibold text-slate-100" for="exam-profile">試験プロファイル</label>
                <select name="exam_profile_key" id="exam-profile" class="form-control w-full" required>
                    @foreach($examProfiles as $profile)
                        <option value="{{ $profile['key'] }}">{{ $profile['exam_code'] }} {{ $profile['subject'] }}（{{ $profile['question_count'] }}問 / {{ $profile['duration_minutes'] }}分）</option>
                    @endforeach
                </select>
                <label class="block text-sm font-semibold text-slate-100" for="exam-pack">検証済み静的問題集</label>
                <select name="question_pack_id" id="exam-pack" class="form-control w-full" required>
                    @foreach($examPacks as $pack)
                        <option value="{{ $pack->id }}">{{ $pack->title }} (v{{ $pack->version }})</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400">選択した試験と問題集の検証済み設定が一致しない場合は開始できません。</p>
                <button type="submit" class="btn-primary">本番形式で始める</button>
            </form>
        </section>
    @endif
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
</div>
@endsection
