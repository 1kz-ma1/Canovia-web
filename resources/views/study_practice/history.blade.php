@extends('layouts.app')
@section('title', '演習の復習履歴 | Canovia')
@section('content')
<div class="mx-auto max-w-4xl space-y-4">
    <h1 class="text-2xl font-black text-slate-100">演習の復習履歴</h1>
    <p class="text-sm text-slate-400">本人の記録だけを表示します。演習終了後も保存された問題と回答を振り返れます。</p>
    <a class="btn-secondary" href="{{ route('plans.tasks.study_practice.show', [$plan, $task]) }}">演習へ戻る</a>
    @forelse ($attempts as $attempt)
        <article class="page-card p-4">
            <h2 class="font-bold text-slate-100">{{ $attempt->exercise_title ?: 'AI演習' }}</h2>
            <p class="mt-2 text-sm text-slate-400">{{ $attempt->created_at?->format('Y-m-d H:i') }} · score {{ $attempt->score_percent }}%</p>
            <a class="btn-primary mt-3" href="{{ route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]) }}">問題と回答を復習する</a>
        </article>
    @empty
        <p class="page-card p-4 text-slate-400">保存された演習結果はありません。</p>
    @endforelse
    {{ $attempts->links() }}
</div>
@endsection
