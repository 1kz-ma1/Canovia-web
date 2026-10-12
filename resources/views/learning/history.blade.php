@extends('layouts.app')
@section('title', '学習履歴・復習のヒント | Canovia')
@section('content')
<div class="mx-auto max-w-3xl space-y-5" data-learning-history>
    <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="inline-flex items-center text-sm font-semibold text-sky-400">← 1問ずつ学習へ戻る</a>

    <header class="page-card p-5 sm:p-7">
        <p class="text-xs font-bold tracking-widest text-cyan-400">LEARNING REVIEW</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-50">学習履歴と復習のヒント</h1>
        <p class="mt-2 text-sm leading-6 text-slate-300">{{ $plan->title }} / {{ $task->title }}</p>
        <p class="mt-3 text-xs leading-6 text-slate-400">理解・演習モードの記録済み回答だけを表示します。模擬試験・旧AI演習の成績とは混ぜず、合格可能性や理解度を推定しません。</p>
        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="記録の要約">
            <div class="rounded-xl border border-slate-700 p-3">
                <p class="text-xs text-slate-400">回答記録</p>
                <p class="mt-1 text-xl font-bold text-slate-50">{{ $history['saved_count'] }}<span class="ml-1 text-xs font-normal">件</span></p>
            </div>
            <div class="rounded-xl border border-slate-700 p-3">
                <p class="text-xs text-slate-400">記録上の正解</p>
                <p class="mt-1 text-xl font-bold text-slate-50">{{ $history['correct_count'] }}<span class="ml-1 text-xs font-normal">件</span></p>
            </div>
            <div class="col-span-2 rounded-xl border border-slate-700 p-3 sm:col-span-1">
                <p class="text-xs text-slate-400">評価から除外した回答</p>
                <p class="mt-1 text-xl font-bold text-slate-50">{{ $history['excluded_count'] }}<span class="ml-1 text-xs font-normal">件</span></p>
            </div>
        </div>
        <p class="mt-3 text-xs leading-5 text-slate-400">上記の件数は最新{{ $history['window'] }}件以内。誤タップ申告は採点記録を消さず、復習候補の集計から除外しています。</p>
    </header>

    <section class="page-card p-5 sm:p-7" data-learning-review-topics>
        <h2 class="text-lg font-bold text-slate-50">見直してみる分野</h2>
        <p class="mt-2 text-xs leading-6 text-slate-400">異なる問題で2回以上の不正解があり、直近2回連続正解でない分野のみ表示。復習の候補であり、「苦手」と断定するものではありません。</p>
        @if($history['review_topics']->isEmpty())
            <p class="mt-4 rounded-xl border border-slate-700 p-4 text-sm text-slate-300" data-learning-review-empty>現時点では、繰り返しの誤答に基づく復習候補はありません。回答数が少ない場合もここには表示されません。</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach($history['review_topics'] as $topic)
                    <li class="rounded-xl border border-slate-600 p-4" data-learning-review-topic>
                        <p class="font-semibold text-slate-100">{{ $topic['name'] }}</p>
                        <p class="mt-1 text-sm text-slate-300">直近の対象回答 {{ $topic['answered'] }}件 / 不正解 {{ $topic['incorrect'] }}件</p>
                        <p class="mt-1 text-xs text-slate-400">解説や回答時の考え方を、下の履歴から確認してみましょう。</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="page-card p-5 sm:p-7" data-learning-recent-answers>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-bold text-slate-50">最近の回答</h2>
            <span class="text-xs text-slate-400">新しい順・最大15件</span>
        </div>
        @if($history['recent']->isEmpty())
            <p class="mt-4 text-sm text-slate-300" data-learning-history-empty>このタスクには、まだ新方式の回答履歴がありません。1問解くとここに記録されます。</p>
        @else
            <ol class="mt-4 space-y-3">
                @foreach($history['recent'] as $entry)
                    <li class="rounded-xl border border-slate-700 p-4" data-learning-history-entry>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                            <span>{{ $entry['mode'] === 'understanding' ? '理解' : '演習' }} · {{ $entry['pack_title'] }} · 第{{ $entry['ordinal'] }}問</span>
                            @if($entry['answered_at'])<time datetime="{{ $entry['answered_at']->toIso8601String() }}">{{ $entry['answered_at']->format('Y/m/d H:i') }}</time>@endif
                        </div>
                        <p class="mt-2 whitespace-pre-line break-words text-sm font-semibold leading-6 text-slate-100">{{ $entry['prompt'] }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold {{ $entry['was_correct'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ $entry['was_correct'] ? '正解' : '不正解' }}</span>
                            @if($entry['excluded'])<span class="rounded-full border border-slate-600 px-2 py-0.5 text-xs text-slate-300">復習集計から除外</span>@endif
                            @foreach($entry['concepts'] as $concept)<span class="text-xs text-slate-400">{{ $concept }}</span>@endforeach
                        </div>
                        <details class="mt-3 rounded-lg border border-slate-700 px-3 py-2" data-learning-history-detail>
                            <summary class="cursor-pointer text-sm font-semibold text-sky-300">回答・考え方・解説を見る</summary>
                            <p class="mt-3 break-words text-sm leading-6 text-slate-200">自分の回答：{{ $entry['answer'] }}</p>
                            @if($entry['reasoning'] !== '')
                                <p class="mt-2 text-xs font-semibold text-slate-400">回答時の考え方</p>
                                <p class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-200">{{ $entry['reasoning'] }}</p>
                            @endif
                            <p class="mt-3 text-xs font-semibold text-slate-400">問題集の解説</p>
                            <p class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-300">{{ $entry['explanation'] ?: '解説は登録されていません。' }}</p>
                        </details>
                    </li>
                @endforeach
            </ol>
        @endif
        <a href="{{ route('plans.tasks.learning.index', [$plan, $task]) }}" class="btn-primary mt-5 inline-flex">1問ずつ学習を再開する</a>
    </section>
</div>
@endsection
