@extends('layouts.app')
@section('title', '模擬試験 | Canovia')
@section('content')
@php
    $profile = $run->exam_profile_snapshot ?? [];
    $total = (int) ($profile['question_count'] ?? $items->count());
    $isComplete = $run->status === 'completed';
    $draft = $item->examResponse;
    $remaining = $run->exam_deadline_at ? max(0, (int) now()->diffInSeconds($run->exam_deadline_at, false)) : 0;
    $correctCount = $isComplete ? $items->filter(fn ($it) => $it->answer?->was_correct === true)->count() : null;
    $answeredCount = $items->filter(fn ($it) => $it->examResponse !== null)->count();
    $snapshot = $item->question_snapshot ?? [];
    $choices = data_get($snapshot, 'response_field.choices', []);
@endphp
<div class="mx-auto max-w-3xl space-y-5" data-adaptive-exam="{{ $run->id }}">
    <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="text-sm font-semibold text-sky-400">← 学習モード選択</a>
    <section class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-cyan-400">EXAM SIMULATION / FROZEN QUESTIONS</p>
        <h1 class="mt-2 text-xl font-bold text-slate-50">{{ $run->pack_title_snapshot }}</h1>
        <p class="mt-2 text-sm text-slate-300">{{ $profile['exam_code'] ?? 'Exam' }} {{ $profile['subject'] ?? '' }} / {{ $total }}問 / {{ $profile['duration_minutes'] ?? '-' }}分</p>
        <p class="mt-2 text-xs text-slate-400">試験設定 v{{ $run->exam_profile_version }} · 出典仕様 {{ $profile['source_reference'] ?? '未記載' }}</p>
        @if(! $isComplete)
            <p class="mt-3 text-sm font-semibold {{ $expired ? 'text-amber-300' : 'text-slate-200' }}" data-exam-deadline>
                {{ $expired ? '制限時間が経過しました。提出して採点してください。' : '残り時間：約'.ceil($remaining / 60).'分' }}
                · 終了時刻 {{ $run->exam_deadline_at?->format('Y/m/d H:i') }}
            </p>
            <p class="mt-2 text-xs leading-6 text-slate-400">{{ $answeredCount }}/{{ $total }}問 回答保存済み。試験中は正答・解説を表示しません。ページを閉じても残り時間は止まりません。</p>
        @else
            <p class="mt-3 text-base font-bold text-slate-50" data-exam-score>採点結果：{{ $correctCount }}/{{ $total }}問正解（{{ $total ? (int) round(100 * $correctCount / $total) : 0 }}%）</p>
            <p class="mt-2 text-xs text-slate-400">採点は試験終了時に一括実施しました。Task進捗や理解度推定は自動変更していません。</p>
        @endif
    </section>

    @if(! $isComplete)
        <section class="page-card p-5 sm:p-7" data-exam-question="{{ $item->ordinal }}">
            <p class="text-sm font-semibold text-slate-300">第{{ $item->ordinal }}問 / {{ $total }}問</p>
            <h2 class="mt-3 whitespace-pre-line text-lg font-bold leading-8 text-slate-50">{{ $snapshot['prompt'] ?? '' }}</h2>
            @if(! $expired)
                <form method="POST" action="{{ route('plans.tasks.learning.exam.answer', [$plan, $task, $run]) }}" class="mt-5 space-y-3">
                    @csrf <input type="hidden" name="request_id" value="{{ $responseRequestId }}">
                    <input type="hidden" name="learning_run_item_id" value="{{ $item->id }}">
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-bold text-slate-300">回答を選ぶ（何度でも変更できます）</legend>
                        @foreach($choices as $choice)
                            <label class="flex items-start gap-3 rounded-xl border border-slate-600 p-3 text-sm text-slate-200">
                                <input type="radio" name="choice" value="{{ $choice['id'] }}" required @checked(old('choice', $draft?->answer_value) == $choice['id'])>
                                <span>{{ $choice['id'] }} · {{ $choice['label'] ?? $choice['id'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @if($errors->any())<p class="text-sm text-red-300" role="alert">{{ $errors->first() }}</p>@endif
                    <button class="btn-primary" type="submit">回答を保存して次へ</button>
                </form>
                <div class="mt-5 flex flex-wrap gap-3">
                    @if($item->ordinal > 1)
                        <form method="POST" action="{{ route('plans.tasks.learning.exam.navigate', [$plan, $task, $run]) }}">
                            @csrf <input type="hidden" name="ordinal" value="{{ $item->ordinal - 1 }}">
                            <button class="btn-secondary" type="submit">前の問題へ</button>
                        </form>
                    @endif
                    @if($item->ordinal < $total)
                        <form method="POST" action="{{ route('plans.tasks.learning.exam.navigate', [$plan, $task, $run]) }}">
                            @csrf <input type="hidden" name="ordinal" value="{{ $item->ordinal + 1 }}">
                            <button class="btn-secondary" type="submit">次の問題へ（未回答で進む）</button>
                        </form>
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm text-amber-300">締切後の新しい回答や変更は受け付けません。</p>
            @endif
        </section>
        <section class="page-card p-4 sm:p-5">
            <p class="text-xs leading-6 text-slate-300">途中終了すると、この時点の保存済み回答だけで採点します。未回答は正解扱いしません。終了後の回答変更はできません。</p>
            <form method="POST" action="{{ route('plans.tasks.learning.exam.finish', [$plan, $task, $run]) }}" class="mt-3">
                @csrf <button class="btn-primary" type="submit">試験を終了してまとめて採点</button>
            </form>
        </section>
    @else
        <section class="page-card p-5 sm:p-7" data-exam-results>
            <h2 class="text-lg font-bold text-slate-50">問題別の結果と解説</h2>
            <ol class="mt-4 space-y-4">
                @foreach($items as $it)
                    @php
                        $result = $it->answer;
                        $rule = $it->grading_rule_snapshot ?? [];
                        $correctId = (string) ($rule['answer'] ?? '');
                        $ch = data_get($it->question_snapshot, 'response_field.choices', []);
                        $correctLabel = collect(is_array($ch) ? $ch : [])->firstWhere('id', $correctId)['label'] ?? $correctId;
                    @endphp
                    <li class="rounded-xl border border-slate-600 p-4 text-sm text-slate-200">
                        <p class="text-xs font-bold text-slate-400">第{{ $it->ordinal }}問 — {{ !$result ? '未回答' : ($result->was_correct ? '正解' : '不正解') }}</p>
                        <p class="mt-2 whitespace-pre-line">{{ data_get($it->question_snapshot, 'prompt', '') }}</p>
                        <p class="mt-2">回答：{{ $result?->answer_value ?: 'なし' }}</p>
                        <p class="mt-1">正答：{{ $correctId }} · {{ $correctLabel }}</p>
                        <details class="mt-3">
                            <summary class="cursor-pointer font-semibold text-sky-300">解説を読む</summary>
                            <p class="mt-2 whitespace-pre-line leading-7">{{ $it->explanation_snapshot ?: '解説は未登録です。' }}</p>
                        </details>
                    </li>
                @endforeach
            </ol>
            <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="btn-secondary mt-4 inline-flex">問題集とモードへ戻る</a>
        </section>
    @endif
</div>
@endsection
