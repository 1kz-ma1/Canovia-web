@extends('layouts.app')

@section('title', '簿記の現在地診断 | Canovia')

@section('content')
<div class="mx-auto max-w-5xl space-y-5" data-bookkeeping-placement>
    <header class="page-card p-5 sm:p-6">
        <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'preparation']) }}" class="text-xs font-bold text-amber-300">← 学習準備へ戻る</a>
        <p class="mt-4 text-[10px] font-black uppercase tracking-[.16em] text-amber-300">STUDY STARTING POINT</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">簿記の現在地を確認する</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
            3級を最初からやり直すか、2級へ進めるかを固定しません。まず3級の基礎4分野を短く確認し、復習すべき単元と先取りできそうな範囲を考えます。
        </p>
        <p class="mt-3 text-xs leading-5 text-slate-500">
            この12問はCanoviaオリジナルの簡易診断で、日商簿記の公式試験ではありません。結果だけで合格や級全体の習熟を判定しません。
        </p>
    </header>

    <section class="page-card p-4">
        <a href="{{ route('plans.bookkeeping_journal.show', $plan) }}" class="btn-secondary min-h-11 inline-flex items-center px-4 text-xs" data-bookkeeping-placement-journal-link>4択の次は、借方・貸方を直接入力してみる →</a>
    </section>

    @if ($latest)
        <section class="page-card p-5 sm:p-6" data-bookkeeping-placement-history>
            <p class="text-[10px] font-black uppercase tracking-[.14em] text-amber-300">LATEST RECORDED DIAGNOSIS</p>
            <h2 class="mt-2 text-lg font-black text-slate-100">前回の確認結果：{{ $latest->displayValue() }}</h2>
            <p class="mt-1 text-xs text-slate-500">{{ $latest->observed_at?->format('Y/m/d H:i') }} · 保存済みの診断結果</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($topics as $key => $label)
                    <div class="rounded-xl border border-white/10 p-3">
                        <p class="text-xs font-bold text-slate-300">{{ $label }}</p>
                        <p class="mt-1 text-lg font-black text-slate-100">{{ (int) data_get($latest->components, $key, 0) }}%</p>
                    </div>
                @endforeach
            </div>
            @if ($latestPlacement)
                <p class="mt-4 text-sm leading-6 text-slate-300" data-bookkeeping-placement-suggestion>{{ $latestPlacement['message'] }}</p>
                @if (! empty($latestPlacement['next_actions']))
                    <div class="mt-3" data-bookkeeping-placement-next-actions>
                        <p class="text-xs font-bold text-amber-200">次の行動の候補</p>
                        <ul class="mt-2 list-inside list-disc space-y-1 text-xs leading-5 text-slate-300">
                            @foreach ($latestPlacement['next_actions'] as $action)
                                <li>{{ $action }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
            <p class="mt-2 text-xs text-slate-500">前回と異なる問題でも安定して解けるか確認する必要があります。2級へ進むことを制限する判定ではありません。</p>
        </section>
    @endif

    @if ($result)
        <section class="page-card p-5 sm:p-6" data-bookkeeping-placement-result>
            <h2 class="text-lg font-black text-slate-50">今回の診断：{{ (int) $result['correct_count'] }} / {{ (int) $result['total_count'] }}問</h2>
            <p class="mt-3 text-sm leading-6 text-slate-300">{{ $result['message'] }}</p>
            @if (! empty($result['next_actions']))
                <div class="mt-3" data-bookkeeping-placement-current-actions>
                    <p class="text-xs font-black text-amber-200">次に試したいこと</p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs leading-5 text-slate-300">
                        @foreach ($result['next_actions'] as $action)
                            <li>{{ $action }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary mt-3 inline-flex min-h-11 items-center px-4 text-xs">PlanのTaskを確認・調整する →</a>
                </div>
            @endif
            @if (! empty($result['weak_topics']))
                <p class="mt-2 text-xs font-bold text-amber-200">
                    復習候補：{{ collect($result['weak_topics'])->map(fn ($key) => $topics[$key] ?? $key)->implode('・') }}
                </p>
            @endif
            <details class="mt-4 rounded-xl border border-white/10 p-4">
                <summary class="cursor-pointer text-sm font-black text-slate-100">問題ごとの解説を見る</summary>
                <div class="mt-3 space-y-2">
                    @foreach ($result['feedback'] as $feedback)
                        <div class="rounded-lg border border-white/8 p-3">
                            <p class="text-xs font-bold {{ $feedback['correct'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ $feedback['id'] }} · {{ $feedback['correct'] ? '正解' : '要確認' }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-300">正解：{{ $feedback['correct_choice'] }}</p>
                            @if (! $feedback['correct'] && ! empty($feedback['submitted_choice']))
                                <p class="mt-1 text-xs leading-5 text-slate-500">回答：{{ $feedback['submitted_choice'] }}</p>
                            @endif
                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ $feedback['explanation'] }}</p>
                        </div>
                    @endforeach
                </div>
            </details>
            <p class="mt-3 text-xs text-slate-500">{{ $result['disclaimer'] }}</p>
        </section>
    @endif

    @if ($canEdit)
        <section class="page-card p-5 sm:p-6" data-bookkeeping-placement-form>
            <h2 class="text-lg font-black text-slate-100">基礎12問を解く</h2>
            <p class="mt-2 text-xs leading-5 text-slate-400">授業の復習と2級の先取りは両立できます。最初に希望を選び、問題に回答してください。自信がない項目も回答し、苦手分野の目安をつかみます。</p>
            <form method="POST" action="{{ route('plans.bookkeeping_placement.store', $plan) }}" class="mt-5 space-y-5">
                @csrf
                <input type="hidden" name="request_id" value="{{ $requestId }}">
                <fieldset class="rounded-xl border border-white/10 p-4">
                    <legend class="px-1 text-sm font-black text-slate-100">今回の希望</legend>
                    <label class="mt-2 flex gap-2 text-xs text-slate-300"><input type="radio" name="wants_advance" value="0" @checked(old('wants_advance', '0') === '0') required>まず3級の復習・定着を優先したい</label>
                    <label class="mt-2 flex gap-2 text-xs text-slate-300"><input type="radio" name="wants_advance" value="1" @checked(old('wants_advance') === '1') required>3級の復習に加えて2級も先取りしたい</label>
                </fieldset>
                @error('request_id')<p class="text-xs text-rose-300">{{ $message }}</p>@enderror
                @error('answers')<p class="text-xs text-rose-300">{{ $message }}</p>@enderror
                @foreach ($questions as $question)
                    <fieldset class="rounded-2xl border border-white/10 bg-slate-950/20 p-4">
                        <legend class="px-1 text-sm font-black text-slate-100">
                            {{ $loop->iteration }}. {{ $question['prompt'] }}
                        </legend>
                        <p class="mt-1 text-[11px] text-slate-500">{{ $topics[$question['topic']] }}</p>
                        @error('answers.'.$question['id'])<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($question['choices'] as $value => $label)
                                <label class="flex cursor-pointer gap-2 rounded-xl border border-white/8 p-3 text-xs leading-5 text-slate-300">
                                    <input type="radio" name="answers[{{ $question['id'] }}]" value="{{ $value }}" @checked(old('answers.'.$question['id']) === $value) required>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-primary min-h-11 px-4" data-bookkeeping-placement-submit>採点して現在地を確認</button>
                    <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'preparation']) }}" class="btn-secondary min-h-11 px-4">学習準備へ戻る</a>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
