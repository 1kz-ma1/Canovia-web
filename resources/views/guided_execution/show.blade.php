@extends('layouts.app')

@section('title', '実行を一緒に進める | Canovia')

@section('content')
    @php
        $ratingLabels = [
            'as_expected' => '想定どおり進んだ',
            'partly' => '一部うまくいった',
            'not_as_expected' => '想定どおりではなかった',
            'learning_only' => '結果より学びがあった',
        ];
        $defaultIntent = old('intent', $activeExecution?->intent ?: ($task->next_action_note ?: $task->title));
        $defaultFocus = old('focus_points', $activeExecution ? implode("\n", $activeExecution->focus_points ?? []) : '');
        $defaultObservation = old('observation_points', $activeExecution ? implode("\n", $activeExecution->observation_points ?? []) : '');
        $defaultSignal = old('success_signal', $activeExecution?->success_signal ?: '');
    @endphp

    <div class="mx-auto max-w-5xl space-y-5 pb-28 md:pb-0">
        <header class="page-card border-cyan-300/20 p-5 sm:p-6 plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">GUIDED EXECUTION</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50">{{ $task->title }}</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
                    <p class="mt-3 text-xs leading-5 text-slate-500">
                        Canoviaの外で実行するTaskです。時間は測らず、始める前に今回の方針を決め、終わったら起きたことをEvidenceとして残します。
                    </p>
                </div>
                <span class="badge {{ $assessment['recommended'] ? 'badge-green' : 'badge-slate' }}">
                    {{ $assessment['recommended'] ? 'この進め方がおすすめ' : '任意で利用' }}
                </span>
            </div>

            <div class="mt-4 rounded-xl border border-white/8 bg-white/[0.025] p-3">
                <p class="text-[11px] leading-5 text-slate-400">{{ $assessment['reason'] }}</p>
            </div>
        </header>

        @if (session('success'))
            <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">入力内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($activeExecution)
            <section class="page-card border-emerald-300/15 p-5 sm:p-6" data-guided-execution-active>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">BEFORE ACTION · READY</p>
                        <h2 class="mt-1 text-xl font-black text-slate-100">今回見るポイントは決まりました</h2>
                    </div>
                    <span class="badge badge-green">現実で実行</span>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    <article class="rounded-2xl border border-cyan-300/10 bg-cyan-300/[0.025] p-4">
                        <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">ACTION INTENT</p>
                        <p class="mt-2 whitespace-pre-line text-sm font-bold leading-6 text-slate-200">{{ $activeExecution->intent }}</p>
                        @if ($activeExecution->success_signal)
                            <p class="mt-3 text-[10px] font-black uppercase tracking-[.12em] text-slate-500">SUCCESS / LEARNING SIGNAL</p>
                            <p class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-400">{{ $activeExecution->success_signal }}</p>
                        @endif
                    </article>

                    <article class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                        @if (! empty($activeExecution->focus_points))
                            <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">FOCUS</p>
                            <ul class="mt-2 space-y-1 text-xs leading-5 text-slate-300">
                                @foreach ($activeExecution->focus_points as $point)
                                    <li>• {{ $point }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (! empty($activeExecution->observation_points))
                            <p class="{{ ! empty($activeExecution->focus_points) ? 'mt-4' : '' }} text-[10px] font-black uppercase tracking-[.12em] text-amber-300">OBSERVE</p>
                            <ul class="mt-2 space-y-1 text-xs leading-5 text-slate-300">
                                @foreach ($activeExecution->observation_points as $point)
                                    <li>? {{ $point }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (empty($activeExecution->focus_points) && empty($activeExecution->observation_points))
                            <p class="text-xs leading-5 text-slate-500">今回は目的だけ決めています。実行中に気づいたことを、戻ってからそのまま教えてください。</p>
                        @endif
                    </article>
                </div>

                <div class="mt-5 rounded-2xl border border-violet-300/15 bg-violet-300/[0.025] p-4 sm:p-5">
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-violet-300">REAL WORLD</p>
                    <h3 class="mt-1 text-lg font-black text-slate-100">ここからはCanoviaを閉じても大丈夫</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-400">Timerを回す必要はありません。商談・練習・面接・運動などをそのまま実行して、終わったらこの画面へ戻ってきてください。</p>
                </div>

                <div class="mt-6 border-t border-white/8 pt-5" data-guided-reflection>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">AFTER ACTION</p>
                    <h3 class="mt-1 text-xl font-black text-slate-100">どうだった？</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-500">振り返りを確定した時点で初めてTask Evidenceになります。自己申告だけでTask進捗は自動変更しません。</p>

                    <form method="POST" action="{{ route('plans.tasks.guided_execution.reflect', [$plan, $task, $activeExecution]) }}" class="mt-5 space-y-4" data-mutation-once>
                        @csrf
                        <input type="hidden" name="reflection_request_id" value="{{ $reflectionRequestId }}">

                        <fieldset>
                            <legend class="text-xs font-bold text-slate-300">結果に一番近いもの</legend>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($ratingLabels as $value => $label)
                                    <label class="cursor-pointer rounded-xl border border-white/8 bg-white/[0.025] p-3 text-xs text-slate-300 has-[:checked]:border-cyan-300/40 has-[:checked]:bg-cyan-300/[0.06]">
                                        <input type="radio" name="outcome_rating" value="{{ $value }}" class="mr-2" required @checked(old('outcome_rating') === $value)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <div>
                            <label class="text-xs font-bold text-slate-300">実際に何が起きた？</label>
                            <textarea name="actual_outcome" rows="3" maxlength="4000" required class="form-control mt-2 min-h-[7rem]" placeholder="例：見積提示まで進んだが、購入時期は来月だった">{{ old('actual_outcome') }}</textarea>
                        </div>

                        <div class="grid gap-4 md:grid-cols-3">
                            <div>
                                <label class="text-xs font-bold text-slate-300">観測できたこと</label>
                                <textarea name="observations" rows="4" maxlength="4000" class="form-control mt-2" placeholder="事実・相手の反応・数値など">{{ old('observations') }}</textarea>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-300">気づいたこと</label>
                                <textarea name="discoveries" rows="4" maxlength="4000" class="form-control mt-2" placeholder="想定との違い、学びなど">{{ old('discoveries') }}</textarea>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-300">次に変えること</label>
                                <textarea name="next_adjustment" rows="4" maxlength="4000" class="form-control mt-2" placeholder="次回試すことがあれば">{{ old('next_adjustment') }}</textarea>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary">振り返りをEvidenceとして残す</button>
                    </form>
                </div>

                <details class="mt-5 rounded-xl border border-white/8 bg-slate-950/20 p-3">
                    <summary class="cursor-pointer text-xs font-bold text-slate-400">実行前の方針を変更する</summary>
                    <form method="POST" action="{{ route('plans.tasks.guided_execution.prepare', [$plan, $task]) }}" class="mt-4 space-y-3" data-mutation-once>
                        @csrf
                        <input type="hidden" name="prepare_request_id" value="{{ $prepareRequestId }}">
                        <input type="hidden" name="source" value="guided_execution_edit">
                        <div>
                            <label class="text-xs font-bold text-slate-300">今回やること</label>
                            <textarea name="intent" rows="2" required maxlength="1000" class="form-control mt-2">{{ $defaultIntent }}</textarea>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <textarea name="focus_points" rows="3" maxlength="4000" class="form-control" placeholder="意識することを1行ずつ">{{ $defaultFocus }}</textarea>
                            <textarea name="observation_points" rows="3" maxlength="4000" class="form-control" placeholder="終わった後に確認したいことを1行ずつ">{{ $defaultObservation }}</textarea>
                        </div>
                        <textarea name="success_signal" rows="2" maxlength="1000" class="form-control" placeholder="今回うまくいった / 学べたと判断するSignal">{{ $defaultSignal }}</textarea>
                        <button type="submit" class="btn-secondary">方針を更新</button>
                    </form>

                    <form method="POST" action="{{ route('plans.tasks.guided_execution.cancel', [$plan, $task, $activeExecution]) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="text-xs font-semibold text-slate-600 underline underline-offset-4">今回の実行方針を取り消す</button>
                    </form>
                </details>
            </section>
        @else
            <section class="page-card p-5 sm:p-6" data-guided-execution-prepare>
                <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">BEFORE ACTION</p>
                <h2 class="mt-1 text-xl font-black text-slate-100">始める前に、今回だけ見るポイントを決める</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">全部埋める必要はありません。「今回やること」だけでも開始できます。意識点や観測点を決めると、戻ってきた時の振り返りがEvidenceとして使いやすくなります。</p>

                <form method="POST" action="{{ route('plans.tasks.guided_execution.prepare', [$plan, $task]) }}" class="mt-5 space-y-4" data-mutation-once>
                    @csrf
                    <input type="hidden" name="prepare_request_id" value="{{ $prepareRequestId }}">
                    <input type="hidden" name="source" value="guided_execution">

                    <div>
                        <label class="text-xs font-bold text-slate-300">今回やること</label>
                        <textarea name="intent" rows="3" required maxlength="1000" class="form-control mt-2 min-h-[7rem]">{{ $defaultIntent }}</textarea>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-xs font-bold text-slate-300">意識すること <span class="text-slate-600">任意・1行ずつ</span></label>
                            <textarea name="focus_points" rows="4" maxlength="4000" class="form-control mt-2" placeholder="例：見積前に購入時期を確認する">{{ $defaultFocus }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-300">終わったら確認したいこと <span class="text-slate-600">任意・1行ずつ</span></label>
                            <textarea name="observation_points" rows="4" maxlength="4000" class="form-control mt-2" placeholder="例：見積まで進んだか&#10;断られた理由は何だったか">{{ $defaultObservation }}</textarea>
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-bold text-slate-300">成功 / 学びのSignal <span class="text-slate-600">任意</span></label>
                        <textarea name="success_signal" rows="2" maxlength="1000" class="form-control mt-2" placeholder="例：クロージング成功だけでなく、失注理由が分かれば学びあり">{{ $defaultSignal }}</textarea>
                    </div>

                    <button type="submit" class="btn-primary">この方針で現実の実行へ</button>
                </form>
            </section>
        @endif

        @if ($latestCompleted)
            <section class="page-card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-emerald-300">LATEST EVIDENCE</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">前回の振り返り</h2>
                    </div>
                    @if ($latestCompleted->evidence)
                        <span class="badge badge-slate">Evidence {{ number_format((float) $latestCompleted->evidence->confidence, 2) }}</span>
                    @endif
                </div>
                <p class="mt-3 text-xs font-bold text-slate-300">{{ $ratingLabels[$latestCompleted->outcome_rating] ?? '振り返り済み' }}</p>
                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $latestCompleted->actual_outcome }}</p>

                @if ($latestCompleted->discoveries || $latestCompleted->next_adjustment)
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @if ($latestCompleted->discoveries)
                            <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                                <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">DISCOVERY</p>
                                <p class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-300">{{ $latestCompleted->discoveries }}</p>
                            </div>
                        @endif
                        @if ($latestCompleted->next_adjustment)
                            <div class="rounded-xl border border-cyan-300/10 bg-cyan-300/[0.025] p-3">
                                <p class="text-[10px] font-black uppercase tracking-[.12em] text-cyan-300">NEXT ADJUSTMENT</p>
                                <p class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-300">{{ $latestCompleted->next_adjustment }}</p>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        @endif

        <div class="flex flex-wrap justify-between gap-2 px-1">
            <a href="{{ route('plans.show', $plan) }}" class="text-xs font-semibold text-slate-500">← Planへ戻る</a>
            <a href="{{ route('home') }}" class="text-xs font-semibold text-slate-500">Homeへ</a>
        </div>
    </div>
@endsection
