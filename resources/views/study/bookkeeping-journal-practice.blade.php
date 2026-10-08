@extends('layouts.app')

@section('title', '簿記の仕訳演習 | Canovia')

@section('content')
<div class="mx-auto max-w-5xl space-y-5" data-bookkeeping-journal-practice>
    <header class="page-card p-5 sm:p-6">
        <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'preparation']) }}" class="text-xs font-bold text-amber-300">← Study 学習準備</a>
        <p class="mt-4 text-[10px] font-black uppercase tracking-[.16em] text-amber-300">BOOKKEEPING / STRUCTURED JOURNAL</p>
        <h1 class="mt-2 text-2xl font-black text-slate-50">借方・貸方を入力して仕訳を解く</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
            勘定科目と金額を自分で選び、借方・貸方を組み合わせて回答します。複合仕訳も対象です。
            行の順序や、同じ勘定科目を複数行に分けた表現は、合計が同じなら正解として扱います。
        </p>
        <p class="mt-2 text-xs text-slate-500">Canoviaオリジナルの4問です。日商簿記の公式試験ではなく、資格の合否や級全体の習熟を示すものではありません。</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <a class="btn-secondary min-h-11 inline-flex items-center px-4 text-xs" href="{{ route('plans.bookkeeping_placement.show', $plan) }}">12問の基礎診断へ</a>
            <a class="btn-secondary min-h-11 inline-flex items-center px-4 text-xs" href="{{ route('plans.show', $plan) }}">PlanのTaskを確認</a>
        </div>
    </header>

    @if ($latest && $result)
        <section class="page-card p-5 sm:p-6" data-bookkeeping-journal-result>
            <p class="text-[10px] font-black uppercase tracking-[.13em] text-amber-300">LATEST RECORDED RESULT</p>
            <h2 class="mt-2 text-xl font-black text-slate-100">
                {{ (int) $result['correct_count'] }} / {{ (int) $result['total_count'] }}問正解
                <span class="ml-2 text-sm font-bold text-slate-400">{{ $latest->displayValue() }}</span>
            </h2>
            <p class="mt-2 text-xs leading-5 text-slate-500">{{ $latest->observed_at?->format('Y/m/d H:i') }} · 今回の演習結果のみの正答率</p>

            @if (! empty($result['next_actions']))
                <div class="mt-4 rounded-2xl border border-amber-300/20 p-4" data-bookkeeping-journal-next-actions>
                    <h3 class="text-xs font-black text-amber-200">次に試すこと</h3>
                    <ul class="mt-2 list-inside list-disc space-y-2 text-xs leading-5 text-slate-300">
                        @foreach ($result['next_actions'] as $next)
                            <li>{{ $next }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <details class="mt-4 rounded-2xl border border-white/10 p-4" open>
                <summary class="cursor-pointer text-sm font-black text-slate-100">問題ごとの採点・正解例</summary>
                <div class="mt-4 space-y-4">
                    @foreach ($result['details'] as $detail)
                        <article class="rounded-xl border border-white/10 bg-slate-950/20 p-4" data-bookkeeping-journal-feedback="{{ $detail['id'] }}">
                            <p class="text-xs font-black {{ $detail['correct'] ? 'text-emerald-300' : 'text-amber-300' }}">
                                {{ $detail['id'] }} · {{ $detail['correct'] ? '正解' : '要確認' }}
                            </p>
                            <p class="mt-2 text-xs leading-5 text-slate-100">{{ $detail['prompt'] }}</p>
                            @unless ($detail['correct'])
                                <p class="mt-2 text-xs font-bold text-amber-200" data-bookkeeping-journal-error="{{ $detail['error_type'] }}">
                                    {{ $practice->errorLabel($detail['error_type']) }}
                                </p>
                            @endunless
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                <div class="rounded-lg border border-white/8 p-3">
                                    <p class="mb-2 text-[11px] font-bold text-slate-400">あなたの回答</p>
                                    @foreach ($detail['entered'] as $line)
                                        <p class="text-xs leading-6 text-slate-300">
                                            {{ $line['side'] === 'debit' ? '借方' : '貸方' }}：
                                            {{ $line['account'] }} {{ number_format($line['amount']) }}円
                                        </p>
                                    @endforeach
                                </div>
                                <div class="rounded-lg border border-emerald-300/20 p-3">
                                    <p class="mb-2 text-[11px] font-bold text-emerald-300">正解例</p>
                                    @foreach ($detail['expected'] as $line)
                                        <p class="text-xs leading-6 text-slate-300">
                                            {{ $line['side'] === 'debit' ? '借方' : '貸方' }}：
                                            {{ $line['account'] }} {{ number_format($line['amount']) }}円
                                        </p>
                                    @endforeach
                                </div>
                            </div>
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $detail['explanation'] }}</p>
                        </article>
                    @endforeach
                </div>
            </details>
            <p class="mt-4 text-xs leading-5 text-slate-500">{{ $result['disclaimer'] }}</p>
            <p class="mt-2 text-xs leading-5 text-slate-500">TaskやPlanの進捗は自動変更しません。復習する範囲は自分で調整できます。</p>
        </section>
    @endif

    @if ($canEdit)
        <section class="page-card p-5 sm:p-6" data-bookkeeping-journal-form>
            <h2 class="text-lg font-black text-slate-100">仕訳4問に挑戦する</h2>
            <p class="mt-2 text-xs leading-5 text-slate-400">
                各問題について借方・貸方、勘定科目、金額（円）を入力してください。使わない行は3項目とも空欄にします。
                すべての問題に回答した後に、まとめて採点します。
            </p>
            <form method="POST" action="{{ route('plans.bookkeeping_journal.store', $plan) }}" class="mt-5 space-y-6">
                @csrf
                <input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
                @error('request_id') <p class="text-xs text-rose-300">{{ $message }}</p> @enderror
                @error('answers') <p class="text-xs text-rose-300">{{ $message }}</p> @enderror
                @foreach ($questions as $question)
                    @php $questionNumber = $loop->iteration; @endphp
                    <fieldset class="rounded-2xl border border-white/10 bg-slate-950/20 p-4" data-bookkeeping-journal-question="{{ $question['id'] }}">
                        <legend class="px-1 text-sm font-black text-slate-100">{{ $loop->iteration }}. {{ $question['prompt'] }}</legend>
                        <p class="mt-1 text-[11px] text-slate-500">{{ $topics[$question['topic']] ?? '仕訳' }} · 最大4行</p>
                        @error('answers.'.$question['id']) <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
                        <div class="mt-3 space-y-2">
                            @for ($row = 0; $row < 4; $row++)
                                <div class="grid grid-cols-1 gap-2 rounded-xl border border-white/8 p-2 sm:grid-cols-[minmax(85px,1fr)_minmax(120px,2fr)_minmax(95px,1fr)]">
                                    <label class="text-[11px] text-slate-500">
                                        借方／貸方
                                        <select
                                            class="form-control mt-1 w-full text-xs"
                                            name="answers[{{ $question['id'] }}][{{ $row }}][side]"
                                            aria-label="問題{{ $questionNumber }}・{{ $row + 1 }}行目の借方貸方"
                                        >
                                            <option value="">未使用</option>
                                            <option value="debit" @selected(old('answers.'.$question['id'].'.'.$row.'.side') === 'debit')>借方</option>
                                            <option value="credit" @selected(old('answers.'.$question['id'].'.'.$row.'.side') === 'credit')>貸方</option>
                                        </select>
                                    </label>
                                    <label class="text-[11px] text-slate-500">
                                        勘定科目
                                        <select
                                            class="form-control mt-1 w-full text-xs"
                                            name="answers[{{ $question['id'] }}][{{ $row }}][account]"
                                            aria-label="問題{{ $questionNumber }}・{{ $row + 1 }}行目の勘定科目"
                                        >
                                            <option value="">選択なし</option>
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account }}" @selected(old('answers.'.$question['id'].'.'.$row.'.account') === $account)>{{ $account }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="text-[11px] text-slate-500">
                                        金額（円）
                                        <input
                                            class="form-control mt-1 w-full text-xs"
                                            type="number"
                                            min="1"
                                            max="999999999"
                                            step="1"
                                            inputmode="numeric"
                                            name="answers[{{ $question['id'] }}][{{ $row }}][amount]"
                                            value="{{ old('answers.'.$question['id'].'.'.$row.'.amount') }}"
                                            aria-label="問題{{ $questionNumber }}・{{ $row + 1 }}行目の金額"
                                        >
                                    </label>
                                </div>
                            @endfor
                        </div>
                    </fieldset>
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-primary min-h-11 px-4" data-bookkeeping-journal-submit>採点して記録する</button>
                    <a href="{{ route('plans.bookkeeping_placement.show', $plan) }}" class="btn-secondary min-h-11 px-4">基礎診断へ戻る</a>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
