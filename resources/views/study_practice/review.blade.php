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
    <section class="page-card space-y-3 p-4" aria-label="同問再回答の履歴">
        <h2 class="text-lg font-bold text-slate-100">同問再回答の履歴</h2>
        <p class="text-xs text-slate-400">採点済みAttemptとは別の再回答記録です。元のスコアや弱点判定へ加算しません。</p>
        @forelse ($retryResults as $result)
            <a class="block text-sky-200 underline" href="{{ route('plans.tasks.study_practice.retry.show', [$plan, $task, $attempt->id, $result->id]) }}">{{ $result->created_at?->format('Y-m-d H:i') }} の再回答記録を開く</a>
        @empty
            <p class="text-sm text-slate-400">再回答記録はまだありません。</p>
        @endforelse
        {{ $retryResults->links() }}
    </section>
</div>
@endsection
