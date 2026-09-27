@extends('layouts.app')

@section('title', 'Companion | Canovia')

@section('content')
    <div class="mx-auto max-w-5xl space-y-5 pb-28 md:pb-0">
        <header class="relative overflow-hidden rounded-[1.65rem] border border-violet-300/15 bg-[radial-gradient(circle_at_82%_15%,rgba(139,92,246,.14),transparent_30%),radial-gradient(circle_at_10%_100%,rgba(34,211,238,.10),transparent_34%),rgba(6,13,31,.82)] p-5 sm:p-7">
            <div class="max-w-3xl">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">CANOVIA COMPANION</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">Canoviaが知っていることを、会話につなぐ</h1>
                <p class="mt-3 text-sm leading-7 text-slate-400">
                    汎用チャットではなく、Goal Context・Plan・Task・Evidenceを読みながら一緒に整理します。
                    変更が必要な場合もAIが直接書き換えず、まず変更候補として提示します。
                </p>
            </div>
        </header>

        @if (! $companionPublished || ! $companionConfigured)
            <section class="page-card border-slate-700 p-5 sm:p-6">
                <span class="badge badge-slate">準備中</span>
                <h2 class="mt-3 text-lg font-black text-slate-100">Companionは現在公開準備中です</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">既存のHome / Inbox / Plan / Guided Executionはこれまで通り利用できます。</p>
            </section>
        @elseif (! $companionEntitled)
            <section class="page-card border-violet-300/15 p-5 sm:p-6">
                <span class="badge badge-slate">Premium Core</span>
                <h2 class="mt-3 text-lg font-black text-slate-100">常時伴走はPremiumの摩擦軽減機能です</h2>
                <p class="mt-2 max-w-2xl text-xs leading-5 text-slate-400">
                    FreeでもGoal Discovery、Plan、Inbox、Guided Executionなどを使って目標へ進めます。
                    Companionは、それらの文脈整理・会話・変更候補作成をCanoviaがまとめて引き受ける機能です。
                </p>
            </section>
        @endif

        @if ($canUseCompanion)
            <section class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">NEW CONVERSATION</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">何について一緒に考える？</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">対象を選ぶと、そのPlan / TaskのGoal ContextとRecent Evidenceを会話へ渡します。</p>

                <form method="POST" action="{{ route('companion.threads.store') }}" class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end" data-mutation-once>
                    @csrf
                    <label class="flex-1 text-xs font-bold text-slate-400">
                        Context
                        <select name="scope" class="form-control mt-2">
                            <option value="global">全体を見ながら相談</option>
                            @foreach ($plans as $plan)
                                <option value="plan:{{ $plan->id }}">Plan · {{ $plan->title }}</option>
                                @foreach ($plan->tasks->whereNotIn('status', ['done', 'cancelled'])->take(12) as $task)
                                    <option value="task:{{ $task->id }}">　Task · {{ $task->title }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn-primary">Companionを開く</button>
                </form>
            </section>
        @endif

        <section class="page-card overflow-hidden">
            <div class="border-b border-slate-800 p-5">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-slate-500">CONVERSATIONS</p>
                <h2 class="mt-1 text-lg font-black text-slate-100">最近の会話</h2>
            </div>
            <div class="divide-y divide-slate-800/80">
                @forelse ($threads as $thread)
                    <a href="{{ route('companion.show', $thread) }}" class="block p-4 transition hover:bg-white/[0.025] sm:p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-200">{{ $thread->title ?: '新しい会話' }}</p>
                                <p class="mt-1 truncate text-xs text-slate-500">
                                    @if ($thread->task)
                                        {{ $thread->plan?->title }} / {{ $thread->task->title }}
                                    @elseif ($thread->plan)
                                        {{ $thread->plan->title }}
                                    @else
                                        Canovia全体
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 text-[11px] text-slate-600">{{ $thread->last_message_at?->format('m/d H:i') ?: $thread->created_at?->format('m/d H:i') }}</span>
                        </div>
                    </a>
                @empty
                    <div class="p-6 text-sm text-slate-500">まだCompanionとの会話はありません。</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
