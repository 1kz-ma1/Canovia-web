@extends('layouts.app')
@section('title', '1問ずつ学習 | Canovia')
@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-adaptive-learning-run="{{ $run->id }}">
    <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="text-sm font-semibold text-sky-400">← 学習モードと問題集</a>
    <section class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-cyan-400">QUESTION BANK / ONE QUESTION AT A TIME</p>
        <h1 class="mt-2 text-xl font-bold text-slate-50">{{ $run->pack_title_snapshot }}</h1>
        <p class="mt-2 text-sm text-slate-300">{{ $run->mode === 'understanding' ? '理解モード' : '演習モード' }} · 回答済 {{ $answeredCount }}問 · 正答 {{ $correctCount }}問</p>
        <p class="mt-2 text-xs leading-5 text-slate-400">ここでの正答数は記録上の採点結果です。理解度・合格確率を断定しません。Task進捗も自動では変更しません。</p>
    </section>

    @if(session('notice'))<p role="status" class="rounded-lg border border-slate-600 p-3 text-sm text-slate-200">{{ session('notice') }}</p>@endif

    @if($run->status === 'completed')
        <section class="page-card p-5 sm:p-7" data-adaptive-learning-completed>
            <h2 class="text-lg font-bold text-slate-50">学習を終了しました</h2>
            <p class="mt-3 text-sm text-slate-300">{{ $answeredCount }}問回答・{{ $correctCount }}問正解。今回の履歴は保存されています。従来のStudyPracticeAttemptやTask進捗には自動反映していません。</p>
            <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="btn-primary mt-4 inline-flex">新しい学習を始める</a>
        </section>
    @elseif($item)
        @php
            $q = $item->question_snapshot ?? [];
            $choices = data_get($q, 'response_field.choices', []);
            $answer = $item->answer;
            $rule = $item->grading_rule_snapshot ?? [];
            $correctChoiceId = (string) ($rule['answer'] ?? '');
            $correctLabel = collect(is_array($choices) ? $choices : [])->firstWhere('id', $correctChoiceId)['label'] ?? $correctChoiceId;
        @endphp
        <section class="page-card p-5 sm:p-7" data-learning-question="{{ $item->ordinal }}">
            <p class="text-xs font-bold text-slate-400">第{{ $item->ordinal }}問 · {{ $q['source_type'] ?? 'question_bank' }}{{ filled($q['source_reference'] ?? null) ? ' / '.$q['source_reference'] : '' }}</p>
            <h2 class="mt-3 whitespace-pre-line text-lg font-semibold leading-8 text-slate-50">{{ $q['prompt'] ?? '' }}</h2>
            @if(! $answer)
                <form method="POST" action="{{ route('plans.tasks.learning.answer', [$plan, $task, $run]) }}" class="mt-5 space-y-3" data-learning-answer-form>
                    @csrf
                    <input type="hidden" name="request_id" value="{{ $answerRequestId }}">
                    <input type="hidden" name="learning_run_item_id" value="{{ $item->id }}">
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-slate-200">回答を選んでください</legend>
                        @foreach($choices as $choice)
                            <label class="flex items-start gap-3 rounded-xl border border-slate-600 px-4 py-3 text-sm text-slate-200">
                                <input type="radio" name="choice" required value="{{ $choice['id'] }}" @checked(old('choice') == $choice['id'])>
                                <span>{{ $choice['id'] }} · {{ $choice['label'] ?? $choice['id'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @if ($errors->any())<p role="alert" class="text-sm text-red-300">{{ $errors->first() }}</p>@endif
                    <button type="submit" class="btn-primary">この1問を採点・保存</button>
                </form>
            @else
                <div class="mt-5 rounded-xl border border-sky-500/30 p-4" data-learning-answer-feedback>
                    <p class="text-sm font-bold {{ $answer->was_correct ? 'text-emerald-300' : 'text-amber-300' }}">{{ $answer->was_correct ? '正解' : '不正解' }}</p>
                    <p class="mt-2 text-sm text-slate-200">あなたの回答：{{ $answer->answer_value }}</p>
                    <p class="mt-2 text-sm text-slate-200">正答：{{ $correctChoiceId }} · {{ $correctLabel }}</p>
                    @if($run->mode === 'understanding')
                        <h3 class="mt-4 text-sm font-bold text-slate-100">解説</h3>
                        <p class="mt-1 whitespace-pre-line text-sm leading-7 text-slate-300">{{ $item->explanation_snapshot ?: 'この問題には解説が登録されていません。' }}</p>
                    @else
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm font-semibold text-sky-300">解説を読む（任意）</summary>
                            <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-300">{{ $item->explanation_snapshot ?: 'この問題には解説が登録されていません。' }}</p>
                        </details>
                    @endif
                </div>
                <form method="POST" action="{{ route('plans.tasks.learning.next', [$plan, $task, $run]) }}" class="mt-4">
                    @csrf <button type="submit" class="btn-primary">次の問題へ</button>
                </form>
            @endif
        </section>
    @else
        <section class="page-card p-5 sm:p-7">
            <p class="text-sm text-slate-200">次に出題できる問題がありません。この学習を終了できます。</p>
        </section>
    @endif

    @if($run->status === 'active')
        <section class="page-card p-4 sm:p-5">
            <p class="text-xs leading-6 text-slate-300">1問で終了しても回答履歴は保存されます。終了せず別ページへ移動した場合も「前回の続き」から再開できます。</p>
            <form method="POST" action="{{ route('plans.tasks.learning.finish', [$plan, $task, $run]) }}" class="mt-3">
                @csrf <button type="submit" class="btn-secondary">ここで学習を終了する</button>
            </form>
        </section>
    @endif
</div>
@endsection
