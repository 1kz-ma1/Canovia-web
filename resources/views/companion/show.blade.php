@extends('layouts.app')

@section('title', 'Companion | Canovia')

@section('content')
    @php
        $candidateLabels = [
            'create_task' => 'Task追加候補',
            'update_task' => 'Task変更候補',
            'update_plan' => 'Plan変更候補',
            'record_goal_fact' => 'Goal Context更新候補',
            'create_future_memo' => '未来メモ候補',
            'create_inbox_item' => 'Inbox候補',
        ];
    @endphp

    <div class="mx-auto max-w-5xl space-y-4 pb-36 md:pb-8">
        <header class="page-card border-violet-300/15 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">CANOVIA COMPANION</p>
                    <h1 class="mt-1 truncate text-xl font-black text-slate-100">{{ $thread->title ?: '新しい会話' }}</h1>
                    <p class="mt-1 text-xs text-slate-500">
                        @if ($thread->task)
                            {{ $thread->plan?->title }} / {{ $thread->task->title }}
                        @elseif ($thread->plan)
                            {{ $thread->plan->title }}
                        @else
                            Canovia全体
                        @endif
                    </p>
                </div>
                <a href="{{ route('companion.index') }}" class="btn-secondary px-3 py-2 text-xs">会話一覧</a>
            </div>
        </header>

        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">送信内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <details class="page-card p-4">
            <summary class="cursor-pointer text-xs font-bold text-slate-300">この会話でCanoviaが参照しているContext</summary>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">SCOPE</p>
                    <p class="mt-1 text-xs font-bold text-slate-200">{{ strtoupper($contextSnapshot['scope'] ?? 'global') }}</p>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">GOAL</p>
                    <p class="mt-1 line-clamp-2 text-xs text-slate-300">{{ data_get($contextSnapshot, 'goal_context.desired_state', '未選択') }}</p>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">EVIDENCE</p>
                    <p class="mt-1 text-xs font-bold text-slate-200">{{ count($contextSnapshot['recent_evidence'] ?? []) }}件参照</p>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">INBOX</p>
                    <p class="mt-1 text-xs font-bold text-slate-200">未整理 {{ data_get($contextSnapshot, 'inbox.pending_count', 0) }}件</p>
                </div>
            </div>
        </details>

        <section class="space-y-3" data-companion-messages>
            @forelse ($thread->messages as $message)
                <article class="{{ $message->role === 'user' ? 'ml-auto max-w-3xl border-cyan-300/15 bg-cyan-300/[0.045]' : 'mr-auto max-w-4xl border-violet-300/15 bg-violet-300/[0.035]' }} rounded-2xl border p-4 sm:p-5">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] {{ $message->role === 'user' ? 'text-cyan-300' : 'text-violet-300' }}">
                        {{ $message->role === 'user' ? 'YOU' : 'COMPANION' }}
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-200">{{ $message->content }}</p>

                    @if ($message->role === 'assistant' && $message->mutationCandidates->isNotEmpty())
                        <div class="mt-4 space-y-2 border-t border-white/8 pt-4">
                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-amber-300">MUTATION CANDIDATES · 未反映</p>
                            @foreach ($message->mutationCandidates as $candidate)
                                <div class="rounded-xl border {{ $candidate->status === 'pending' ? 'border-amber-300/15 bg-amber-300/[0.025]' : 'border-slate-800 bg-slate-950/20' }} p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <span class="badge {{ $candidate->status === 'pending' ? 'badge-slate' : 'badge-slate' }}">{{ $candidate->status === 'pending' ? '未反映' : '見送り' }}</span>
                                            <p class="mt-2 text-sm font-bold text-slate-200">{{ $candidate->title }}</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ $candidate->summary }}</p>
                                            <p class="mt-2 text-[10px] font-bold text-slate-600">{{ $candidateLabels[$candidate->type] ?? $candidate->type }}</p>
                                        </div>
                                        @if ($candidate->status === 'pending')
                                            <form method="POST" action="{{ route('companion.candidates.dismiss', [$thread, $candidate]) }}">
                                                @csrf
                                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">見送る</button>
                                            </form>
                                        @endif
                                    </div>
                                    @if (! empty($candidate->payload))
                                        <details class="mt-3">
                                            <summary class="cursor-pointer text-[11px] font-semibold text-slate-500">変更内容を見る</summary>
                                            <pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded-lg bg-slate-950/50 p-3 text-[10px] leading-5 text-slate-400">{{ json_encode($candidate->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
                                        </details>
                                    @endif
                                    @if ($candidate->status === 'pending')
                                        <p class="mt-3 text-[10px] leading-4 text-amber-200/70">Step 1では候補を保存するだけで、Canoviaのデータは変更されません。</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <div class="page-card p-6 text-center">
                    <p class="text-sm font-bold text-slate-200">ここから話しかけて大丈夫です。</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">「今の状況どう見える？」「次何を優先すべき？」「この結果をPlanに反映したい」など、選択中Contextを前提に相談できます。</p>
                </div>
            @endforelse
        </section>

        @if ($canUseCompanion)
            <section class="sticky bottom-20 z-30 rounded-2xl border border-violet-300/15 bg-slate-950/95 p-3 shadow-[0_-18px_50px_rgba(2,6,23,.45)] backdrop-blur-xl md:bottom-4">
                <form method="POST" action="{{ route('companion.messages.store', $thread) }}" class="flex items-end gap-2" data-mutation-once>
                    @csrf
                    <input type="hidden" name="request_id" value="{{ old('request_id', $messageRequestId) }}">
                    <input type="hidden" name="source_path" value="{{ request()->path() }}">
                    <textarea name="content" rows="2" required maxlength="6000" class="form-control min-h-[3.2rem] flex-1 resize-y" placeholder="Canoviaに相談する…">{{ old('content') }}</textarea>
                    <button type="submit" class="btn-primary shrink-0">送信</button>
                </form>
                <p class="mt-2 px-1 text-[10px] text-slate-600">変更が必要でもAIは直接反映せず、まずCandidateとして提示します。</p>
            </section>
        @else
            <section class="page-card border-violet-300/15 p-4">
                <p class="text-xs font-bold text-slate-200">この会話は閲覧できますが、新しいNative会話の送信にはPremium Coreが必要です。</p>
            </section>
        @endif
    </div>
@endsection
