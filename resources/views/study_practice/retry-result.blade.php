@extends('layouts.app')
@section('title', '同問再回答の記録 | Canovia')
@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-study-retry-result>
    <header class="page-card space-y-3 p-5">
        <h1 class="text-2xl font-black text-slate-100">同問再回答の記録</h1>
        <p class="text-sm text-slate-300">{{ $retry->created_at?->format('Y-m-d H:i') }} · 元の演習とは独立した振り返り練習です。</p>
        <p class="text-sm text-slate-300">保存済み根拠との照合：{{ data_get($retry->assessment_payload, 'assessment.correct_count', 0) }}/{{ data_get($retry->assessment_payload, 'assessment.graded_count', 0) }}問。正誤保留：{{ data_get($retry->assessment_payload, 'assessment.ungraded_count', 0) }}問。</p>
        <p class="text-sm text-amber-200">別問題で理解できた証拠ではありません。元のスコア・Task進捗・弱点判定は変更していません。</p>
        <a class="btn-secondary" href="{{ route('plans.tasks.study_practice.review', [$plan, $task, $source->id]) }}">元の復習・再回答履歴へ</a>
    </header>
    @include('study_practice.partials.attempt-review')
</div>
@endsection
