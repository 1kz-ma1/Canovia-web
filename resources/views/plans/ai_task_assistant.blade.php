@extends('layouts.app')

@section('title', 'AIと初期計画をつくる | Canovia')

@section('content')
    <div
        class="initial-plan-page mx-auto max-w-5xl space-y-5 pb-32 sm:space-y-6 md:pb-10"
        data-funnel-root
        data-event-url="{{ route('behavior_events.store') }}"
        data-plan-id="{{ $plan->id }}"
    >
        <section class="page-card border-cyan-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">CANOVIA / PLAN BUILDER</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">AIと初期計画をつくる</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-400">
                        Canoviaが計画づくりに必要な情報をまとめます。普段使っているAIへ相談文を渡し、
                        最後の回答をCanoviaへ戻すだけで、Taskと進む順番を登録できます。
                    </p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">計画へ戻る</a>
            </div>

            <div class="mt-5 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-cyan-300">TARGET PLAN</p>
                <h2 class="mt-1 text-lg font-black text-slate-100">{{ $plan->title }}</h2>
                @if ($plan->description)
                    <p class="mt-2 line-clamp-3 text-xs leading-5 text-slate-400">{{ $plan->description }}</p>
                @endif
            </div>
        </section>

        <section class="page-card p-5 sm:p-6" data-goal-context-preview>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-300">GOAL CONTEXT</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">Canoviaが今わかっていること</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">この把握度はAIの自己評価ではなく、現在地・成功Signal・制約など確認済み情報のCoverageから計算しています。</p>
                </div>
                <span class="badge {{ $goalContext->readiness_state === 'high' ? 'badge-green' : ($goalContext->readiness_state === 'medium' ? 'badge-amber' : 'badge-slate') }}">
                    現在地の把握 {{ $goalContext->readiness_score }}%
                </span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-emerald-300/10 bg-emerald-300/[0.025] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-emerald-300">DESIRED STATE</p>
                    <p class="mt-2 text-sm font-bold text-slate-200">{{ $goalContext->desired_state }}</p>
                    @if ($goalContext->current_state_summary)
                        <p class="mt-3 text-[10px] font-black uppercase tracking-[.12em] text-slate-500">CURRENT STATE</p>
                        <p class="mt-1 text-xs leading-5 text-slate-400">{{ $goalContext->current_state_summary }}</p>
                    @else
                        <p class="mt-3 text-xs leading-5 text-slate-500">現在地はまだ十分に確認できていません。</p>
                    @endif
                </div>

                <div class="rounded-2xl border border-slate-700/70 bg-slate-950/25 p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.12em] text-slate-500">CONTEXT STATUS</p>
                    @php
                        $confirmedGoalFacts = collect(data_get($goalContextSnapshot, 'confirmed_facts', []));
                        $unknownGoalFacts = collect(data_get($goalContextSnapshot, 'known_unknowns', []));
                    @endphp
                    <p class="mt-2 text-xs text-slate-300">確認済み {{ $confirmedGoalFacts->count() }}件</p>
                    <p class="mt-1 text-xs text-slate-400">Known Unknown {{ $unknownGoalFacts->count() }}件</p>
                    <p class="mt-3 text-[11px] leading-5 text-slate-500">情報が少なくても仮Planは作れます。重要なUnknownは、AIが想像で埋めず「まず測るTask」に変えます。</p>
                </div>
            </div>
        </section>

        <section class="page-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-300">CANOVIA MEMORY</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">Canoviaが会話から覚えていること</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">
                        ユーザーが明示した希望・関心などだけを内部Memoryとして参照します。入力のために別画面で管理する必要はありません。
                    </p>
                </div>
            </div>

            @if (($futureMemos ?? collect())->isNotEmpty())
                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($futureMemos as $memo)
                        <div class="rounded-2xl border border-violet-300/10 bg-slate-950/30 p-3">
                            <p class="text-[10px] font-black tracking-[.12em] text-violet-300">{{ $memo->kindLabel() }} · {{ $memo->categoryLabel() }}</p>
                            <p class="mt-1 line-clamp-3 text-xs leading-5 text-slate-300">{{ $memo->content }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 rounded-2xl border border-dashed border-slate-700 bg-slate-950/20 p-4">
                    <p class="text-sm font-bold text-slate-200">追加のMemoryはまだありません</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">なくても計画作成は進められます。会話の中で明示された内容が必要に応じて蓄積されます。</p>
                </div>
            @endif
        </section>

        <section class="grid gap-5 xl:grid-cols-2">
            <div class="page-card p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-cyan-300/10 text-sm font-black text-cyan-200">1</span>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-cyan-300">SEND TO AI</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">AIへ相談文を渡す</h2>
                    </div>
                </div>

                <textarea id="aiPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $prompt }}</textarea>

                <div class="mt-4 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
                    <p class="text-sm font-semibold text-slate-100">相談に必要な内容はCanovia側で準備済みです</p>
                    <p class="mt-1 text-xs leading-5 text-slate-400">
                        原文を読む必要はありません。ChatGPT、Gemini、Claudeなど普段使っているAIへ、そのまま送ってください。
                        AIから質問された場合は、会話を続けて大丈夫です。
                    </p>

                    <button
                        type="button"
                        class="btn-primary mt-4"
                        data-copy-target="#aiPrompt"
                        data-onboarding-target="ai-copy"
                        data-funnel-event="plan_generation_prompt_copy_clicked"
                    >相談文をコピー</button>

                    <details class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                        <summary class="cursor-pointer text-xs font-semibold text-slate-300">この相談文に含まれる情報</summary>
                        <ul class="mt-3 space-y-2 text-xs leading-5 text-slate-500">
                            <li>・対象PlanのID・目標・説明・期限などの基本情報</li>
                            <li>・Goal Contextの確認済み事実・未確認Hint・Known Unknown</li>
                            <li>・AI共有がONになっている未来メモ</li>
                            <li>・Taskへ分解するときの時間・優先度・順序のルール</li>
                            <li>・Canoviaへ戻すJSON形式と対象Planを変えない安全条件</li>
                        </ul>
                    </details>
                </div>
            </div>

            <div class="page-card p-5 sm:p-6" data-onboarding-target="ai-import">
                <div class="flex items-center gap-3">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-violet-300/10 text-sm font-black text-violet-200">2</span>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-300">RETURN TO CANOVIA</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">AIの回答をCanoviaへ戻す</h2>
                    </div>
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-400">
                    AIの最後の回答をコピーしたら、下のボタンを押してください。
                    説明文やコードブロックが含まれていても、CanoviaがJSON部分を探して読み込みます。
                </p>

                <form
                    method="POST"
                    action="{{ route('plans.ai_task_assistant.import', $plan) }}"
                    class="mt-4"
                    data-ai-plan-generation-import
                >
                    @csrf
                    @if (request()->boolean('return_to_workspace'))
                        <input type="hidden" name="return_to_workspace" value="1">
                    @endif

                    <button
                        type="button"
                        class="btn-primary"
                        data-paste-target="#tasks_json"
                        data-paste-submit="1"
                        data-paste-fallback="#initialPlanJsonManualInput"
                        data-paste-status="#initialPlanJsonPasteStatus"
                    >クリップボードから貼り付けて読み込む</button>

                    <p id="initialPlanJsonPasteStatus" class="mt-2 hidden text-xs leading-5 text-slate-400" aria-live="polite"></p>

                    <details
                        id="initialPlanJsonManualInput"
                        class="ai-handoff-details mt-4 rounded-xl border border-slate-800 bg-slate-950/35 p-3"
                        @if($errors->has('tasks_json') || old('tasks_json')) open @endif
                    >
                        <summary class="cursor-pointer text-xs font-semibold text-slate-300">手動で貼り付ける / JSONを確認する</summary>
                        <textarea
                            id="tasks_json"
                            name="tasks_json"
                            class="form-control mt-3 min-h-[180px] font-mono text-xs"
                            placeholder="AIの最後の回答をここへ貼り付け"
                            data-ai-json-input
                            required
                        >{{ old('tasks_json') }}</textarea>
                        <button type="submit" class="btn-secondary mt-3" data-ai-import-submit>この回答を読み込む</button>
                    </details>

                    @if ($errors->has('tasks_json'))
                        @php
                            $jsonErrorMessage = $errors->first('tasks_json');
                            $jsonRepairPrompt = implode("\n", [
                                'Canoviaの初期計画JSONでエラーが発生しました。',
                                '下の「元のCanoviaプロンプト」を仕様と対象計画の唯一の正として扱ってください。',
                                'エラー解消に必要な箇所だけ修正し、タスク内容・時間・順序など正しい情報はできるだけ保持してください。',
                                'target_plan.idは '.$plan->id.' のままにし、別の計画IDを使わないでください。',
                                'priorityとactivation_costは必ず1〜5です。タスクの実行順はpriorityを6以上にせず、reorder_tasksで表現してください。',
                                '修正後はJSONとして構文解析できることと、Canoviaのエラー内容が解消されていることを確認してください。',
                                '説明文・Markdown・コードフェンスを付けず、有効なJSONだけを返してください。',
                                '',
                                '【Canoviaのエラー】',
                                $jsonErrorMessage,
                                '',
                                '【エラーになったJSON】',
                                old('tasks_json', ''),
                                '',
                                '【元のCanoviaプロンプト】',
                                $prompt,
                            ]);
                        @endphp

                        <div class="mt-4 rounded-2xl border border-rose-300/20 bg-rose-300/[0.04] p-4">
                            <p class="text-sm font-black text-rose-100">JSONを確認できませんでした</p>
                            <p class="mt-1 text-xs leading-5 text-slate-400">
                                入力内容は残しています。修正依頼をコピーして、JSONを作ったAIへそのまま送ってください。
                            </p>
                            <div class="mt-3 rounded-xl border border-rose-300/15 bg-slate-950/30 p-3">
                                <p class="text-[11px] font-bold text-rose-200">Canoviaが検出した内容</p>
                                <p class="mt-1 text-xs leading-5 text-slate-300">{{ $jsonErrorMessage }}</p>
                            </div>

                            <textarea id="initialPlanJsonRepairPrompt" readonly tabindex="-1" aria-hidden="true" class="sr-only">{{ $jsonRepairPrompt }}</textarea>
                            <button type="button" class="btn-primary mt-3" data-copy-target="#initialPlanJsonRepairPrompt">修正依頼をコピー</button>

                            <details class="ai-handoff-details mt-3 rounded-xl border border-rose-300/10 bg-slate-950/25 p-3">
                                <summary class="cursor-pointer text-xs font-semibold text-rose-100/80">修正依頼に含まれる情報</summary>
                                <p class="mt-2 text-xs leading-5 text-slate-500">
                                    Canoviaのエラー内容、返されたJSON、元の相談文、正しいPlan IDと出力条件を含みます。
                                </p>
                            </details>
                        </div>
                    @endif
                </form>

                <div class="mt-4 rounded-xl border border-emerald-300/15 bg-emerald-300/[0.035] p-3">
                    <p class="text-xs font-bold text-emerald-200">Canovia側で内容を確認してから登録します</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        軽微な形式差はCanoviaが補正し、別Planへの回答など安全に判断できない内容だけ処理を止めます。
                    </p>
                </div>
            </div>
        </section>
    </div>

    {{-- Standard copy/paste behavior is handled centrally in resources/js/app.js. --}}
@endsection
