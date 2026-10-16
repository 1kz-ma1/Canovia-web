@extends('layouts.app')
@section('title', '同じ問題への再回答 | Canovia')
@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-study-retry-form>
    <header class="page-card p-5 space-y-3">
        <h1 class="text-2xl font-black text-slate-100">同じ問題への再回答</h1>
        <p class="text-sm leading-6 text-slate-300">元の誤答・一部正解への振り返り練習です。同分野の別問題による理解確認とは異なります。前の回答は入力していません。</p>
        <p class="text-sm leading-6 text-amber-200">独立した再回答記録として保存し、元のAttempt・スコア・Task進捗は変更しません。正答根拠のない問題の採点は保留します。解説を見た直後の正解だけで、弱点解消や完了とは判定しません。</p>
        <a class="btn-secondary" href="{{ route('plans.tasks.study_practice.review', [$plan, $task, $source->id]) }}">元の復習へ戻る</a>
    </header>
    @error('answers')<p role="alert" class="text-rose-200">{{ $message }}</p>@enderror
    @if ($cards === [])
        <p class="page-card p-5 text-slate-300">安全に再回答できる誤答・一部正解の記録がありません。IDや回答形式が不明な場合は内容を推測して開始しません。</p>
    @else
        <form method="POST" action="{{ route('plans.tasks.study_practice.retry.store', [$plan, $task, $source->id]) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="retry_request_id" value="{{ old('retry_request_id', $retryRequestId) }}">
            @foreach ($cards as $card)
                <fieldset class="page-card space-y-4 p-4" data-retry-question="{{ $card['id'] }}">
                    <legend class="px-2 font-bold text-slate-100">元の第{{ $card['ordinal'] }}問</legend>
                    <p class="whitespace-pre-wrap leading-7 text-slate-200">{{ $card['prompt'] }}</p>
                    @foreach ($card['fields'] as $field)
                        @php($name = 'answers['.$card['id'].']['.$field['id'].']')
                        @php($value = old('answers.'.$card['id'].'.'.$field['id']))
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-bold text-slate-300">{{ $field['label'] }} · {{ $field['required'] ? '必須' : '任意' }}</legend>
                            @if (in_array($field['type'], ['single_choice', 'multiple_choice']))
                                @foreach ($field['choices'] as $choice)
                                    <label class="flex gap-3 rounded-xl border border-slate-700 p-3 text-sm text-slate-200">
                                        <input type="{{ $field['type'] === 'single_choice' ? 'radio' : 'checkbox' }}" name="{{ $name }}{{ $field['type'] === 'multiple_choice' ? '[]' : '' }}" value="{{ $choice['id'] }}"
                                            @checked(is_array($value) ? in_array($choice['id'], $value, true) : $value === $choice['id']) @required($field['type'] === 'single_choice' && $field['required'])>
                                        <span>{{ $choice['id'] }}：{{ $choice['label'] }}</span>
                                    </label>
                                @endforeach
                            @elseif ($field['type'] === 'textarea')
                                <textarea class="form-control min-h-28" name="{{ $name }}" aria-label="{{ $field['label'] }}" maxlength="12000" @required($field['required'])>{{ is_string($value) ? $value : '' }}</textarea>
                            @else
                                <input class="form-control" type="{{ $field['type'] === 'number' ? 'number' : 'text' }}" step="any" name="{{ $name }}" aria-label="{{ $field['label'] }}" maxlength="3000" value="{{ is_string($value) ? $value : '' }}" @required($field['required'])>
                            @endif
                        </fieldset>
                    @endforeach
                </fieldset>
            @endforeach
            <button class="btn-primary" type="submit">独立した再回答記録を保存する</button>
        </form>
    @endif
</div>
@endsection
