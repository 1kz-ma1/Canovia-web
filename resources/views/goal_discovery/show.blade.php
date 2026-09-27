@extends('layouts.app')

@section('title', 'Canoviaと話す | Canovia')

@section('content')
    @php
        $planReady = $goalContext->readiness_score >= 40 || ! $question || $presentationMode === 'compact';
        $textFirst = $presentationMode === 'conversational' || empty($question['options'] ?? []);
        $confirmedFacts = collect(data_get($snapshot, 'confirmed_facts', []))
            ->reject(fn ($fact) => ($fact->key ?? null) === 'goal_title')
            ->values();
        $unknownFacts = collect(data_get($snapshot, 'known_unknowns', []))->values();
    @endphp

    <div class="mx-auto max-w-4xl space-y-4 pb-32 md:pb-8">
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">回答を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <header class="page-card border-violet-300/15 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">FIRST COMPANION</p>
                    <h1 class="mt-1 text-xl font-black text-slate-100">{{ $goalContext->desired_state }}</h1>
                    <p class="mt-1 text-xs text-slate-500">フォームを埋めるのではなく、会話からCanoviaが現在地を組み立てています。</p>
                </div>
                <span class="badge {{ $goalContext->readiness_state === 'high' ? 'badge-green' : ($goalContext->readiness_state === 'medium' ? 'badge-amber' : 'badge-slate') }}">
                    現在地の把握 {{ $goalContext->readiness_score }}%
                </span>
            </div>
        </header>

        <section class="space-y-3" data-goal-discovery-conversation>
            @foreach (($messages ?? collect()) as $message)
                <article class="{{ $message->role === 'user' ? 'ml-auto max-w-3xl border-cyan-300/15 bg-cyan-300/[0.045]' : 'mr-auto max-w-3xl border-violet-300/15 bg-violet-300/[0.04]' }} rounded-2xl border p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] {{ $message->role === 'user' ? 'text-cyan-300' : 'text-violet-300' }}">
                        {{ $message->role === 'user' ? 'YOU' : 'CANOVIA' }}
                    </p>
                    <p class="mt-2 whitespace-pre-wrap text-sm leading-7 text-slate-200">{{ $message->content }}</p>
                </article>
            @endforeach
        </section>

        @if ($question)
            <section class="page-card border-cyan-300/15 p-4 sm:p-5" data-goal-discovery-question>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">YOUR TURN</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">{{ $question['title'] }}</h2>
                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $question['copy'] }}</p>
                    </div>
                    <span class="badge badge-slate">必要なことだけ</span>
                </div>

                @if ($textFirst)
                    <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" class="mt-4" data-mutation-once>
                        @csrf
                        <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                        <input type="hidden" name="answer_mode" value="text">
                        <textarea name="answer_text" rows="3" maxlength="2000" class="form-control min-h-[7rem]" placeholder="{{ $question['placeholder'] }}">{{ old('answer_text') }}</textarea>
                        <button type="submit" class="btn-primary mt-3">これで更新</button>
                    </form>
                @endif

                @if (! empty($question['options']))
                    <div class="{{ $textFirst ? 'mt-5 border-t border-white/6 pt-4' : 'mt-4' }}">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-slate-600">{{ $textFirst ? 'タップで答えるなら' : '近いものをタップ' }}</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($question['options'] as $option)
                                <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" data-mutation-once>
                                    @csrf
                                    <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                                    <input type="hidden" name="answer_mode" value="quick">
                                    <input type="hidden" name="choice" value="{{ $option['value'] }}">
                                    <button type="submit" class="btn-secondary w-full px-4 py-3 text-left text-xs">{{ $option['label'] }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! $textFirst)
                    <details class="mt-4 rounded-xl border border-slate-800 bg-slate-950/25 p-3">
                        <summary class="cursor-pointer text-xs font-bold text-slate-300">文章で説明する</summary>
                        <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" class="mt-3" data-mutation-once>
                            @csrf
                            <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                            <input type="hidden" name="answer_mode" value="text">
                            <textarea name="answer_text" rows="3" maxlength="2000" class="form-control min-h-[7rem]" placeholder="{{ $question['placeholder'] }}">{{ old('answer_text') }}</textarea>
                            <button type="submit" class="btn-primary mt-3">送る</button>
                        </form>
                    </details>
                @endif

                <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" class="mt-4" data-mutation-once>
                    @csrf
                    <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                    <input type="hidden" name="answer_mode" value="skip">
                    <button type="submit" class="text-xs font-semibold text-slate-500 underline decoration-slate-700 underline-offset-4">今は分からない / 飛ばす</button>
                </form>
            </section>
        @endif

        @if ($planReady)
            <section class="page-card border-emerald-300/15 p-5">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">ENOUGH TO START</p>
                <h2 class="mt-1 text-lg font-black text-slate-100">{{ $presentationMode === 'compact' ? '質問は一旦ここまででも大丈夫' : '最初の仮Planを作れる状態です' }}</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">全部を確定する必要はありません。分からない部分は実行とEvidenceから後で更新できます。</p>
                <form method="POST" action="{{ route('plans.store') }}" class="mt-4" data-mutation-once>
                    @csrf
                    <input type="hidden" name="goal_context_id" value="{{ $goalContext->id }}">
                    <input type="hidden" name="title" value="{{ $goalContext->desired_state }}">
                    <input type="hidden" name="create_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button type="submit" class="btn-primary">{{ $presentationMode === 'compact' ? 'この情報で仮Planへ進む' : 'この会話から仮Planを作る' }}</button>
                </form>
            </section>
        @endif

        <details class="page-card p-4">
            <summary class="cursor-pointer text-xs font-bold text-slate-400">Canoviaが今理解していること</summary>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-emerald-300">KNOWN</p>
                    @forelse ($confirmedFacts->take(5) as $fact)
                        <p class="mt-2 text-xs leading-5 text-slate-300">{{ $fact->label }}</p>
                    @empty
                        <p class="mt-2 text-xs text-slate-500">目標以外はまだ確認中です。</p>
                    @endforelse
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-amber-300">KNOWN UNKNOWN</p>
                    @forelse ($unknownFacts->take(5) as $fact)
                        <p class="mt-2 text-xs leading-5 text-slate-300">? {{ $fact->label }}</p>
                    @empty
                        <p class="mt-2 text-xs text-slate-500">今のところ明示されたUnknownはありません。</p>
                    @endforelse
                </div>
            </div>
        </details>

        <div class="flex flex-wrap justify-between gap-2 px-1">
            <a href="{{ route('plans.create') }}" class="text-xs font-semibold text-slate-600">← 別の目標で話す</a>
            <a href="{{ route('plans.create.manual') }}" class="text-xs font-semibold text-slate-600">詳細フォームで作る</a>
        </div>
    </div>
@endsection
