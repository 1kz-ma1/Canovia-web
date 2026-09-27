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
        $candidateStatusLabels = [
            'pending' => '確認待ち',
            'applied' => '反映済み',
            'dismissed' => '見送り',
        ];
        $fieldLabels = [
            'title' => 'タイトル',
            'description' => '説明',
            'estimated_minutes' => '見積時間',
            'remaining_minutes' => '残り時間',
            'priority' => '優先度',
            'activation_cost' => '着手コスト',
            'next_action_note' => '次のAction',
            'status' => '状態',
            'category' => 'カテゴリ',
            'priority_mode' => '優先度モード',
            'start_date' => '開始日',
            'deadline' => '期限',
            'type' => 'Fact種別',
            'key' => 'Fact Key',
            'label' => 'ラベル',
            'value' => '内容',
            'measurement' => 'Measurement',
            'importance' => '重要度',
            'kind' => '種類',
            'content' => '本文',
            'use_for_ai' => 'AI Contextで使う',
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
                                @php
                                    $preview = $candidatePreviews[$candidate->id] ?? ['changes' => [], 'blocked_fields' => [], 'target_label' => 'Canovia'];
                                    $previewChanges = $preview['changes'] ?? [];
                                    $blockedFields = $preview['blocked_fields'] ?? [];
                                @endphp
                                <div class="rounded-xl border {{ $candidate->status === 'pending' ? 'border-amber-300/15 bg-amber-300/[0.025]' : ($candidate->status === 'applied' ? 'border-emerald-300/15 bg-emerald-300/[0.025]' : 'border-slate-800 bg-slate-950/20') }} p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <span class="badge {{ $candidate->status === 'applied' ? 'badge-green' : 'badge-slate' }}">{{ $candidateStatusLabels[$candidate->status] ?? $candidate->status }}</span>
                                            <p class="mt-2 text-sm font-bold text-slate-200">{{ $candidate->title }}</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ $candidate->summary }}</p>
                                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] font-bold text-slate-600">
                                                <span>{{ $candidateLabels[$candidate->type] ?? $candidate->type }}</span>
                                                <span>{{ $preview['target_label'] ?? 'Canovia' }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    @if (! empty($previewChanges))
                                        <div class="mt-3 rounded-xl border border-white/8 bg-slate-950/35 p-3">
                                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">反映される内容</p>
                                            <dl class="mt-2 space-y-2">
                                                @foreach ($previewChanges as $key => $value)
                                                    <div class="grid gap-1 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                                        <dt class="text-[11px] font-bold text-slate-500">{{ $fieldLabels[$key] ?? $key }}</dt>
                                                        <dd class="break-words text-xs text-slate-200">
                                                            @if (is_array($value))
                                                                {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                                            @elseif (is_bool($value))
                                                                {{ $value ? 'はい' : 'いいえ' }}
                                                            @elseif ($value === null)
                                                                未設定
                                                            @else
                                                                {{ $value }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    @endif

                                    @if (! empty($blockedFields))
                                        <div class="mt-2 rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">反映しない項目</p>
                                            <p class="mt-1 text-[11px] leading-5 text-slate-500">
                                                {{ collect($blockedFields)->map(fn ($field) => $fieldLabels[$field] ?? $field)->implode(' / ') }}
                                            </p>
                                            <p class="mt-1 text-[10px] leading-4 text-slate-600">進捗・完了などEvidence側で決める項目や、Companionに許可していない項目は無視されます。</p>
                                        </div>
                                    @endif

                                    @if ($candidate->status === 'pending')
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($canApplyCompanionCandidates && ! empty($previewChanges))
                                                <form method="POST" action="{{ route('companion.candidates.apply', [$thread, $candidate]) }}" data-mutation-once>
                                                    @csrf
                                                    <input type="hidden" name="apply_request_id" value="{{ $candidateApplyRequestIds[$candidate->id] ?? '' }}">
                                                    <button type="submit" class="btn-primary px-3 py-2 text-xs">この内容を反映する</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('companion.candidates.dismiss', [$thread, $candidate]) }}">
                                                @csrf
                                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">見送る</button>
                                            </form>
                                        </div>
                                        <p class="mt-3 text-[10px] leading-4 text-amber-200/70">「反映する」を押すまでCanoviaのデータは変更されません。</p>
                                    @elseif ($candidate->status === 'applied')
                                        <p class="mt-3 text-[10px] leading-4 text-emerald-200/70">
                                            人の確認後に反映済みです。
                                            @if ($candidate->applied_target_type && $candidate->applied_target_id)
                                                {{ $candidate->applied_target_type }} #{{ $candidate->applied_target_id }}
                                            @endif
                                        </p>
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
