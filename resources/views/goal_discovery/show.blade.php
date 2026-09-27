@extends('layouts.app')

@section('title', '目標を理解する | Canovia')

@section('content')
    @php
        $confirmedFacts = collect(data_get($snapshot, 'confirmed_facts', []))
            ->reject(fn ($fact) => ($fact->key ?? null) === 'goal_title')
            ->values();
        $candidateFacts = collect(data_get($snapshot, 'candidate_hints', []))->values();
        $unknownFacts = collect(data_get($snapshot, 'known_unknowns', []))->values();

        $factText = function ($fact) {
            $value = $fact->value_json ?? null;
            if (is_array($value)) {
                return (string) ($value['text'] ?? $value['value'] ?? $value['stage'] ?? $value['kind'] ?? $value['mode'] ?? '');
            }
            return (string) $value;
        };

        $planButtonPrimary = $goalContext->readiness_score >= 40 || ! $question || $presentationMode === 'compact';
        $textFirst = $presentationMode === 'conversational' || empty($question['options'] ?? []);
    @endphp

    <div class="mx-auto max-w-6xl space-y-5 pb-28 md:pb-0">
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">回答を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <header class="page-card border-cyan-300/20 p-5 sm:p-6" data-goal-discovery-preview>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">GOAL CONTEXT</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">{{ $goalContext->desired_state }}</h1>
                    <p class="mt-2 text-xs leading-5 text-slate-500">回答するたび、現在地と仮Planの前提が更新されます。</p>
                </div>
                <div class="shrink-0 text-right">
                    <span class="badge {{ $goalContext->readiness_state === 'high' ? 'badge-green' : ($goalContext->readiness_state === 'medium' ? 'badge-amber' : 'badge-slate') }}">
                        現在地の把握 {{ $goalContext->readiness_score }}%
                    </span>
                    <p class="mt-2 text-[10px] font-bold uppercase tracking-[.12em] text-slate-600">{{ strtoupper($goalContext->readiness_state) }}</p>
                </div>
            </div>

            <div class="mt-5 h-2 overflow-hidden rounded-full bg-slate-900">
                <div class="h-full rounded-full bg-cyan-300/70 transition-all" style="width: {{ max(4, min(100, (int) $goalContext->readiness_score)) }}%"></div>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-3">
                <div class="rounded-2xl border border-emerald-300/10 bg-emerald-300/[0.025] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-emerald-300">KNOWN</p>
                    @if ($confirmedFacts->isEmpty())
                        <p class="mt-2 text-xs leading-5 text-slate-500">目標以外はまだ確認中です。</p>
                    @else
                        <div class="mt-2 space-y-2">
                            @foreach ($confirmedFacts->take(4) as $fact)
                                <div>
                                    <p class="text-[10px] font-bold text-slate-500">{{ $fact->label }}</p>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-300">{{ $factText($fact) ?: '確認済み' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-amber-300/10 bg-amber-300/[0.02] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-amber-300">KNOWN UNKNOWN</p>
                    @if ($unknownFacts->isEmpty())
                        <p class="mt-2 text-xs leading-5 text-slate-500">今のところ明示されたUnknownはありません。</p>
                    @else
                        <div class="mt-2 space-y-1">
                            @foreach ($unknownFacts->take(4) as $fact)
                                <p class="text-xs leading-5 text-slate-300">? {{ $fact->label }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl border border-violet-300/10 bg-violet-300/[0.02] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-violet-300">AI HINT</p>
                    @if ($candidateFacts->isEmpty())
                        <p class="mt-2 text-xs leading-5 text-slate-500">AIの未確認Hintはまだありません。HintはFactとして確定されません。</p>
                    @else
                        <div class="mt-2 space-y-1">
                            @foreach ($candidateFacts->take(3) as $fact)
                                <p class="text-xs leading-5 text-slate-300">{{ $fact->label }} · 未確認</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </header>

        @if ($presentationMode === 'compact')
            <section class="page-card border-cyan-300/20 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">ENOUGH TO START</p>
                <h2 class="mt-1 text-lg font-black text-slate-100">質問は一旦ここまででも大丈夫</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">分からない部分は仮Plan側でMeasurement Taskにできます。あとからInboxやEvidenceで現在地を更新できます。</p>
                <form method="POST" action="{{ route('plans.store') }}" class="mt-4" data-mutation-once>
                    @csrf
                    <input type="hidden" name="goal_context_id" value="{{ $goalContext->id }}">
                    <input type="hidden" name="title" value="{{ $goalContext->desired_state }}">
                    <input type="hidden" name="create_request_id" value="{{ (string) IlluminateSupportStr::uuid() }}">
                    <button type="submit" class="btn-primary">この情報で仮Planへ進む</button>
                </form>
            </section>
        @endif

        @if ($question)
            <section class="page-card p-5 sm:p-6" data-goal-discovery-question>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">NEXT QUESTION</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">{{ $question['title'] }}</h2>
                        <p class="mt-2 text-xs leading-5 text-slate-400">{{ $question['copy'] }}</p>
                    </div>
                    <span class="badge badge-slate">1問ずつ</span>
                </div>

                @if ($textFirst)
                    <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" class="mt-5" data-mutation-once>
                        @csrf
                        <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                        <input type="hidden" name="answer_mode" value="text">
                        <textarea name="answer_text" rows="3" maxlength="2000" class="form-control min-h-[7rem]" placeholder="{{ $question['placeholder'] }}">{{ old('answer_text') }}</textarea>
                        <button type="submit" class="btn-primary mt-3">これで更新</button>
                    </form>
                @endif

                @if (! empty($question['options']))
                    <div class="{{ $textFirst ? 'mt-5 border-t border-white/6 pt-4' : 'mt-5' }}">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-slate-600">{{ $textFirst ? 'タップで答えるなら' : '近いものをタップ' }}</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($question['options'] as $option)
                                <form method="POST" action="{{ route('goal_discovery.answer', $goalContext) }}" data-mutation-once>
                                    @csrf
                                    <input type="hidden" name="question_id" value="{{ $question['id'] }}">
                                    <input type="hidden" name="answer_mode" value="quick">
                                    <input type="hidden" name="choice" value="{{ $option['value'] }}">
                                    <button type="submit" class="{{ $presentationMode === 'quick' && ! $textFirst ? 'btn-primary' : 'btn-secondary' }} w-full px-4 py-3 text-left text-xs">{{ $option['label'] }}</button>
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
                            <button type="submit" class="btn-primary mt-3">これで更新</button>
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
        @else
            <section class="page-card border-emerald-300/15 p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">READY</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">最初の仮Planを作れる状態です</h2>
                <p class="mt-2 text-xs leading-5 text-slate-400">この後もEvidenceが増えればCurrent Stateは更新できます。今ここで全部を確定する必要はありません。</p>
            </section>
        @endif

        @if ($presentationMode !== 'compact')
            <section class="page-card p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">PROVISIONAL PLAN</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">この情報で仮Planを見る</h2>
                        <p class="mt-1 text-xs leading-5 text-slate-500">現在地が不足していれば、Canoviaは「まず測る・観察するTask」を優先します。</p>
                    </div>
                    <form method="POST" action="{{ route('plans.store') }}" data-mutation-once>
                        @csrf
                        <input type="hidden" name="goal_context_id" value="{{ $goalContext->id }}">
                        <input type="hidden" name="title" value="{{ $goalContext->desired_state }}">
                        <input type="hidden" name="create_request_id" value="{{ (string) IlluminateSupportStr::uuid() }}">
                        <button type="submit" class="{{ $planButtonPrimary ? 'btn-primary' : 'btn-secondary' }}">仮Planへ進む</button>
                    </form>
                </div>
            </section>
        @endif

        <div class="flex flex-wrap justify-between gap-2 px-1">
            <a href="{{ route('plans.create') }}" class="text-xs font-semibold text-slate-500">← 別の目標を入力</a>
            <a href="{{ route('plans.create.manual') }}" class="text-xs font-semibold text-slate-500">詳細フォームで作る</a>
        </div>
    </div>
@endsection
