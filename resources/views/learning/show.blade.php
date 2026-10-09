@extends('layouts.app')
@section('title', '1問ずつ学習 | Canovia')
@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-adaptive-learning-run="{{ $run->id }}">
    <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="text-sm font-semibold text-sky-400">← 学習モードと問題集</a>
    <section class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-cyan-400">QUESTION BANK / ONE QUESTION AT A TIME</p>
        <h1 class="mt-2 text-xl font-bold text-slate-50">{{ $run->pack_title_snapshot }}</h1>
        <p class="mt-2 text-sm text-slate-300">{{ $run->mode === 'understanding' ? '理解モード' : '演習モード' }} · 回答済 {{ $answeredCount }}問 · 正答 {{ $correctCount }}問</p>
        <p class="mt-2 text-xs leading-5 text-slate-400">ここでの正答数は記録上の採点結果です。理解度・合格確率を断定しません。Task進捗も自動では変更しません。直近の問題は確定済み、その先の候補だけ回答履歴に基づいて再検討します。</p>
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
            $responseType = (string) data_get($q, 'response_field.type', 'single_choice');
            $correctChoiceId = (string) ($rule['answer'] ?? '');
            $correctLabel = collect(is_array($choices) ? $choices : [])->firstWhere('id', $correctChoiceId)['label'] ?? $correctChoiceId;
            $correctMultiple = is_array($rule['answers'] ?? null) ? $rule['answers'] : [];
            $correctNumeric = $rule['answer'] ?? null;
            $numericTolerance = $rule['tolerance'] ?? 0;
            $savedTypedAnswer = $answer?->answer_payload;
            $displayedAnswer = $savedTypedAnswer['value'] ?? $answer?->answer_value;
            $displayedAnswerText = is_array($displayedAnswer)
                ? implode(' / ', $displayedAnswer) : (string) $displayedAnswer;
        @endphp
        <section class="page-card p-5 sm:p-7" data-learning-question="{{ $item->ordinal }}">
            <p class="text-xs font-bold text-slate-400">第{{ $item->ordinal }}問 · {{ $q['source_type'] ?? 'question_bank' }}{{ filled($q['source_reference'] ?? null) ? ' / '.$q['source_reference'] : '' }}</p>
            <h2 class="mt-3 whitespace-pre-line text-lg font-semibold leading-8 text-slate-50">{{ $q['prompt'] ?? '' }}</h2>
            @if(! $answer)
                <form method="POST" action="{{ route('plans.tasks.learning.answer', [$plan, $task, $run]) }}" class="mt-5 space-y-3" data-learning-answer-form>
                    @csrf
                    <input type="hidden" name="request_id" value="{{ $answerRequestId }}">
                    <input type="hidden" name="learning_run_item_id" value="{{ $item->id }}">
                    @if ($responseType === 'number')
                        <label for="learning-number-{{ $item->id }}" class="block text-sm font-semibold text-slate-200">数値で回答してください</label>
                        <input id="learning-number-{{ $item->id }}" type="text" name="number"
                            inputmode="decimal" autocomplete="off" required maxlength="64"
                            value="{{ old('number') }}" class="form-control w-full"
                            placeholder="例：42 または 10.5" data-learning-number-answer>
                        <p class="text-xs text-slate-400">半角数字で入力してください。許容誤差は採点ルールに従います。</p>
                    @else
                        <fieldset class="space-y-3">
                            <legend class="text-sm font-semibold text-slate-200">
                                {{ $responseType === 'multiple_choice' ? '正しいと思うものをすべて選んでください' : '回答を選んでください' }}
                            </legend>
                            @foreach($choices as $choice)
                                <label class="flex items-start gap-3 rounded-xl border border-slate-600 px-4 py-3 text-sm text-slate-200">
                                    @if ($responseType === 'multiple_choice')
                                        <input type="checkbox" name="choices[]" value="{{ $choice['id'] }}"
                                            @checked(in_array((string) $choice['id'], (array) old('choices', []), true))>
                                    @else
                                        <input type="radio" name="choice" required value="{{ $choice['id'] }}" @checked(old('choice') == $choice['id'])>
                                    @endif
                                    <span>{{ $choice['id'] }} · {{ $choice['label'] ?? $choice['id'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endif
                    @if ($run->mode === 'understanding')
                        <div class="space-y-2">
                            <label for="learning-reasoning-{{ $item->id }}" class="block text-sm font-semibold text-slate-200">考え方・選んだ理由（任意）</label>
                            <textarea id="learning-reasoning-{{ $item->id }}" name="reasoning"
                                rows="3" maxlength="1000" class="form-control w-full"
                                placeholder="例：この公式を使うと考えた理由など" data-learning-reasoning>{{ old('reasoning') }}</textarea>
                            <p class="text-xs text-slate-400">回答と一緒に記録します。メモの内容は採点・理解度評価には使いません。回答後は編集できません。</p>
                        </div>
                    @endif
                    @if ($errors->any())<p role="alert" class="text-sm text-red-300">{{ $errors->first() }}</p>@endif
                    <button type="submit" class="btn-primary">この1問を採点・保存</button>
                </form>
            @else
                <div class="mt-5 rounded-xl border border-sky-500/30 p-4" data-learning-answer-feedback>
                    <p class="text-sm font-bold {{ $answer->was_correct ? 'text-emerald-300' : 'text-amber-300' }}">{{ $answer->was_correct ? '正解' : '不正解' }}</p>
                    <p class="mt-2 text-sm text-slate-200">あなたの回答：{{ $displayedAnswerText }}</p>
                    @if (filled($savedTypedAnswer['reasoning'] ?? null))
                        <div class="mt-3 rounded-lg border border-slate-600 p-3" data-learning-saved-reasoning>
                            <p class="text-xs font-semibold text-slate-300">回答時に残した考え方</p>
                            <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-200">{{ $savedTypedAnswer['reasoning'] }}</p>
                        </div>
                    @endif
                    @if ($responseType === 'multiple_choice')
                        <p class="mt-2 text-sm text-slate-200">正答：{{ implode(' / ', $correctMultiple) }}</p>
                    @elseif ($responseType === 'number')
                        <p class="mt-2 text-sm text-slate-200">正答：{{ $correctNumeric }}（許容誤差 ±{{ $numericTolerance }}）</p>
                    @else
                        <p class="mt-2 text-sm text-slate-200">正答：{{ $correctChoiceId }} · {{ $correctLabel }}</p>
                    @endif
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
                @if(! $answer->evaluationAdjustment)
                    <form method="POST" action="{{ route('plans.tasks.learning.evaluation_adjustments.store', [$plan, $task, $answer]) }}" class="mt-3">
                        @csrf <input type="hidden" name="reason" value="accidental_tap">
                        <button type="submit" class="btn-secondary">誤タップとして評価への影響を除外</button>
                    </form>
                @else
                    <p class="mt-3 text-xs text-slate-400">誤タップ申告済み：正誤は保存し、学習推薦には使用しません。</p>
                @endif
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
