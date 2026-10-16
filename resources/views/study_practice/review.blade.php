@extends('layouts.app')
@section('title', '演習の復習 | Canovia')
@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-attempt-review-workspace>
    <header class="page-card p-5">
        <h1 class="text-2xl font-black text-slate-100">{{ $attempt->exercise_title ?: 'AI演習' }}の復習</h1>
        <p class="mt-2 text-sm text-slate-400">{{ $attempt->created_at?->format('Y-m-d H:i') }} · 記録されたスコア {{ $attempt->score_percent }}%</p>
        <p class="mt-2 text-xs text-slate-400">閲覧によってスコア・Task進捗・学習履歴は変わりません。</p>
        <div class="mt-3 flex flex-wrap gap-3">
            <a class="btn-secondary" href="{{ route('plans.tasks.study_practice.history', [$plan, $task]) }}">復習履歴へ</a>
            <a class="btn-secondary" href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}">演習へ戻る</a>
        </div>
    </header>
    @include('study_practice.partials.attempt-review')
</div>
@endsection
